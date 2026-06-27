<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       LndRestInvoiceService
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

class LndRestInvoiceService implements LightningInvoiceServiceContract
{
    public function __construct(
        private HttpClientContract  $http,
        private \WC_Payment_Gateway $gateway,
    ) {}

    public function create_invoice(array $args): LightningInvoiceResponse
    {
        $lnd_url     = rtrim($this->gateway->get_option('lnd_rest_url'), '/');
        $macaroon    = $this->gateway->get_option('lnd_macaroon_hex');
        $certificate = $this->gateway->get_option('lnd_certificate');
        $verify_ssl  = $this->gateway->get_option('lnd_verify_ssl');

        $url  = "{$lnd_url}/v1/invoices";
        $body = [
            'memo'   => (string) ($args['memo'] ?? ''),
            'expiry' => (string) ((int) abs((int) ($args['expiry'] ?? 3600))),
        ];

        $http_args = [
            'headers' => [
                'Grpc-Metadata-macaroon' => $macaroon,
                'Content-Type'           => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ];

        $temp_cert = '';
        if (!empty($certificate)) {
            $temp_cert = tempnam(sys_get_temp_dir(), 'lnd_cert_');
            file_put_contents($temp_cert, $certificate);
            $http_args['sslcertificates'] = $temp_cert;
        } else {
            $http_args['sslverify'] = ($verify_ssl === 'yes');
        }

        try {
            $response = $this->http->post($url, $http_args);
            $data     = $this->parse_response($response);
        } finally {
            if (!empty($temp_cert) && file_exists($temp_cert)) {
                unlink($temp_cert);
            }
        }

        $payment_request = (string) ($data['payment_request'] ?? '');
        $r_hash_b64      = (string) ($data['r_hash'] ?? '');
        $invoice_id      = bin2hex(base64_decode(strtr($r_hash_b64, '-_', '+/')));

        return new LightningInvoiceResponse($invoice_id, $payment_request, 'OPEN');
    }

    public function get_invoice_status(string $invoice_id): LightningInvoiceStatusResponse
    {
        $lnd_url     = rtrim($this->gateway->get_option('lnd_rest_url'), '/');
        $macaroon    = $this->gateway->get_option('lnd_macaroon_hex');
        $certificate = $this->gateway->get_option('lnd_certificate');
        $verify_ssl  = $this->gateway->get_option('lnd_verify_ssl');

        $url       = "{$lnd_url}/v1/invoice/{$invoice_id}";
        $http_args = [
            'headers' => [
                'Grpc-Metadata-macaroon' => $macaroon,
            ],
        ];

        $temp_cert = '';
        if (!empty($certificate)) {
            $temp_cert = tempnam(sys_get_temp_dir(), 'lnd_cert_');
            file_put_contents($temp_cert, $certificate);
            $http_args['sslcertificates'] = $temp_cert;
        } else {
            $http_args['sslverify'] = ($verify_ssl === 'yes');
        }

        try {
            $response = $this->http->get($url, $http_args);
            $data     = $this->parse_response($response);
        } finally {
            if (!empty($temp_cert) && file_exists($temp_cert)) {
                unlink($temp_cert);
            }
        }

        $state = (string) ($data['state'] ?? '');

        return new LightningInvoiceStatusResponse($state === 'SETTLED', $state);
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
                "lnd REST HTTP error: status={$status_code}",
                __('Payment via Lightning node failed. Please try again.', 'paycrypto-me-for-woocommerce')
            );
        }

        $data = json_decode($body, true);

        if (!is_array($data)) {
            throw new PayCryptoMePaymentException(
                'lnd REST invalid JSON response',
                __('Payment via Lightning node failed. Please try again.', 'paycrypto-me-for-woocommerce')
            );
        }

        return $data;
    }
}
