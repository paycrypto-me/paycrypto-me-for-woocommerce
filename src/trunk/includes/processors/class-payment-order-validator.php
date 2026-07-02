<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       PaymentOrderValidator
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

class PaymentOrderValidator
{
    public function validate_order(\WC_Order $order, array $payment_data, \WC_Payment_Gateway $gateway)
    {
        if (!$order) {
            throw new \InvalidArgumentException('Order is unavailable.');
        }

        if (!$order->needs_payment()) {
            throw new \InvalidArgumentException(
                    \sprintf(
                        'Order #%s does not require payment.',
                        esc_html( (string) $order->get_id() )
                    )
            );
        }

        if ($payment_data['fiat_amount'] <= 0) {
            throw new \InvalidArgumentException(\sprintf(
                    'Order #%s total amount (%s) is not valid for payment.',
                    esc_html( (string) $order->get_id() ),
                    esc_html( wp_strip_all_tags( wc_price( $payment_data['fiat_amount'], [ 'currency' => $order->get_currency() ] ) ) )
                ) );
        }

        // Accept the gateway's own ID or the express block variant (gateway_id . '_express').
        $order_payment_method = $order->get_payment_method();
        if ($order_payment_method !== $gateway->id && $order_payment_method !== $gateway->id . '_express') {
            throw new \InvalidArgumentException(
                    \sprintf(
                        'Payment method (%s) of order #%s is incompatible to payment gateway (%s).',
                        esc_html( (string) $order_payment_method ),
                        esc_html( (string) $order->get_id() ),
                        esc_html( (string) $gateway->id )
                    ) );
        }

        if (!$order->get_currency()) {
            throw new \InvalidArgumentException(
                    \sprintf(
                        'Order #%s currency (%s) is not valid for payment.',
                        esc_html( (string) $order->get_id() ),
                        esc_html( (string) $order->get_currency() )
                    )
            );
        }
    }

    public function validate_gateway_config(\WC_Payment_Gateway $gateway)
    {
        if (!$gateway->is_available()) {
            throw new PayCryptoMeException('Payment gateway is not available.');
        }
    }
}
