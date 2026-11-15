<?php
/** Optional project support; never part of the merchant's payment configuration. */
namespace PayCryptoMe\WooCommerce;

defined('ABSPATH') || exit;
?>
<div class="paycrypto-support-box">
    <div class="support-qr">
        <img src="<?php echo esc_url($donation_assets . 'wallet_address_qrcode.png'); ?>" width="128" height="128"
            alt="<?php esc_attr_e('Bitcoin QR code for an optional donation', 'paycrypto-me-for-woocommerce'); ?>" />
    </div>
    <div class="support-body">
        <p class="support-kicker"><?php echo esc_html(WC_PayCryptoMe::NAME_BRAND); ?> · <?php esc_html_e('Open source', 'paycrypto-me-for-woocommerce'); ?></p>
        <h3><?php esc_html_e('Help move the project forward', 'paycrypto-me-for-woocommerce'); ?></h3>
        <p class="support-copy"><?php esc_html_e('Finding the plugin useful? Your Bitcoin donation helps support ongoing development.', 'paycrypto-me-for-woocommerce'); ?></p>
        <code id="btc-address-admin" class="support-address"><?php echo esc_html($donation_address); ?></code>
        <div class="support-actions">
            <button type="button" id="copy-btc-admin" class="support-btn">
                <span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
                <?php echo esc_html_x('Copy address', 'button label: copy the Bitcoin donation address', 'paycrypto-me-for-woocommerce'); ?>
            </button>
            <a class="support-wallet" href="<?php echo esc_url('bitcoin:' . $donation_address, array('bitcoin')); ?>">
                <?php echo esc_html_x('Open wallet', 'link label: open the Bitcoin donation URI in a wallet', 'paycrypto-me-for-woocommerce'); ?> <span aria-hidden="true">↗</span>
            </a>
        </div>
        <p class="support-status" role="status" aria-live="polite"></p>
    </div>
</div>
