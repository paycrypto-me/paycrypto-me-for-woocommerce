<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       OrderGatewayMatcher
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

class OrderGatewayMatcher
{
    public static function matches(\WC_Order $order, string $gateway_id): bool
    {
        return \in_array($order->get_payment_method(), [$gateway_id, $gateway_id . '_express'], true);
    }
}
