<?php
// Loaded only by wp eval-file, in the runner's disposable WordPress installations.
use PayCryptoMe\WooCommerce\AdvisoryLockName;
use PayCryptoMe\WooCommerce\DbInstaller;
use PayCryptoMe\WooCommerce\PayCryptoMeBitcoinGatewayActivate;
use PayCryptoMe\WooCommerce\PayCryptoMeDBStatementsService;

global $wpdb;
$operation = $args[0];
$table = $wpdb->prefix . PayCryptoMeBitcoinGatewayActivate::TABLE_DERIVATION_INDEXES;
$wallet = (int) ($args[1] ?? 1);
$db = new PayCryptoMeDBStatementsService();
switch ($operation) {
    case 'hold':
        $kind = $args[2];
        $name = $kind === 'install' ? AdvisoryLockName::install() : AdvisoryLockName::wallet($table, $wallet);
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name)) !== '1') {
            throw new RuntimeException('Fixture failed to acquire its own resource.');
        }
        try {
            echo "READY " . $wpdb->get_var('SELECT CONNECTION_ID()') . "\n";
            flush();
            stream_set_timeout(STDIN, 240);
            if (trim((string) fgets(STDIN)) !== 'release') {
                throw new RuntimeException('Barrier lost its coordinator.');
            }
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
        }
        break;
    case 'legacy-check':
        // Only invoked on this runner's private MySQL while another process owns
        // the candidate lock. A simultaneous successful acquisition proves why
        // changing versions needs an operational drain.
        $legacy = $args[2] === 'install' ? DbInstaller::INSTALL_LOCK : 'paycrypto_wallet_' . $wallet;
        $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $legacy));
        if ((string) $got !== '1') {
            throw new RuntimeException('Could not run the isolated cutover control.');
        }
        echo $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $legacy));
        break;
    case 'multisite':
        $first = AdvisoryLockName::install();
        $existing_service = new PayCryptoMeDBStatementsService();
        switch_to_blog($wallet);
        try {
            if (!DbInstaller::install(true) || $first === AdvisoryLockName::install()) {
                throw new RuntimeException('Blog switch failed to isolate installation');
            }
            $second = (new PayCryptoMeDBStatementsService())->reserve_derivation_index_for_wallet(1, 0);
            $original = $existing_service->reserve_derivation_index_for_wallet(1, 0);
        } finally {
            restore_current_blog();
        }
        if ($first !== AdvisoryLockName::install()) {
            throw new RuntimeException('Blog restore failed');
        }
        echo wp_json_encode([$second, $original, $existing_service->reserve_derivation_index_for_wallet(1, 0)]);
        break;
    case 'verify':
    case 'install':
        if ($operation === 'install' && !DbInstaller::install(true)) {
            throw new RuntimeException('Install failed.');
        }
        $tables = array_map(fn($name) => $wpdb->prefix . $name, DbInstaller::tables());
        foreach ($tables as $name) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($name))) !== $name) {
                throw new RuntimeException('Missing table: ' . $name);
            }
        }
        echo wp_json_encode([get_option(DbInstaller::VERSION_OPTION), $tables]);
        break;
    case 'reserve':
        echo $db->reserve_derivation_index_for_wallet($wallet, 2);
        break;
    case 'setup':
        $vector = json_decode(file_get_contents('/qa-vectors.json'), true)[$wallet];
        update_option('woocommerce_paycrypto_me_settings', [
            'enabled' => 'yes', 'hide_for_non_admin_users' => 'no', 'selected_network' => $vector['network'],
            'network_identifier' => $vector['xpub'], 'title' => 'Bitcoin QA', 'crypto_currency' => 'BTC',
        ]);
        $id = $db->get_wallet_xpubkey_id($vector['xpub'], $vector['network']);
        if (!$id) {
            $id = $db->insert_wallet_xpubkey($vector['xpub'], $vector['network']);
        }
        echo (int) $id;
        break;
    case 'product':
        WC_Install::create_pages();
        $customer = new WC_Customer(get_user_by('login', 'buyer')->ID);
        $customer->set_billing_first_name('QA');
        $customer->set_billing_last_name('Buyer');
        $customer->set_billing_email('qa@example.test');
        $customer->set_billing_country('US');
        $customer->set_billing_address_1('100 Test Street');
        $customer->set_billing_city('Boston');
        $customer->set_billing_state('MA');
        $customer->set_billing_postcode('02110');
        $customer->set_billing_phone('6175550100');
        $customer->save();
        update_option('woocommerce_coming_soon', 'no');
        $product = new WC_Product_Simple();
        $product->set_name('QA download');
        $product->set_virtual(true);
        $product->set_regular_price('10');
        $product->save();
        echo $product->get_id();
        break;
    case 'result':
        $order = wc_get_order($wallet);
        $row = $db->get_by_order_id($wallet);
        if (!$order || !$row || $order->get_payment_method() !== 'paycrypto_me') {
            throw new RuntimeException('Missing order or persisted Bitcoin payment.');
        }
        $settings = get_option('woocommerce_paycrypto_me_settings');
        if ((int) $row['wallet_xpubkeys_id'] !== 1 || $row['xpub'] !== $settings['network_identifier'] ||
            $order->get_meta('_paycrypto_me_payment_address') !== $row['payment_address']) {
            throw new RuntimeException('Wallet, order metadata and transaction disagree.');
        }
        echo wp_json_encode([$row['payment_address'], (int) $row['derivation_index'], $order->get_status()]);
        break;
    default:
        throw new RuntimeException('Unexpected QA operation');
}
