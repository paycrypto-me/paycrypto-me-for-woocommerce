<?php

namespace PayCryptoMe\WooCommerce {
    // All service classes are loaded via Composer autoloader
}

namespace {

use PHPUnit\Framework\TestCase;
use PayCryptoMe\WooCommerce\LndRestInvoiceService;
use PayCryptoMe\WooCommerce\HttpClientContract;
use PayCryptoMe\WooCommerce\PayCryptoMePaymentException;

class LndRestInvoiceServiceTest extends TestCase
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
            'lnd_rest_url'    => 'https://lnd.example.com',
            'lnd_macaroon_hex' => 'deadbeef',
            'lnd_certificate' => '',
            'lnd_verify_ssl'  => 'yes',
        ]);
    }

    private function ok_response(array $body): array
    {
        return [
            'response' => ['code' => 200, 'message' => 'OK'],
            'body'     => json_encode($body),
        ];
    }

    public function test_create_invoice_returns_correct_payment_request_and_invoice_id(): void
    {
        // r_hash as URL-safe base64 of known bytes: 0xdeadbeef → base64url = '3q2-7w=='
        $r_hash_bytes = "\xde\xad\xbe\xef";
        $r_hash_b64   = rtrim(strtr(base64_encode($r_hash_bytes), '+/', '-_'), '=');
        $expected_id  = bin2hex($r_hash_bytes); // 'deadbeef'

        $http = new class($r_hash_b64) implements HttpClientContract {
            public function __construct(private string $r_hash) {}
            public function post(string $url, array $args): array
            {
                return [
                    'response' => ['code' => 200, 'message' => 'OK'],
                    'body'     => json_encode([
                        'payment_request' => 'lnbc1test',
                        'r_hash'          => $this->r_hash,
                    ]),
                ];
            }
            public function get(string $url, array $args): array { return []; }
        };

        $service  = new LndRestInvoiceService($http, $this->default_gateway());
        $response = $service->create_invoice(['memo' => 'test order', 'expiry' => 3600]);

        $this->assertSame('lnbc1test', $response->payment_request);
        $this->assertSame($expected_id, $response->invoice_id);
    }

    public function test_r_hash_base64url_to_hex_conversion(): void
    {
        // Verify the conversion with a known value:
        // base64url("hello") = "aGVsbG8" → hex = 68656c6c6f
        $r_hash_b64  = 'aGVsbG8';
        $expected_id = bin2hex(base64_decode('aGVsbG8='));

        $http = new class($r_hash_b64) implements HttpClientContract {
            public function __construct(private string $r_hash) {}
            public function post(string $url, array $args): array
            {
                return [
                    'response' => ['code' => 200, 'message' => 'OK'],
                    'body'     => json_encode([
                        'payment_request' => 'lnbc1hello',
                        'r_hash'          => $this->r_hash,
                    ]),
                ];
            }
            public function get(string $url, array $args): array { return []; }
        };

        $service  = new LndRestInvoiceService($http, $this->default_gateway());
        $response = $service->create_invoice(['memo' => 'test', 'expiry' => 600]);

        $this->assertSame($expected_id, $response->invoice_id);
    }

    public function test_get_invoice_status_settled_returns_paid_true(): void
    {
        $http = new class implements HttpClientContract {
            public function post(string $url, array $args): array { return []; }
            public function get(string $url, array $args): array
            {
                return [
                    'response' => ['code' => 200, 'message' => 'OK'],
                    'body'     => json_encode(['state' => 'SETTLED']),
                ];
            }
        };

        $result = (new LndRestInvoiceService($http, $this->default_gateway()))
            ->get_invoice_status('deadbeef');

        $this->assertTrue($result->paid);
        $this->assertSame('SETTLED', $result->status);
    }

    public function test_resolve_payment_request_returns_empty_string(): void
    {
        $http = new class implements HttpClientContract {
            public function post(string $url, array $args): array { return []; }
            public function get(string $url, array $args): array { return []; }
        };

        $result = (new LndRestInvoiceService($http, $this->default_gateway()))
            ->resolve_payment_request('deadbeef');

        $this->assertSame('', $result);
    }

    public function test_create_invoice_empty_body_throws_exception(): void
    {
        $http = new class implements HttpClientContract {
            public function post(string $url, array $args): array
            {
                return ['response' => ['code' => 200, 'message' => 'OK'], 'body' => ''];
            }
            public function get(string $url, array $args): array { return []; }
        };

        $this->expectException(PayCryptoMePaymentException::class);
        (new LndRestInvoiceService($http, $this->default_gateway()))
            ->create_invoice(['memo' => 'test', 'expiry' => 3600]);
    }
}

} // end global namespace
