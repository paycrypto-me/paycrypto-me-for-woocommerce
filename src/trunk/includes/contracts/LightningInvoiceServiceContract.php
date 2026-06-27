<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

interface LightningInvoiceServiceContract
{
    public function create_invoice(array $args): LightningInvoiceResponse;

    public function get_invoice_status(string $invoice_id): LightningInvoiceStatusResponse;
}
