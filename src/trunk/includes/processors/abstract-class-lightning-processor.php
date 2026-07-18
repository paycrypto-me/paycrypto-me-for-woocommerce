<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       AbstractLightningProcessor
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

abstract class AbstractLightningProcessor extends AbstractPaymentProcessor
{
    private const RESOLVE_MAX_ATTEMPTS = 2;
    private const RESOLVE_DELAY_MS     = 750;

    protected LightningInvoiceServiceContract         $service;
    protected PayCryptoMeLightningDBStatementsService $db;

    abstract protected function invoice_args_filter(): string;
    abstract protected function node_type(): string;
    abstract protected function base_invoice_args(\WC_Order $order): array;

    final public function process(\WC_Order $order, array $payment_data): array
    {
        $args = apply_filters(
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- concrete implementations return prefixed 'paycryptome_lightning_*_invoice_args'.
            $this->invoice_args_filter(),
            array_merge($this->base_invoice_args($order), [
                'order_id' => (string) $order->get_id(),
                'memo'     => apply_filters('paycryptome_lightning_invoice_memo', '', $order, $this->gateway),
                'expiry'   => apply_filters(
                    'paycryptome_lightning_invoice_expiry',
                    absint($this->gateway->get_option('invoice_expiry', 3600)),
                    $order,
                    $this->gateway
                ),
            ]),
            $order,
            $this->gateway
        );

        $payment_data['crypto_network'] = "lightning:{$this->node_type()}";

        $response = $this->service->create_invoice($args);

        if ($response->payment_request === '') {
            $response = $this->resolve_payment_request($response, $order);
        }

        $expiry_seconds  = (int) ($args['expiry'] ?? 3600);
        $expires_at      = gmdate('Y-m-d H:i:s', time() + $expiry_seconds);

        $this->db->insert_invoice(
            $order->get_id(),
            $this->node_type(),
            $response->invoice_id,
            $response->payment_request,
            $expires_at,
            isset($args['amount_sats']) ? (int) $args['amount_sats'] : null
        );

        // Align WC payment expiry with the actual Lightning invoice expiry.
        $payment_data['payment_expires_at'] = (string) ceil($expiry_seconds / 3600);

        $payment_data['payment_request']      = $response->payment_request;
        $payment_data['lightning_invoice_id'] = $response->invoice_id;

        // Uniform URI for QR code generation — both gateways expose payment_uri.
        $payment_data['payment_uri'] = 'lightning:' . $response->payment_request;

        return apply_filters('paycryptome_lightning_payment_data', $payment_data, $response, $order, $this->gateway);
    }

    private function resolve_payment_request(LightningInvoiceResponse $response, \WC_Order $order): LightningInvoiceResponse
    {
        for ($attempt = 1; $attempt <= self::RESOLVE_MAX_ATTEMPTS; $attempt++) {
            if ($attempt > 1) {
                usleep(self::RESOLVE_DELAY_MS * 1000);
            }

            $payment_request = $this->service->resolve_payment_request($response->invoice_id);

            if ($payment_request !== '') {
                if ($attempt > 1) {
                    $this->gateway->register_paycrypto_me_log(
                        \sprintf(
                            'Lightning payment_request resolved for invoice %s after %d attempt(s) (node_type=%s, order=%d)',
                            $response->invoice_id,
                            $attempt,
                            $this->node_type(),
                            $order->get_id()
                        ),
                        'info'
                    );
                }

                return new LightningInvoiceResponse(
                    $response->invoice_id,
                    $payment_request,
                    $response->status,
                    $response->checkout_link
                );
            }
        }

        throw new PayCryptoMePaymentException(
            \sprintf(
                'Lightning payment_request not resolved for invoice %s after %d attempts (node_type=%s, order=%d)',
                esc_html($response->invoice_id),
                esc_html((string) self::RESOLVE_MAX_ATTEMPTS),
                esc_html($this->node_type()),
                esc_html((string) $order->get_id())
            ),
            esc_html__('Your Lightning invoice is taking longer than expected to generate. Please try again in a moment.', 'paycrypto-me-for-woocommerce')
        );
    }
}
