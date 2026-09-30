<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       AvailablePaymentGatewaysFilter
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

/**
 * Hides the alternate PayCryptoMe gateway on "Pay for order" once the order
 * already carries payment meta for one of the two, so a customer cannot
 * switch payment rails mid-flow. If both metas are already present (an order
 * from before this filter existed), both gateways are left visible — that
 * legacy edge case is not auto-remediated here.
 */
class AvailablePaymentGatewaysFilter
{
    public static function filter(array $gateways): array
    {
        if (!is_wc_endpoint_url('order-pay')) {
            return $gateways;
        }

        $order = wc_get_order(absint(get_query_var('order-pay')));

        return self::apply($gateways, $order instanceof \WC_Order ? $order : null);
    }

    public static function apply(array $gateways, ?\WC_Order $order): array
    {
        if (!$order) {
            return $gateways;
        }

        $has_onchain = (bool) $order->get_meta('_paycrypto_me_payment_address');
        $has_lightning = (bool) $order->get_meta('_paycrypto_me_payment_request');

        if ($has_onchain xor $has_lightning) {
            unset($gateways[$has_onchain ? 'paycrypto_me_lightning' : 'paycrypto_me']);
        }

        return $gateways;
    }
}
