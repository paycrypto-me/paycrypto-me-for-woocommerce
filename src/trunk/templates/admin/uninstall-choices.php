<?php
/** @package WooCommerce\PayCryptoMe */

defined('ABSPATH') || exit;
?>
</table>
<section class="paycrypto-danger-area" aria-labelledby="<?php echo esc_attr($this->get_field_key($key)); ?>">
    <h3 class="wc-settings-sub-title" id="<?php echo esc_attr($this->get_field_key($key)); ?>"><?php echo esc_html($data['title']); ?></h3>
    <?php if (!empty($data['description'])) { echo wp_kses_post($data['description']); } ?>
    <?php if (current_user_can('manage_options')) :
        $paycrypto_me_cleanup_choices = \PayCryptoMe\WooCommerce\UninstallPreferences::read();
        ?>
    <fieldset>
        <legend><?php esc_html_e('These choices apply to both gateways when you delete the plugin from WordPress.', 'paycrypto-me-for-woocommerce'); ?></legend>
        <p><label>
            <input type="checkbox" name="paycrypto_me_cleanup[logs]" value="yes" <?php checked($paycrypto_me_cleanup_choices['logs']); ?>>
            <?php esc_html_e('Delete this plugin’s WooCommerce logs on uninstall.', 'paycrypto-me-for-woocommerce'); ?>
        </label></p>
        <p><label>
            <input type="checkbox" aria-describedby="paycrypto-payment-cleanup-warning" name="paycrypto_me_cleanup[payments]" value="yes" <?php checked($paycrypto_me_cleanup_choices['payments']); ?>>
            <?php esc_html_e('Delete this plugin’s payment records on uninstall.', 'paycrypto-me-for-woocommerce'); ?>
        </label></p>
        <p id="paycrypto-payment-cleanup-warning" class="description"><?php esc_html_e('Orders remain, but their Bitcoin payment details and wallet derivation history are erased. Reinstalling with the same extended public key can reuse previous receiving addresses.', 'paycrypto-me-for-woocommerce'); ?></p>
        <?php wp_nonce_field('paycrypto_me_cleanup_' . $this->id, 'paycrypto_me_cleanup_nonce', false); ?>
    </fieldset>
    <?php endif; ?>
</section>
<table class="form-table">
