<?php

namespace PayCryptoMe\WooCommerce {
    // All service classes are loaded via Composer autoloader
}

namespace {

use PHPUnit\Framework\TestCase;
use PayCryptoMe\WooCommerce\BtcpayInvoiceService;
use PayCryptoMe\WooCommerce\HttpClientContract;
use PayCryptoMe\WooCommerce\PayCryptoMePaymentException;

// wp_json_encode/WC_Payment_Gateway fallbacks live in tests/_support/wp-helpers.php
// (loaded by bootstrap.php before any test file).

class BtcpayInvoiceServiceTest extends TestCase
{
    private function make_gateway(array $opts): \WC_Payment_Gateway
    {
        return new class($opts) extends \WC_Payment_Gateway {
            private array $opts;
            public function __construct(array $opts) { $this->opts = $opts; }
            public function get_option($key, $empty_value = null) { return $this->opts[$key] ?? $empty_value; }
        };
    }

    private function default_gateway(): \WC_Payment_Gateway
    {
        return $this->make_gateway([
            'btcpay_url'      => 'https://btcpay.example.com',
            'btcpay_store_id' => 'store123',
            'btcpay_api_key'  => 'apikey456',
        ]);
    }

    private function json_response(int $code, $body): array
    {
        return [
            'response' => ['code' => $code, 'message' => 'OK'],
            'body'     => json_encode($body),
        ];
    }

    public function test_create_invoice_returns_correct_response(): void
    {
        $invoice_response = [
            'id'           => 'inv_abc',
            'status'       => 'New',
            'checkoutLink' => 'https://btcpay.example.com/i/inv_abc',
        ];

        $http = new class($invoice_response) implements HttpClientContract {
            public function __construct(private array $invoice) {}

            public function post(string $url, array $args): array
            {
                return [
                    'response' => ['code' => 200, 'message' => 'OK'],
                    'body'     => json_encode($this->invoice),
                ];
            }

            public function get(string $url, array $args): array { return []; }
        };

        $service  = new BtcpayInvoiceService($http, $this->default_gateway());
        $response = $service->create_invoice(['order_id' => 1, 'memo' => 'Order #1', 'expiry' => 3600]);

        $this->assertSame('inv_abc', $response->invoice_id);
        // create_invoice() no longer resolves the bolt11 inline — BTCPay generates the
        // Lightning invoice asynchronously, so resolution happens via resolve_payment_request().
        $this->assertSame('', $response->payment_request);
        $this->assertSame('New', $response->status);
        $this->assertSame('https://btcpay.example.com/i/inv_abc', $response->checkout_link);
    }

    public function test_create_invoice_sends_order_amount_and_currency(): void
    {
        $http = new class implements HttpClientContract {
            public ?array $captured_body = null;
            public function post(string $url, array $args): array
            {
                $this->captured_body = json_decode($args['body'], true);
                return [
                    'response' => ['code' => 200, 'message' => 'OK'],
                    'body'     => json_encode(['id' => 'inv_abc', 'status' => 'New']),
                ];
            }
            public function get(string $url, array $args): array { return []; }
        };

        $service = new BtcpayInvoiceService($http, $this->default_gateway());
        $service->create_invoice(['order_id' => 1, 'memo' => 'Order #1', 'expiry' => 3600, 'amount' => '99.99', 'currency' => 'BRL']);

        $this->assertSame('99.99', $http->captured_body['amount']);
        $this->assertSame('BRL', $http->captured_body['currency']);
    }

    public function test_create_invoice_uses_custom_payment_method_id_and_speed_policy_from_gateway_option(): void
    {
        $http = new class implements HttpClientContract {
            public ?array $captured_body = null;
            public function post(string $url, array $args): array
            {
                $this->captured_body = json_decode($args['body'], true);
                return [
                    'response' => ['code' => 200, 'message' => 'OK'],
                    'body'     => json_encode(['id' => 'inv_abc', 'status' => 'New']),
                ];
            }
            public function get(string $url, array $args): array { return []; }
        };

        $gateway = $this->make_gateway([
            'btcpay_url'                => 'https://btcpay.example.com',
            'btcpay_store_id'           => 'store123',
            'btcpay_api_key'            => 'apikey456',
            'btcpay_payment_method_id'  => 'BTC-LN-CUSTOM',
        ]);

        (new BtcpayInvoiceService($http, $gateway))
            ->create_invoice(['order_id' => 1, 'memo' => 'Order #1', 'expiry' => 3600]);

        $this->assertSame(['BTC-LN-CUSTOM'], $http->captured_body['checkout']['paymentMethods']);
    }

    public function test_resolve_payment_request_matches_custom_payment_method_id_from_gateway_option(): void
    {
        $payment_methods_response = [
            ['paymentMethodId' => 'BTC-LN-CUSTOM', 'destination' => 'lnbc1customdestination'],
        ];

        $http = new class($payment_methods_response) implements HttpClientContract {
            public function __construct(private array $methods) {}
            public function post(string $url, array $args): array { return []; }
            public function get(string $url, array $args): array
            {
                return ['response' => ['code' => 200, 'message' => 'OK'], 'body' => json_encode($this->methods)];
            }
        };

        $gateway = $this->make_gateway([
            'btcpay_url'               => 'https://btcpay.example.com',
            'btcpay_store_id'          => 'store123',
            'btcpay_api_key'           => 'apikey456',
            'btcpay_payment_method_id' => 'BTC-LN-CUSTOM',
        ]);

        $payment_request = (new BtcpayInvoiceService($http, $gateway))->resolve_payment_request('inv_abc');

        $this->assertSame('lnbc1customdestination', $payment_request);
    }

    public function test_resolve_payment_request_returns_bolt11_when_ready(): void
    {
        $payment_methods_response = [
            ['paymentMethodId' => 'BTC-LN',   'destination' => 'lnbc1234abcd'],
            ['paymentMethodId' => 'BTC-CHAIN', 'destination' => 'bc1qtest'],
        ];

        $http = new class($payment_methods_response) implements HttpClientContract {
            public function __construct(private array $methods) {}
            public function post(string $url, array $args): array { return []; }
            public function get(string $url, array $args): array
            {
                return $this->json_ok($this->methods);
            }
            private function json_ok($body): array
            {
                return ['response' => ['code' => 200, 'message' => 'OK'], 'body' => json_encode($body)];
            }
        };

        $service         = new BtcpayInvoiceService($http, $this->default_gateway());
        $payment_request = $service->resolve_payment_request('inv_abc');

        $this->assertSame('lnbc1234abcd', $payment_request);
    }

    public function test_resolve_payment_request_returns_empty_string_when_not_ready(): void
    {
        $payment_methods_response = [
            ['paymentMethodId' => 'BTC-LN', 'destination' => ''],
        ];

        $http = new class($payment_methods_response) implements HttpClientContract {
            public function __construct(private array $methods) {}
            public function post(string $url, array $args): array { return []; }
            public function get(string $url, array $args): array
            {
                return ['response' => ['code' => 200, 'message' => 'OK'], 'body' => json_encode($this->methods)];
            }
        };

        $service         = new BtcpayInvoiceService($http, $this->default_gateway());
        $payment_request = $service->resolve_payment_request('inv_abc');

        $this->assertSame('', $payment_request);
    }

    public function test_resolve_payment_request_throws_on_http_error(): void
    {
        $http = new class implements HttpClientContract {
            public function post(string $url, array $args): array { return []; }
            public function get(string $url, array $args): array
            {
                return ['response' => ['code' => 500, 'message' => 'Server Error'], 'body' => json_encode(['error' => 'boom'])];
            }
        };

        $this->expectException(PayCryptoMePaymentException::class);
        (new BtcpayInvoiceService($http, $this->default_gateway()))
            ->resolve_payment_request('inv_abc');
    }

    public function test_create_invoice_throws_on_http_401(): void
    {
        $http = new class implements HttpClientContract {
            public function post(string $url, array $args): array
            {
                return [
                    'response' => ['code' => 401, 'message' => 'Unauthorized'],
                    'body'     => json_encode(['error' => 'Unauthorized']),
                ];
            }
            public function get(string $url, array $args): array { return []; }
        };

        $this->expectException(PayCryptoMePaymentException::class);
        (new BtcpayInvoiceService($http, $this->default_gateway()))
            ->create_invoice(['order_id' => 1, 'memo' => 'test', 'expiry' => 3600]);
    }

    public function test_get_invoice_status_settled_returns_paid_true(): void
    {
        $http = new class implements HttpClientContract {
            public function post(string $url, array $args): array { return []; }
            public function get(string $url, array $args): array
            {
                return [
                    'response' => ['code' => 200, 'message' => 'OK'],
                    'body'     => json_encode(['status' => 'Settled']),
                ];
            }
        };

        $result = (new BtcpayInvoiceService($http, $this->default_gateway()))
            ->get_invoice_status('inv_abc');

        $this->assertTrue($result->paid);
        $this->assertSame('Settled', $result->status);
    }

    public function test_get_invoice_status_new_returns_paid_false(): void
    {
        $http = new class implements HttpClientContract {
            public function post(string $url, array $args): array { return []; }
            public function get(string $url, array $args): array
            {
                return [
                    'response' => ['code' => 200, 'message' => 'OK'],
                    'body'     => json_encode(['status' => 'New']),
                ];
            }
        };

        $result = (new BtcpayInvoiceService($http, $this->default_gateway()))
            ->get_invoice_status('inv_abc');

        $this->assertFalse($result->paid);
        $this->assertSame('New', $result->status);
    }
}

} // end global namespace
