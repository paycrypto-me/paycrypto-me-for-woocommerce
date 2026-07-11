<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       PaymentDisplayDataBuilder
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

class PaymentDisplayDataBuilder
{
    private QrCodeService $qr_code_service;

    public function __construct(QrCodeService $qr_code_service)
    {
        $this->qr_code_service = $qr_code_service;
    }

    /**
     * Normalizes the order-details display array shared by both gateways.
     *
     * $args carries the gateway-specific values: payment_identifier, payment_uri,
     * logo_path, crypto_network, network_label, crypto_amount, crypto_currency,
     * confirmations_required.
     */
    public function build(\WC_Order $order, array $args): array
    {
        // Gateways whose expiry isn't actually enforced (on-chain) opt out via show_expiry;
        // defaults to true so the enforced Lightning countdown still renders.
        $show_expiry          = $args['show_expiry'] ?? true;
        $expires_hours        = (int) $order->get_meta('_paycrypto_me_payment_expires_at');
        $order_date           = $order->get_date_created();
        $expires_at_formatted = ($show_expiry && $expires_hours > 0 && $order_date)
            ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $order_date->getTimestamp() + $expires_hours * HOUR_IN_SECONDS)
            : null;

        return [
            'payment_identifier'     => $args['payment_identifier'],
            'payment_uri'            => $args['payment_uri'],
            'payment_qr_code'        => $this->qr_code_service->generate_qr_code_data_uri($args['payment_uri'], $args['logo_path']),
            'fiat_amount'            => $order->get_meta('_paycrypto_me_fiat_amount'),
            'fiat_currency'          => $order->get_meta('_paycrypto_me_fiat_currency'),
            'crypto_amount'          => $args['crypto_amount'],
            'crypto_currency'        => $args['crypto_currency'],
            'crypto_label'           => $this->crypto_label($args['crypto_currency']),
            'network_label'          => $args['network_label'],
            'crypto_network'         => $args['crypto_network'],
            'expires_at'             => $order->get_meta('_paycrypto_me_payment_expires_at'),
            'expires_at_formatted'   => $expires_at_formatted,
            'confirmations_required' => $args['confirmations_required'],
        ];
    }

    private function crypto_label($crypto_currency): string
    {
        // Bitcoin-only plugin; the map exists so an unknown code degrades to itself.
        $names = ['BTC' => 'Bitcoin'];

        return $names[$crypto_currency] ?? (string) $crypto_currency;
    }
}
