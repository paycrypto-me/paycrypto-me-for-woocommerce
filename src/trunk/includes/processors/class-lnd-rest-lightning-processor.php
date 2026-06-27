<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       LndRestLightningProcessor
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

class LndRestLightningProcessor extends AbstractLightningProcessor
{
    public function __construct(\WC_Payment_Gateway $gateway)
    {
        parent::__construct($gateway);
        $this->service = new LndRestInvoiceService(new WpHttpClient(), $gateway);
        $this->db      = new PayCryptoMeLightningDBStatementsService();
    }

    protected function invoice_args_filter(): string              { return 'paycryptome_lightning_lnd_invoice_args'; }
    protected function node_type(): string                        { return 'lnd_rest'; }
    protected function base_invoice_args(\WC_Order $order): array { return []; }
}
