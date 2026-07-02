<?php
// Minimal WP helper fallbacks for unit tests (global namespace)

if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) { return $value; }
}
if (!function_exists('__')) {
    function __($text, $domain = null) { return $text; }
}
if (!function_exists('esc_html')) {
    function esc_html($text) { return $text; }
}
if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = null) { return $text; }
}
if (!function_exists('wp_kses_post')) {
    function wp_kses_post($text) { return $text; }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($text) { return strip_tags((string) $text); }
}
if (!function_exists('esc_url')) {
    function esc_url($url) { return $url; }
}
if (!function_exists('esc_attr')) {
    function esc_attr($text) { return $text; }
}
if (!function_exists('get_bloginfo')) {
    function get_bloginfo($show = '') { return 'Test Blog'; }
}
if (!function_exists('get_option')) {
    function get_option($key) { return null; }
}
if (!function_exists('absint')) {
    function absint($value) { return abs((int) $value); }
}
if (!function_exists('do_action')) {
    function do_action($tag /*, ...$args */) { return null; }
}
if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce($nonce, $action = -1) { return true; }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($value) { return $value; }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) { return trim((string) $str); }
}
if (!function_exists('wc_add_notice')) {
    function wc_add_notice($message, $type = 'error') { return null; }
}
if (!function_exists('wc_get_checkout_url')) {
    function wc_get_checkout_url() { return 'https://example.org/checkout'; }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) { return json_encode($data, $options, $depth); }
}
// Test-controlled globals ($TEST_CURRENT_USER_CAN / $TEST_CHECK_AJAX_REFERER) let
// individual tests flip these without redeclaring the function.
if (!function_exists('current_user_can')) {
    function current_user_can($capability) {
        global $TEST_CURRENT_USER_CAN;
        return isset($TEST_CURRENT_USER_CAN) ? (bool) $TEST_CURRENT_USER_CAN : true;
    }
}
if (!function_exists('check_ajax_referer')) {
    function check_ajax_referer($action, $query_arg = false) {
        global $TEST_CHECK_AJAX_REFERER;
        return isset($TEST_CHECK_AJAX_REFERER) ? (bool) $TEST_CHECK_AJAX_REFERER : true;
    }
}
// Ajax handlers under test call these and expect execution to stop; tests catch
// the exception to assert on the payload instead of mocking wp_die()/exit.
if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($data = null) { throw new \Exception('WP_JSON_SUCCESS:' . json_encode($data)); }
}
if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($data = null) { throw new \Exception('WP_JSON_ERROR:' . json_encode($data)); }
}

// Basic stubs for WP constants
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

// Single source of truth for WC_Payment_Gateway/WC_Order fallbacks — previously each
// test file declared its own guarded copy, and whichever file's autoloader position
// won the `class_exists()` race silently decided the arity every other file's mocks
// had to match (see BitcoinPaymentProcessorTest's get_option collision).
if (!class_exists('WC_Payment_Gateway')) {
    class WC_Payment_Gateway
    {
        public function get_option($key, $empty_value = null) { return $empty_value; }
        public function register_paycrypto_me_log($message, $level = 'info') { return null; }
    }
}
if (!class_exists('WC_Order')) {
    class WC_Order
    {
        public function get_id() { return 0; }
        public function get_order_number() { return $this->get_id(); }
        public function get_billing_first_name($context = 'view') { return ''; }
        public function get_total() { return '0'; }
        public function get_currency() { return 'USD'; }
    }
}
