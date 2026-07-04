<?php
use PHPUnit\Framework\TestCase;
use PayCryptoMe\WooCommerce\AvailablePaymentGatewaysFilter;

class AvailablePaymentGatewaysFilterTest extends TestCase
{
    private function make_order(bool $has_onchain, bool $has_lightning): \WC_Order
    {
        $meta = [];
        if ($has_onchain) {
            $meta['_paycrypto_me_payment_address'] = 'bc1qexampleaddress';
        }
        if ($has_lightning) {
            $meta['_paycrypto_me_payment_request'] = 'lnbc1exampleinvoice';
        }

        $order = $this->createMock(\WC_Order::class);
        $order->method('get_meta')->willReturnCallback(
            fn($key, $single = true, $context = 'view') => $meta[$key] ?? ''
        );

        return $order;
    }

    private function gateways(): array
    {
        return [
            'paycrypto_me'           => 'stub-bitcoin-gateway',
            'paycrypto_me_lightning' => 'stub-lightning-gateway',
            'other_gateway'          => 'stub-unrelated-gateway',
        ];
    }

    public function test_returns_gateways_unchanged_when_no_order()
    {
        $result = AvailablePaymentGatewaysFilter::apply($this->gateways(), null);

        $this->assertSame($this->gateways(), $result);
    }

    public function test_hides_lightning_when_order_already_has_onchain_meta()
    {
        $result = AvailablePaymentGatewaysFilter::apply($this->gateways(), $this->make_order(true, false));

        $this->assertArrayHasKey('paycrypto_me', $result);
        $this->assertArrayNotHasKey('paycrypto_me_lightning', $result);
        $this->assertArrayHasKey('other_gateway', $result);
    }

    public function test_hides_onchain_when_order_already_has_lightning_meta()
    {
        $result = AvailablePaymentGatewaysFilter::apply($this->gateways(), $this->make_order(false, true));

        $this->assertArrayHasKey('paycrypto_me_lightning', $result);
        $this->assertArrayNotHasKey('paycrypto_me', $result);
        $this->assertArrayHasKey('other_gateway', $result);
    }

    public function test_keeps_both_gateways_when_order_has_no_payment_meta_yet()
    {
        $result = AvailablePaymentGatewaysFilter::apply($this->gateways(), $this->make_order(false, false));

        $this->assertSame($this->gateways(), $result);
    }

    public function test_keeps_both_gateways_when_order_has_meta_from_both_legacy_edge_case()
    {
        // Only possible for orders created before this filter existed. Not
        // auto-remediated — see AvailablePaymentGatewaysFilter class docblock.
        $result = AvailablePaymentGatewaysFilter::apply($this->gateways(), $this->make_order(true, true));

        $this->assertSame($this->gateways(), $result);
    }
}
