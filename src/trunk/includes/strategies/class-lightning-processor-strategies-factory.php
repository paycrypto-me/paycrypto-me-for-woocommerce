<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       LightningProcessorStrategiesFactory
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

class LightningProcessorStrategiesFactory
{
    public static function create(\WC_Payment_Gateway $gateway): GatewayProcessorContract
    {
        $node_type = $gateway->get_option('node_type', 'btcpay');

        return match ($node_type) {
            'lnd_rest' => new LndRestLightningProcessor($gateway),
            default    => new BtcpayLightningProcessor($gateway),
        };
    }
}
