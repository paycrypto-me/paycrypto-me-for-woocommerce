<?php
// Test-only WordPress MU plugin, mounted solely in the disposable package-upgrade store.
require_once '/qa-btcpay.php';
add_filter('pre_http_request', static function ($response, $arguments, $url) {
    if (wp_parse_url($url, PHP_URL_HOST) === 'qa-lnd.invalid' && str_ends_with($url, '/v1/invoices')) {
        $hash = hash('sha256', $arguments['body'], true);
        return ['headers' => [], 'body' => wp_json_encode([
            'payment_request' => 'lnbc1qa' . bin2hex($hash),
            'r_hash' => base64_encode($hash),
        ]), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []];
    }
    return $response;
}, 10, 3);
