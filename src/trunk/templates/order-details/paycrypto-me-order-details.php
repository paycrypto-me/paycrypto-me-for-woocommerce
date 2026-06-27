<?php
if (!defined('ABSPATH')) {
    exit;
}

if ($payment_display_data['payment_identifier']): ?>
    <section class="wc-block-order-confirmation-billing-address paycrypto-me-order-details">
        <h3><?php esc_html_e('Payment Details', 'paycrypto-me-for-woocommerce'); ?></h3>

        <div class="paycrypto-me-order-details__container">
            <div class="paycrypto-me-order-details__wrapper">
                <small><?php esc_html_e('Fiat Amount:', 'paycrypto-me-for-woocommerce'); ?></small>
                <small><?php echo wp_kses_post( wc_price( $payment_display_data['fiat_amount'], array( 'currency' => $payment_display_data['fiat_currency'] ) ) ); ?></small>
            </div>
            <?php if (!empty($payment_display_data['crypto_amount'])): ?>
                <div class="paycrypto-me-order-details__wrapper">
                    <small><?php esc_html_e('Crypto Amount:', 'paycrypto-me-for-woocommerce'); ?></small>
                    <small><?php echo esc_html( number_format_i18n( (float) $payment_display_data['crypto_amount'], 8 ) . ' ' . $payment_display_data['crypto_currency'] ); ?></small>
                </div>
            <?php endif; ?>
            <div class="paycrypto-me-order-details__wrapper">
                <small><?php esc_html_e('Crypto Network:', 'paycrypto-me-for-woocommerce'); ?></small>
                <div class="paycrypto-me-network-switch">
                    <label class="paycrypto-me-network-<?php echo esc_attr($payment_display_data['crypto_network']); ?>-label">
                        <?php echo esc_html($payment_display_data['network_label']); ?>
                    </label>
                </div>
            </div>
            <div
                class="paycrypto-me-order-details__wrapper paycrypto-me-order-details__wrapper--qr-code"
                style="margin-top: 8px; justify-content: center; line-height: 1;">
                <small style="font-weight: 700;"><?php esc_html_e('Scan QR Code to Pay:', 'paycrypto-me-for-woocommerce'); ?></small>
            </div>
            <div class="paycrypto-me-order-details__qr-code-image">
                <img src="<?php echo esc_attr( $payment_display_data['payment_qr_code'] ); ?>"
                    alt="<?php esc_attr_e( 'QR Code for Payment', 'paycrypto-me-for-woocommerce' ); ?>" />
            </div>
            <div class="paycrypto-me-order-details__wrapper paycrypto-me-order-details__wrapper--address">
                <small class="paycrypto-me-order-details__address"><?php echo esc_html($payment_display_data['payment_identifier']); ?></small>
                <button
                    class="paycrypto-me-order-details__copy-address-button"
                    data-address="<?php echo esc_attr($payment_display_data['payment_identifier']); ?>"
                    onclick="window.navigator.clipboard.writeText(this.getAttribute('data-address')) && alert('<?php esc_html_e('Payment address copied to clipboard.', 'paycrypto-me-for-woocommerce'); ?>');">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16">
                        <path fill-rule="evenodd" d="M4 2a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2zm2-1a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1zM2 5a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1v-1h1v1a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h1v1z"/>
                    </svg>
                </button>
            </div>

            <a class="woocommerce-button wp-element-button paycrypto-me-order-details__button paycrypto-me-order-details__open-wallet-button"
                href="<?php echo esc_attr( $payment_display_data['payment_uri'] ); ?>" target="_blank" rel="noopener noreferrer">
                <?php esc_html_e('open your wallet app', 'paycrypto-me-for-woocommerce'); ?>
            </a>
        </div>

    </section>
<?php endif; ?>
