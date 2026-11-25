<?php
// WP-CLI only. Synthetic payments use the real WooCommerce gateway entry points.
use PayCryptoMe\WooCommerce\PayCryptoMeDBStatementsService;
use PayCryptoMe\WooCommerce\PayCryptoMeLightningDBStatementsService;

[$operation, $method, $argument] = $args;
$lightning = in_array($method, ['btcpay', 'lnd_rest'], true);
$id = $lightning ? 'paycrypto_me_lightning' : 'paycrypto_me';
$vector = json_decode(file_get_contents('/qa-vectors.json'), true)[$operation === 'configure' ? (int) $argument : 0];
$settings = $lightning ? [
    'node_type' => $method, 'btcpay_url' => 'https://qa-btcpay.invalid', 'btcpay_api_key' => 'qa-key',
    'btcpay_store_id' => 'qa', 'btcpay_payment_method_id' => 'BTC-LN', 'invoice_expiry' => '3600',
    'lnd_rest_url' => 'https://qa-lnd.invalid', 'lnd_macaroon_hex' => 'abcd',
] : [
    'selected_network' => $vector['network'], 'crypto_currency' => 'BTC',
    'network_identifier' => $method === 'fixed' ? $vector['addresses'][0]['address'] : $vector['xpub'],
];
if ($operation === 'configure') {
    update_option('woocommerce_' . $id . '_settings', ['enabled' => 'yes', 'hide_for_non_admin_users' => 'no'] + $settings);
    return;
}
$gateway = WC()->payment_gateways()->payment_gateways()[$id];
if ($operation === 'create') {
    $order = wc_create_order(['customer_id' => get_user_by('login', 'buyer')->ID]);
    $order->set_billing_email('buyer@example.test');
    $order->add_product(wc_get_product((int) $argument));
    $order->set_payment_method($gateway);
    $order->calculate_totals();
    $order->save();
} else {
    $order = wc_get_order((int) $argument);
}
if ($operation === 'meta') {
    $values = [];
    foreach ($order->get_meta_data() as $meta) {
        if (str_starts_with($meta->key, '_paycrypto_me_')) {
            $values[$meta->key][] = $meta->value;
        }
    }
    if (!$values) {
        throw new RuntimeException('Missing payment metadata in historical order.');
    }
    ksort($values);
    echo wp_json_encode(hash('sha256', wp_json_encode($values)));
    return;
}
if ($operation === 'create' || $operation === 'retry') {
    wc_load_cart();
    $result = $gateway->process_payment($order->get_id());
    if ($result['result'] !== 'success') {
        throw new RuntimeException('QA payment failed: ' . ($result['message'] ?? 'unknown'));
    }
    $order = wc_get_order($order->get_id());
}
if ($operation === 'paid') {
    $order->payment_complete();
}
$db = $lightning ? new PayCryptoMeLightningDBStatementsService() : new PayCryptoMeDBStatementsService();
$row = $db->get_by_order_id($order->get_id());
$data = $gateway->get_order_display_data($order);
if (!$row || !$data) {
    throw new RuntimeException('Expected stored and projected payment');
}
echo wp_json_encode([
    'order' => $order->get_id(), 'row' => $row, 'status' => $order->get_status(),
    'identifier' => $data['payment_identifier'], 'action' => $data['payment_action'],
    'pay' => $order->get_checkout_payment_url(), 'received' => $order->get_checkout_order_received_url(),
]);
