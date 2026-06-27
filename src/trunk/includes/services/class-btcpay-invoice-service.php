<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       BtcpayInvoiceService
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

class BtcpayInvoiceService implements LightningInvoiceServiceContract
{
    public function __construct(
        private HttpClientContract  $http,
        private \WC_Payment_Gateway $gateway,
    ) {}

    public function create_invoice(array $args): LightningInvoiceResponse
    {
        $btcpay_url = rtrim($this->gateway->get_option('btcpay_url'), '/');
        $store_id   = $this->gateway->get_option('btcpay_store_id');
        $api_key    = $this->gateway->get_option('btcpay_api_key');

        $url  = "{$btcpay_url}/api/v1/stores/{$store_id}/invoices";
        $body = [
            'amount'   => '0',
            'currency' => 'BTC',
            'metadata' => [
                'orderId'  => (string) ($args['order_id'] ?? ''),
                'itemDesc' => (string) ($args['memo'] ?? ''),
            ],
            'checkout' => [
                'speedPolicy'       => 'MediumSpeed',
                'paymentMethods'    => ['BTC-LightningNetwork'],
                'expirationMinutes' => (int) floor((int) abs((int) ($args['expiry'] ?? 3600)) / 60),
            ],
        ];

        $response = $this->http->post($url, [
            'headers' => [
                'Authorization' => 'token ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode($body),
        ]);

        $data = $this->parse_response($response);

        $invoice_id      = (string) ($data['id'] ?? '');
        $checkout_link   = $data['checkoutLink'] ?? null;
        $status          = (string) ($data['status'] ?? '');
        $payment_request = $this->fetch_bolt11($btcpay_url, $store_id, $api_key, $invoice_id);

        return new LightningInvoiceResponse($invoice_id, $payment_request, $status, $checkout_link);
    }

    public function get_invoice_status(string $invoice_id): LightningInvoiceStatusResponse
    {
        $btcpay_url = rtrim($this->gateway->get_option('btcpay_url'), '/');
        $store_id   = $this->gateway->get_option('btcpay_store_id');
        $api_key    = $this->gateway->get_option('btcpay_api_key');

        $url = "{$btcpay_url}/api/v1/stores/{$store_id}/invoices/{$invoice_id}";

        $response = $this->http->get($url, [
            'headers' => [
                'Authorization' => 'token ' . $api_key,
            ],
        ]);

        $data   = $this->parse_response($response);
        $status = (string) ($data['status'] ?? '');

        return new LightningInvoiceStatusResponse($status === 'Settled', $status);
    }

    private function fetch_bolt11(string $btcpay_url, string $store_id, string $api_key, string $invoice_id): string
    {
        $url = "{$btcpay_url}/api/v1/stores/{$store_id}/invoices/{$invoice_id}/payment-methods";

        $response = $this->http->get($url, [
            'headers' => [
                'Authorization' => 'token ' . $api_key,
            ],
        ]);

        $methods = $this->parse_response($response);

        foreach ($methods as $method) {
            if (($method['paymentMethod'] ?? '') === 'BTC-LightningNetwork') {
                return (string) ($method['destination'] ?? '');
            }
        }

        return '';
    }

    /**
     * @throws PayCryptoMePaymentException when HTTP status >= 400, body is empty, or JSON is invalid
     */
    private function parse_response(array $response): array
    {
        $status_code = (int) ($response['response']['code'] ?? 0);
        $body        = (string) ($response['body'] ?? '');

        if ($status_code >= 400 || $body === '') {
            throw new PayCryptoMePaymentException(
                "BTCPay HTTP error: status={$status_code}",
                __('Payment via BTCPay Server failed. Please try again.', 'paycrypto-me-for-woocommerce')
            );
        }

        $data = json_decode($body, true);

        if (!is_array($data)) {
            throw new PayCryptoMePaymentException(
                'BTCPay invalid JSON response',
                __('Payment via BTCPay Server failed. Please try again.', 'paycrypto-me-for-woocommerce')
            );
        }

        return $data;
    }
}
