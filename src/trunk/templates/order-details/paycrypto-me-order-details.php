<?php
if (!defined('ABSPATH')) {
    exit;
}

$paycryptome_crypto_label = $payment_display_data['crypto_label'];
$paycryptome_state = $payment_display_data['payment_state'] ?? 'order_status';
$paycryptome_status_label = match ($paycryptome_state) {
    'paid' => __('Payment recorded by the store', 'paycrypto-me-for-woocommerce'),
    'refunded' => __('Refund recorded by the store', 'paycrypto-me-for-woocommerce'),
    'cancelled' => __('Order cancelled', 'paycrypto-me-for-woocommerce'),
    'on_hold' => __('Awaiting store review', 'paycrypto-me-for-woocommerce'),
    'failed' => __('Payment attempt failed', 'paycrypto-me-for-woocommerce'),
    'expired' => __('Expired', 'paycrypto-me-for-woocommerce'),
    'no_payment_due' => __('No payment required', 'paycrypto-me-for-woocommerce'),
    'unavailable' => __('Payment instructions unavailable', 'paycrypto-me-for-woocommerce'),
    'awaiting_payment' => __('Awaiting Payment', 'paycrypto-me-for-woocommerce'),
    default => wc_get_order_status_name($payment_display_data['order_status'] ?? ''),
};

if ($payment_display_data['payment_identifier']): ?>
    <section class="wc-block-order-confirmation-billing-address paycrypto-me-order-details paycrypto-me-order-details--<?php echo esc_attr($payment_display_data['crypto_network']); ?>">
        <h3><?php esc_html_e('Payment Details', 'paycrypto-me-for-woocommerce'); ?></h3>

        <div class="paycrypto-me-order-details__container">
            <div class="paycrypto-me-order-details__wrapper">
                <small><?php esc_html_e('Fiat Amount:', 'paycrypto-me-for-woocommerce'); ?></small>
                <small><?php echo wp_kses_post( wc_price( $payment_display_data['fiat_amount'], array( 'currency' => $payment_display_data['fiat_currency'] ) ) ); ?></small>
            </div>
            <?php if (($payment_display_data['refunded_amount'] ?? 0) > 0): ?>
                <div class="paycrypto-me-order-details__wrapper">
                    <small><?php esc_html_e('Refunded amount', 'paycrypto-me-for-woocommerce'); ?></small>
                    <small><?php echo wp_kses_post(wc_price($payment_display_data['refunded_amount'], ['currency' => $order_currency])); ?></small>
                </div>
            <?php endif; ?>
            <?php if (!empty($payment_display_data['crypto_amount'])): ?>
                <div class="paycrypto-me-order-details__wrapper">
                    <small><?php esc_html_e('Crypto Amount:', 'paycrypto-me-for-woocommerce'); ?></small>
                    <small><?php echo esc_html( number_format_i18n( (float) $payment_display_data['crypto_amount'], 8 ) . ' ' . $payment_display_data['crypto_currency'] ); ?></small>
                </div>
            <?php endif; ?>
            <?php if (($can_pay || $paycryptome_state === 'expired') && !empty($payment_display_data['expires_at_formatted'])): ?>
                <div class="paycrypto-me-order-details__wrapper">
                    <small><?php esc_html_e('Expires at:', 'paycrypto-me-for-woocommerce'); ?></small>
                    <small class="paycrypto-me-order-details__expires"><?php echo esc_html($payment_display_data['expires_at_formatted']); ?></small>
                </div>
            <?php endif; ?>
            <div class="paycrypto-me-order-details__wrapper paycrypto-me-order-details__status-row">
                <small><?php esc_html_e('Status:', 'paycrypto-me-for-woocommerce'); ?></small>
                <div class="paycrypto-me-order-details__status-value">
                    <span class="paycrypto-me-order-details__status-badge paycrypto-me-order-details__status-badge--<?php echo esc_attr($paycryptome_state); ?>">
                        <span class="paycrypto-me-order-details__status-dot"></span>
                        <?php echo esc_html($paycryptome_status_label); ?>
                    </span>
                    <?php if ($can_pay && !empty($payment_display_data['confirmations_required'])): ?>
                        <p class="paycrypto-me-order-details__confirmations-hint">
                            <?php printf(
                                /* translators: %d: number of confirmations required */
                                esc_html( _n(
                                    '%d confirmation required',
                                    '%d confirmations required',
                                    (int) $payment_display_data['confirmations_required'],
                                    'paycrypto-me-for-woocommerce'
                                )),
                                (int) $payment_display_data['confirmations_required']
                            ); ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="paycrypto-me-order-details__wrapper">
                <small><?php esc_html_e('Crypto Network:', 'paycrypto-me-for-woocommerce'); ?></small>
                <div class="paycrypto-me-network-switch">
                    <label class="paycrypto-me-crypto-label">
                        <?php echo esc_html($paycryptome_crypto_label); ?>
                    </label>
                    <label class="paycrypto-me-network-<?php echo esc_attr($payment_display_data['crypto_network']); ?>-label">
                        <?php echo esc_html($payment_display_data['network_label']); ?>
                    </label>
                </div>
            </div>
            <?php if ($can_pay && !empty($payment_display_data['payment_qr_code'])): ?>
                <div
                    class="paycrypto-me-order-details__wrapper paycrypto-me-order-details__wrapper--qr-code"
                    style="margin-top: 8px; justify-content: center; line-height: 1;">
                    <small style="font-weight: 700;"><?php esc_html_e('Scan QR Code to Pay:', 'paycrypto-me-for-woocommerce'); ?></small>
                </div>
                <div class="paycrypto-me-order-details__qr-code-image">
                    <img src="<?php echo esc_attr( $payment_display_data['payment_qr_code'] ); ?>"
                        alt="<?php esc_attr_e( 'QR Code for Payment', 'paycrypto-me-for-woocommerce' ); ?>" />
                </div>
            <?php endif; ?>
            <?php if ($admin): ?>
                <small><?php echo $payment_display_data['crypto_network'] === 'lightning'
                    ? esc_html__('Lightning invoice', 'paycrypto-me-for-woocommerce')
                    : esc_html__('Receiving address', 'paycrypto-me-for-woocommerce'); ?></small>
            <?php endif; ?>
            <?php if ($can_pay || $admin): ?>
                <div class="paycrypto-me-order-details__wrapper paycrypto-me-order-details__wrapper--address">
                    <small class="paycrypto-me-order-details__address"><?php echo esc_html($payment_display_data['payment_identifier']); ?></small>
                    <?php // type="button" is required, not decorative: this section also renders on the admin order screen, inside WooCommerce's order <form>, where a button with no type defaults to submit — copying the address saved the order and reported "Order updated." ?>
                    <button
                        type="button"
                        class="paycrypto-me-order-details__copy-address-button"
                        data-address="<?php echo esc_attr($payment_display_data['payment_identifier']); ?>"
                        aria-label="<?php esc_attr_e('Copy to clipboard', 'paycrypto-me-for-woocommerce'); ?>">
                        <svg class="paycrypto-me-copy-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16">
                            <path fill-rule="evenodd" d="M4 2a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2zm2-1a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1zM2 5a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1v-1h1v1a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h1v1z"/>
                        </svg>
                        <span class="paycrypto-me-copy-feedback"><?php esc_html_e('Copied!', 'paycrypto-me-for-woocommerce'); ?></span>
                    </button>
                </div>
            <?php endif; ?>
            <?php if ($can_pay): ?>
                <a class="woocommerce-button wp-element-button paycrypto-me-order-details__button paycrypto-me-order-details__open-wallet-button"
                    href="<?php echo esc_attr( $payment_display_data['payment_uri'] ); ?>" target="_blank" rel="noopener noreferrer">
                    ⚡ <?php esc_html_e('Pay Using Wallet', 'paycrypto-me-for-woocommerce'); ?>
                </a>
            <?php elseif ($retry_url !== ''): ?>
                <a class="woocommerce-button wp-element-button" href="<?php echo esc_url($retry_url); ?>">
                    <?php esc_html_e('Retry payment', 'paycrypto-me-for-woocommerce'); ?>
                </a>
            <?php endif; ?>
        </div>

    </section>
<?php endif; ?>
