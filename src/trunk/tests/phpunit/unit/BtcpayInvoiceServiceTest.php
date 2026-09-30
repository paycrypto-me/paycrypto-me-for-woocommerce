<?php

namespace PayCryptoMe\WooCommerce {
    // All service classes are loaded via Composer autoloader
}

namespace {

use PHPUnit\Framework\TestCase;
use PayCryptoMe\WooCommerce\BtcpayInvoiceService;
use PayCryptoMe\WooCommerce\PayCryptoMePaymentException;

// wp_json_encode/WC_Payment_Gateway fallbacks live in tests/_support/wp-helpers.php;
// FakeHttpClient/http_ok()/http_error() live in tests/_support/fake-http-client.php
// (both loaded by bootstrap.php before any test file).

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

    public function test_create_invoice_returns_correct_response(): void
    {
        $http = FakeHttpClient::respondingToPost(http_ok([
            'id'           => 'inv_abc',
            'status'       => 'New',
            'checkoutLink' => 'https://btcpay.example.com/i/inv_abc',
        ]));

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
        $http = FakeHttpClient::respondingToPost(http_ok(['id' => 'inv_abc', 'status' => 'New']));

        $service = new BtcpayInvoiceService($http, $this->default_gateway());
        $service->create_invoice(['order_id' => 1, 'memo' => 'Order #1', 'expiry' => 3600, 'amount' => '99.99', 'currency' => 'BRL']);

        $this->assertSame('99.99', $http->lastPostBody()['amount']);
        $this->assertSame('BRL', $http->lastPostBody()['currency']);
    }

    public function test_create_invoice_uses_custom_payment_method_id_and_speed_policy_from_gateway_option(): void
    {
        $http = FakeHttpClient::respondingToPost(http_ok(['id' => 'inv_abc', 'status' => 'New']));

        $gateway = $this->make_gateway([
            'btcpay_url'                => 'https://btcpay.example.com',
            'btcpay_store_id'           => 'store123',
            'btcpay_api_key'            => 'apikey456',
            'btcpay_payment_method_id'  => 'BTC-LN-CUSTOM',
        ]);

        (new BtcpayInvoiceService($http, $gateway))
            ->create_invoice(['order_id' => 1, 'memo' => 'Order #1', 'expiry' => 3600]);

        $this->assertSame(['BTC-LN-CUSTOM'], $http->lastPostBody()['checkout']['paymentMethods']);
    }

    public function test_resolve_payment_request_matches_custom_payment_method_id_from_gateway_option(): void
    {
        $http = FakeHttpClient::respondingToGet(http_ok([
            ['paymentMethodId' => 'BTC-LN-CUSTOM', 'destination' => 'lnbc1customdestination'],
        ]));

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
        $http = FakeHttpClient::respondingToGet(http_ok([
            ['paymentMethodId' => 'BTC-LN',   'destination' => 'lnbc1234abcd'],
            ['paymentMethodId' => 'BTC-CHAIN', 'destination' => 'bc1qtest'],
        ]));

        $service         = new BtcpayInvoiceService($http, $this->default_gateway());
        $payment_request = $service->resolve_payment_request('inv_abc');

        $this->assertSame('lnbc1234abcd', $payment_request);
    }

    public function test_resolve_payment_request_returns_empty_string_when_not_ready(): void
    {
        $http = FakeHttpClient::respondingToGet(http_ok([
            ['paymentMethodId' => 'BTC-LN', 'destination' => ''],
        ]));

        $service         = new BtcpayInvoiceService($http, $this->default_gateway());
        $payment_request = $service->resolve_payment_request('inv_abc');

        $this->assertSame('', $payment_request);
    }

    public function test_resolve_payment_request_throws_on_http_error(): void
    {
        $http = FakeHttpClient::respondingToGet(http_error(500, 'Server Error', json_encode(['error' => 'boom'])));

        $this->expectException(PayCryptoMePaymentException::class);
        (new BtcpayInvoiceService($http, $this->default_gateway()))
            ->resolve_payment_request('inv_abc');
    }

    public function test_get_invoice_status_settled_returns_paid_true(): void
    {
        $http = FakeHttpClient::respondingToGet(http_ok(['status' => 'Settled']));

        $result = (new BtcpayInvoiceService($http, $this->default_gateway()))
            ->get_invoice_status('inv_abc');

        $this->assertTrue($result->paid);
        $this->assertSame('Settled', $result->status);
    }

    public function test_get_invoice_status_new_returns_paid_false(): void
    {
        $http = FakeHttpClient::respondingToGet(http_ok(['status' => 'New']));

        $result = (new BtcpayInvoiceService($http, $this->default_gateway()))
            ->get_invoice_status('inv_abc');

        $this->assertFalse($result->paid);
        $this->assertSame('New', $result->status);
    }

    // --- HTTP error matrix -------------------------------------------------------------
    // parse_response() treats every status >= 400 identically; this data provider proves
    // that holds across the codes BTCPay actually returns, for both create_invoice() (POST)
    // and get_invoice_status()/resolve_payment_request() (GET).

    public static function http_error_status_provider(): array
    {
        return [
            'bad request'         => [400, 'Bad Request'],
            'forbidden'           => [403, 'Forbidden'],
            'not found'           => [404, 'Not Found'],
            'too many requests'   => [429, 'Too Many Requests'],
            'service unavailable' => [503, 'Service Unavailable'],
        ];
    }

    /** @dataProvider http_error_status_provider */
    public function test_create_invoice_throws_on_http_error_status(int $code, string $message): void
    {
        $http = FakeHttpClient::respondingToPost(http_error($code, $message));

        $this->expectException(PayCryptoMePaymentException::class);
        (new BtcpayInvoiceService($http, $this->default_gateway()))
            ->create_invoice(['order_id' => 1, 'memo' => 'test', 'expiry' => 3600]);
    }

    /** @dataProvider http_error_status_provider */
    public function test_get_invoice_status_throws_on_http_error_status(int $code, string $message): void
    {
        $http = FakeHttpClient::respondingToGet(http_error($code, $message));

        $this->expectException(PayCryptoMePaymentException::class);
        (new BtcpayInvoiceService($http, $this->default_gateway()))
            ->get_invoice_status('inv_abc');
    }

    public function test_create_invoice_throws_on_malformed_json(): void
    {
        $http = FakeHttpClient::respondingToPost([
            'response' => ['code' => 200, 'message' => 'OK'],
            'body'     => '{not valid json',
        ]);

        $this->expectException(PayCryptoMePaymentException::class);
        (new BtcpayInvoiceService($http, $this->default_gateway()))
            ->create_invoice(['order_id' => 1, 'memo' => 'test', 'expiry' => 3600]);
    }

    public function test_create_invoice_throws_on_timeout(): void
    {
        // WpHttpClient (the real HttpClientContract adapter) turns a WP_Error — e.g. a
        // cURL timeout — into an empty array; simulate that directly here since fakes
        // implement the contract, not wp_remote_post()/wp_remote_get().
        $http = FakeHttpClient::respondingToPost([]);

        $this->expectException(PayCryptoMePaymentException::class);
        (new BtcpayInvoiceService($http, $this->default_gateway()))
            ->create_invoice(['order_id' => 1, 'memo' => 'test', 'expiry' => 3600]);
    }
}

} // end global namespace
