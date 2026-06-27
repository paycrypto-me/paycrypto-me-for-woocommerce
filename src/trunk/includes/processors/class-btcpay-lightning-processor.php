<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       BtcpayLightningProcessor
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

class BtcpayLightningProcessor extends AbstractLightningProcessor
{
    public function __construct(\WC_Payment_Gateway $gateway)
    {
        parent::__construct($gateway);
        $this->service = new BtcpayInvoiceService(new WpHttpClient(), $gateway);
        $this->db      = new PayCryptoMeLightningDBStatementsService();
    }

    protected function invoice_args_filter(): string              { return 'paycryptome_lightning_btcpay_invoice_args'; }
    protected function node_type(): string                        { return 'btcpay'; }
    protected function base_invoice_args(\WC_Order $order): array { return ['amount' => '0']; }
}
