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
            'amount'   => (string) ($args['amount'] ?? '0'),
            'currency' => (string) ($args['currency'] ?? 'BTC'),
            'metadata' => [
                'orderId'  => (string) ($args['order_id'] ?? ''),
                'itemDesc' => (string) ($args['memo'] ?? ''),
            ],
            'checkout' => [
                'speedPolicy'       => $this->get_speed_policy(),
                'paymentMethods'    => [$this->get_payment_method_id()],
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

        $invoice_id    = (string) ($data['id'] ?? '');
        $checkout_link = $data['checkoutLink'] ?? null;
        $status        = (string) ($data['status'] ?? '');

        return new LightningInvoiceResponse($invoice_id, '', $status, $checkout_link);
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

    public function resolve_payment_request(string $invoice_id): string
    {
        $btcpay_url = rtrim($this->gateway->get_option('btcpay_url'), '/');
        $store_id   = $this->gateway->get_option('btcpay_store_id');
        $api_key    = $this->gateway->get_option('btcpay_api_key');

        $url = "{$btcpay_url}/api/v1/stores/{$store_id}/invoices/{$invoice_id}/payment-methods";

        $response = $this->http->get($url, [
            'headers' => [
                'Authorization' => 'token ' . $api_key,
            ],
        ]);

        $methods = $this->parse_response($response);

        $payment_method_id = $this->get_payment_method_id();

        foreach ($methods as $method) {
            if (($method['paymentMethodId'] ?? '') === $payment_method_id) {
                $destination = (string) ($method['destination'] ?? '');

                if ($destination === '') {
                    $this->gateway->register_paycrypto_me_log(
                        \sprintf('BTCPay invoice %s: %s method present but destination is still empty.', $invoice_id, $payment_method_id),
                        'warning'
                    );
                }

                return $destination;
            }
        }

        $this->gateway->register_paycrypto_me_log(
            \sprintf(
                'BTCPay invoice %s: no %s payment method found (%d method(s) returned).',
                $invoice_id,
                $payment_method_id,
                count($methods)
            ),
            'warning'
        );

        return '';
    }

    /**
     * paymentMethodId used both to request the Lightning method at invoice creation and to
     * match it when resolving the bolt11 — same source, so the two can never drift apart.
     */
    private function get_payment_method_id(): string
    {
        return (string) apply_filters(
            'paycryptome_lightning_btcpay_payment_method_id',
            $this->gateway->get_option('btcpay_payment_method_id', 'BTC-LN')
        );
    }

    private function get_speed_policy(): string
    {
        return (string) apply_filters('paycryptome_lightning_btcpay_speed_policy', 'MediumSpeed');
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
                \sprintf('BTCPay HTTP error: status=%d body=%s', $status_code, substr($body, 0, 500)),
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
