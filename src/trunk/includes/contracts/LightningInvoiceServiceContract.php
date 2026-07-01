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

    /**
     * Single-attempt lookup of the payment_request (bolt11) for an invoice whose
     * creation didn't return it synchronously. Returns '' if the invoice exists
     * but the payment method isn't ready yet — that is not an error condition.
     *
     * @throws PayCryptoMePaymentException when HTTP status >= 400, body is empty, or JSON is invalid
     */
    public function resolve_payment_request(string $invoice_id): string;

    public function get_invoice_status(string $invoice_id): LightningInvoiceStatusResponse;
}
