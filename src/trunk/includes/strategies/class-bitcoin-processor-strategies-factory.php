<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       BitcoinProcessorStrategiesFactory
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

class BitcoinProcessorStrategiesFactory
{
    public static function create(\WC_Payment_Gateway $gateway): GatewayProcessorContract
    {
        return new BitcoinPaymentProcessor(
            $gateway,
            new BitcoinAddressService(),
            new PayCryptoMeDBStatementsService()
        );
    }
}
