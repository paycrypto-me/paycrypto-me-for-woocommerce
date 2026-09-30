<?php
use PHPUnit\Framework\TestCase;
use PayCryptoMe\WooCommerce\PaymentOrderValidator;
use PayCryptoMe\WooCommerce\PayCryptoMeException;

// WC_Order/WC_Payment_Gateway/wc_price fallbacks live in tests/_support/wp-helpers.php
// (loaded by bootstrap.php before any test file).

class PaymentOrderValidatorTest extends TestCase
{
    private function make_order(array $overrides = []): \WC_Order
    {
        return new class ($overrides) extends WC_Order {
            private array $overrides;
            public function __construct(array $overrides)
            {
                $this->overrides = $overrides;
            }
            public function get_id()
            {
                return $this->overrides['id'] ?? 123;
            }
            public function needs_payment()
            {
                return $this->overrides['needs_payment'] ?? true;
            }
            public function get_payment_method($context = 'view')
            {
                return $this->overrides['payment_method'] ?? 'paycrypto_me';
            }
            public function get_currency($context = 'view')
            {
                return $this->overrides['currency'] ?? 'USD';
            }
        };
    }

    private function make_gateway(bool $available = true): \WC_Payment_Gateway
    {
        return new class ($available) extends WC_Payment_Gateway {
            public $id = 'paycrypto_me';
            private bool $available;
            public function __construct(bool $available)
            {
                $this->available = $available;
            }
            public function is_available()
            {
                return $this->available;
            }
        };
    }

    public function test_validate_order_passes_for_valid_order()
    {
        $validator = new PaymentOrderValidator();
        $validator->validate_order($this->make_order(), ['fiat_amount' => 100.0], $this->make_gateway());
        $this->assertTrue(true);
    }

    public function test_validate_order_throws_when_order_does_not_need_payment()
    {
        $validator = new PaymentOrderValidator();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not require payment');
        $validator->validate_order($this->make_order(['needs_payment' => false]), ['fiat_amount' => 100.0], $this->make_gateway());
    }

    public function test_validate_order_throws_when_fiat_amount_not_positive()
    {
        $validator = new PaymentOrderValidator();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is not valid for payment');
        $validator->validate_order($this->make_order(), ['fiat_amount' => 0], $this->make_gateway());
    }

    public function test_validate_order_throws_when_payment_method_mismatches_gateway()
    {
        $validator = new PaymentOrderValidator();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('incompatible to payment gateway');
        $validator->validate_order($this->make_order(['payment_method' => 'other_gateway']), ['fiat_amount' => 100.0], $this->make_gateway());
    }

    public function test_validate_order_accepts_express_payment_method_variant()
    {
        $validator = new PaymentOrderValidator();
        $validator->validate_order($this->make_order(['payment_method' => 'paycrypto_me_express']), ['fiat_amount' => 100.0], $this->make_gateway());
        $this->assertTrue(true);
    }

    public function test_validate_order_throws_when_currency_missing()
    {
        $validator = new PaymentOrderValidator();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('currency');
        $validator->validate_order($this->make_order(['currency' => '']), ['fiat_amount' => 100.0], $this->make_gateway());
    }

    public function test_validate_gateway_config_passes_when_available()
    {
        $validator = new PaymentOrderValidator();
        $validator->validate_gateway_config($this->make_gateway(true));
        $this->assertTrue(true);
    }

    public function test_validate_gateway_config_throws_when_not_available()
    {
        $validator = new PaymentOrderValidator();
        $this->expectException(PayCryptoMeException::class);
        $this->expectExceptionMessage('Payment gateway is not available.');
        $validator->validate_gateway_config($this->make_gateway(false));
    }
}
