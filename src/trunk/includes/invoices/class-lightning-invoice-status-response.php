<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       LightningInvoiceStatusResponse
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

class LightningInvoiceStatusResponse
{
    public function __construct(
        public readonly bool   $paid,
        public readonly string $status,
    ) {}
}
