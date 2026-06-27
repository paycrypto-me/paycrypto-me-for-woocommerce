<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       LightningInvoiceResponse
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

class LightningInvoiceResponse
{
    public function __construct(
        public readonly string  $invoice_id,
        public readonly string  $payment_request,
        public readonly string  $status,
        public readonly ?string $checkout_link = null,
    ) {}
}
