<?php
/** WP-CLI eval-file fixture for the disposable uninstall browser suite. */

use PayCryptoMe\WooCommerce\PayCryptoMeDBStatementsService;
use PayCryptoMe\WooCommerce\PayCryptoMeLightningDBStatementsService;

if (!defined('WP_CLI') || !WP_CLI || !defined('PAYCRYPTOME_DISPOSABLE_UNINSTALL_QA') || PAYCRYPTOME_DISPOSABLE_UNINSTALL_QA !== true) {
    exit(1);
}

global $wpdb;
function pcm_qa_expect($condition, string $message): void
{
    if (!$condition) {
        WP_CLI::error($message);
    }
}

if ($args[0] === 'check-consent') {
    require_once WC_ABSPATH . 'includes/admin/class-wc-admin-settings.php';
    $lightning = new \PayCryptoMe\WooCommerce\WC_Gateway_PayCryptoMe_Lightning();
    $key = 'paycrypto_me_uninstall_cleanup';
    $admin = get_current_user_id();
    pcm_qa_expect(current_user_can('manage_options') && get_option($key) === false, 'Expected an upgraded store with no previous consent.');
    foreach ([null, 'invalid', wp_create_nonce('paycrypto_me_cleanup_paycrypto_me')] as $nonce) {
        $_POST = ['paycrypto_me_cleanup' => ['logs' => 'yes', 'payments' => 'yes']];
        if ($nonce !== null) {
            $_POST['paycrypto_me_cleanup_nonce'] = $nonce;
        }
        $lightning->process_admin_options();
        pcm_qa_expect(get_option($key) === false, 'An absent or invalid form nonce authorized cleanup.');
    }
    wp_set_current_user(0);
    $_POST['paycrypto_me_cleanup_nonce'] = wp_create_nonce('paycrypto_me_cleanup_paycrypto_me_lightning');
    $lightning->process_admin_options();
    pcm_qa_expect(get_option($key) === false, 'An unauthorized user changed cleanup choices.');
    wp_set_current_user($admin);
    $_POST['paycrypto_me_cleanup_nonce'] = wp_create_nonce('paycrypto_me_cleanup_paycrypto_me_lightning');
    $_POST['woocommerce_paycrypto_me_lightning_btcpay_url'] = 'http://invalid.example';
    $lightning->process_admin_options();
    pcm_qa_expect(get_option($key) === false, 'Invalid Lightning settings authorized cleanup.');

    $_POST['_wpnonce'] = wp_create_nonce('woocommerce-settings');
    $_POST['paycrypto_me_cleanup_nonce'] = wp_create_nonce('paycrypto_me_cleanup_paycrypto_me');
    $_POST['woocommerce_paycrypto_me_network_identifier'] = 'invalid';
    $onchain = new \PayCryptoMe\WooCommerce\WC_Gateway_PayCryptoMe();
    foreach ([null, 'mainnet'] as $network) {
        $_POST['woocommerce_paycrypto_me_selected_network'] = $network;
        pcm_qa_expect($onchain->process_admin_options() === false && get_option($key) === false, 'Invalid On-Chain settings authorized cleanup.');
    }
    return;
}

if ($args[0] === 'seed') {
    update_option('woocommerce_paycrypto_me_settings', [
        'network_identifier' => 'bc1qca6mftuav8al60ag7g2wrwv9dj4m35hlw94uum',
        'selected_network' => 'mainnet',
    ]);
    $bitcoin = new PayCryptoMeDBStatementsService();
    $wallet = $bitcoin->get_wallet_xpubkey_id('qa-wallet', 'mainnet') ?: $bitcoin->insert_wallet_xpubkey('qa-wallet', 'mainnet');
    foreach (['fixed', 'derived', 'invoice', 'other'] as $kind) {
        $method = ['fixed' => 'paycrypto_me', 'derived' => 'paycrypto_me', 'invoice' => 'paycrypto_me_lightning', 'other' => 'bacs'][$kind];
        $order = wc_create_order();
        $order->set_payment_method($method);
        $order->set_total(20);
        $order->add_meta_data('_paycrypto_me_pro_control', 'keep');
        if ($kind !== 'other') {
            $order->add_meta_data('_paycrypto_me_payment_uri', 'qa-uri');
        }
        $order->save();
        $id = $order->get_id();
        update_option('pcm_qa_order_' . $kind, $id);
        if ($kind === 'fixed') {
            pcm_qa_expect($bitcoin->insert_static_address($id, 'qa-fixed'), 'Fixed-address fixture failed.');
        } elseif ($kind === 'derived') {
            $index = $bitcoin->reserve_derivation_index_for_wallet($wallet);
            pcm_qa_expect($bitcoin->insert_address($id, $index, 'qa-derived', $wallet), 'Derived-address fixture failed.');
        } elseif ($kind === 'invoice') {
            pcm_qa_expect((new PayCryptoMeLightningDBStatementsService())->insert_invoice(
                $id, 'lnd_rest', 'qa-' . $id, 'ln-qa', gmdate('Y-m-d H:i:s', time() + HOUR_IN_SECONDS)
            ), 'Invoice fixture failed.');
        }
    }
    foreach (['paycrypto_me', 'paycrypto_me-other'] as $source) {
        wc_get_logger()->warning('Uninstall QA', ['source' => $source]);
        (new WC_Log_Handler_DB())->handle(time(), 'warning', 'Uninstall QA', ['source' => $source]);
        file_put_contents(WC_LOG_DIR . WC_Log_Handler_File::get_log_file_name($source . '.0'), 'rotated fixture');
    }
    return;
}

[$action, $logs, $payments] = $args;
$keep_logs = $logs === 'keep';
$keep_payments = $payments === 'keep';
foreach (['fixed' => 'paycrypto_me', 'derived' => 'paycrypto_me', 'invoice' => 'paycrypto_me_lightning', 'other' => 'bacs'] as $kind => $method) {
    $order = wc_get_order(get_option('pcm_qa_order_' . $kind));
    pcm_qa_expect($order && $order->get_payment_method() === $method && (float) $order->get_total() === 20.0, "Order lost: $kind");
    pcm_qa_expect($order->get_meta('_paycrypto_me_pro_control') === 'keep', "Extension metadata lost: $kind");
    pcm_qa_expect(($order->get_meta('_paycrypto_me_payment_uri') === 'qa-uri') === ($keep_payments && $kind !== 'other'), "Payment metadata: $kind");
}
foreach (['bitcoin_wallet_xpubkeys', 'bitcoin_derivation_indexes', 'bitcoin_transactions_data', 'lightning_invoices'] as $suffix) {
    $table = $wpdb->prefix . 'paycrypto_me_' . $suffix;
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
    pcm_qa_expect($exists === $keep_payments, "Unexpected table state: $table");
    if ($exists) {
        pcm_qa_expect($wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $table)) > 0, "Missing payment rows: $table");
    }
}
pcm_qa_expect((get_option('paycrypto_me_db_version') !== false) === $keep_payments, 'Unexpected schema version state.');
foreach (['paycrypto_me', 'paycrypto_me-other'] as $source) {
    $keep = $keep_logs || $source === 'paycrypto_me-other';
    $count = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE source = %s', $wpdb->prefix . 'woocommerce_log', $source));
    pcm_qa_expect(($count > 0) === $keep, "Database log: $source");
    pcm_qa_expect(file_exists(WC_LOG_DIR . WC_Log_Handler_File::get_log_file_name($source . '.0')) === $keep, "Rotated file: $source");
    pcm_qa_expect((bool) glob(WC_LOG_DIR . $source . '-20*.log') === $keep, "Current log file: $source");
}
if ($action === 'uninstalled') {
    foreach (['woocommerce_paycrypto_me_settings', 'woocommerce_paycrypto_me_lightning_settings', 'paycrypto_me_uninstall_cleanup'] as $key) {
        pcm_qa_expect(get_option($key) === false, "Unexpected retained setting: $key");
    }
}
