<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       WC_Gateway_Lightning
 * @extends     WC_Payment_Gateway
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

class WC_Gateway_PayCryptoMe_Lightning extends Abstract_WC_Gateway_PayCryptoMe
{
    /**
     * Constructor for the gateway.
     */
    public function __construct()
    {
        $this->id = 'paycrypto_me_lightning';
        $this->method_title = __('Bitcoin Payments', 'paycrypto-me-for-woocommerce') . ' (' . __('Lightning Network', 'paycrypto-me-for-woocommerce') . ')';
        $this->method_description = __('Accept Bitcoin payments self-hosted via Lightning Network', 'paycrypto-me-for-woocommerce') . ' (' . __('Provided by PayCrypto.Me', 'paycrypto-me-for-woocommerce') . ').';
        $this->has_fields = false;

        parent::__construct();

        add_filter('woocommerce_generate_node_type_html', [$this, 'generate_node_type_html'], 10, 4);
        add_filter('woocommerce_generate_btcpay_test_button_html', [$this, 'generate_btcpay_test_button_html'], 10, 4);
        add_filter('woocommerce_generate_lnd_test_button_html', [$this, 'generate_lnd_test_button_html'], 10, 4);
        add_action('wp_ajax_paycrypto_test_btcpay_connection', [$this, 'ajax_test_btcpay_connection']);
        add_action('wp_ajax_paycrypto_test_lnd_connection', [$this, 'ajax_test_lnd_connection']);
    }

    protected function admin_enqueue_scripts_content($screen)
    {
        if (!$screen) {
            return;
        }

        $is_orders = $screen->id === 'woocommerce_page_wc-orders' || $screen->id === 'shop_order';
        $is_settings = strpos($screen->id, 'woocommerce_page_wc-settings') !== false || $screen->id === 'woocommerce_page_wc-settings';

        if ($is_orders) {
            return;
        }

        if ($is_settings) {
            wp_localize_script('paycrypto-me-admin', 'PayCryptoMeLightningData', array(
                'nodeFieldName' => $this->get_field_key('node_type'),
                'btcpayUrlName' => $this->get_field_key('btcpay_url'),
                'btcpayApiName' => $this->get_field_key('btcpay_api_key'),
                'btcpayStoreName' => $this->get_field_key('btcpay_store_id'),
                'btcpayNonce' => wp_create_nonce('paycrypto_btcpay_test'),
                'lndRestUrlName' => $this->get_field_key('lnd_rest_url'),
                'lndMacaroonName' => $this->get_field_key('lnd_macaroon_hex'),
                'lndCertificateName' => $this->get_field_key('lnd_certificate'),
                'lndVerifySslName' => $this->get_field_key('lnd_verify_ssl'),
                'lndNonce' => wp_create_nonce('paycrypto_lnd_test')
            ));
        }
    }

    public function get_available_networks()
    {
        return [];
    }

    public function get_available_cryptocurrencies($network = null)
    {
        return ['BTC']; //@NOTE: all networks using same crypto.
    }

    protected function init_form_fields_items()
    {
        return [
            'proxy_settings' => [
                'title' => __('Proxy Settings', 'paycrypto-me-for-woocommerce'),
                'type' => 'title',
                'description' => __('Configure proxy settings for connecting to your Lightning node.', 'paycrypto-me-for-woocommerce'),
            ],
            'node_type' => [
                'title' => __('Lightning Node Type', 'paycrypto-me-for-woocommerce'),
                'type' => 'node_type',
                'default' => 'btcpay',
            ],
            'invoice_expiry' => [
                'title' => __('Invoice Expiry', 'paycrypto-me-for-woocommerce'),
                'type' => 'number',
                'description' => __('Time before invoice expires. Min: 300 (5 min), Max: 86400 (24h). Default: 3600 (1 hour)', 'paycrypto-me-for-woocommerce'),
                'default' => '3600',
                'placeholder' => '3600',
                'custom_attributes' => [
                    'min' => '300',
                    'max' => '86400',
                    'step' => '1',
                ],
                'desc_tip' => true,
            ],
            'btcpay_url' => [
                'title' => __('BTCPay Server URL', 'paycrypto-me-for-woocommerce'),
                'type' => 'text',
                'description' => __('Full URL to your BTCPay Server (HTTPS required).', 'paycrypto-me-for-woocommerce'),
                'placeholder' => 'https://btcpay.example.com',
                'default' => '',
                'class' => 'paycrypto-btcpay-field',
                'desc_tip' => true,
            ],
            'btcpay_api_key' => [
                'title' => __('BTCPay API Key', 'paycrypto-me-for-woocommerce'),
                'type' => 'password',
                'description' => __('API key for BTCPay (min 20 characters).', 'paycrypto-me-for-woocommerce'),
                'placeholder' => 'sk_live_...',
                'default' => '',
                'class' => 'paycrypto-btcpay-field',
            ],
            'btcpay_store_id' => [
                'title' => __('BTCPay Store ID', 'paycrypto-me-for-woocommerce'),
                'type' => 'text',
                'description' => __('Your BTCPay store identifier.', 'paycrypto-me-for-woocommerce'),
                'default' => '',
                'class' => 'paycrypto-btcpay-field',
            ],
            'btcpay_test_connection' => [
                'title' => __('BTCPay Connection Test', 'paycrypto-me-for-woocommerce'),
                'type' => 'btcpay_test_button',
                'default' => '',
            ],
            'webhook_info' => [
                'title' => __('Webhook Configuration', 'paycrypto-me-for-woocommerce'),
                'type' => 'title',
                'description' => sprintf(
                    __('Configure this webhook URL in your Lightning node provider dashboard:<br><code>%s</code>', 'paycrypto-me-for-woocommerce'),
                    esc_url(rest_url('paycrypto-me/v1/webhook'))
                ),
            ],
            'btcpay_webhook_secret' => [
                'title' => __('BTCPay Webhook Secret', 'paycrypto-me-for-woocommerce'),
                'type' => 'password',
                'description' => __('Webhook secret (recommended minimum 16 characters).', 'paycrypto-me-for-woocommerce'),
                'default' => '',
                'class' => 'paycrypto-btcpay-field',
            ],
            'lnd_rest_url' => [
                'title' => __('lnd REST URL', 'paycrypto-me-for-woocommerce'),
                'type' => 'text',
                'description' => __('REST API endpoint (HTTPS required).', 'paycrypto-me-for-woocommerce'),
                'placeholder' => 'https://localhost:8080',
                'default' => '',
                'class' => 'paycrypto-lnd-field',
                'desc_tip' => true,
            ],
            'lnd_macaroon_hex' => [
                'title' => __('lnd Macaroon (hex)', 'paycrypto-me-for-woocommerce'),
                'type' => 'textarea',
                'description' => __('Admin macaroon in hexadecimal format (min 100 characters).', 'paycrypto-me-for-woocommerce'),
                'default' => '',
                'class' => 'paycrypto-lnd-field',
                'css' => 'min-height: 80px; font-family: monospace; font-size: 0.9em;',
            ],
            'lnd_certificate' => [
                'title' => __('SSL Certificate (PEM)', 'paycrypto-me-for-woocommerce'),
                'type' => 'textarea',
                'description' => __('Optional: Paste your SSL certificate in PEM format. If provided, this will be used for verification instead of disabling SSL checks.', 'paycrypto-me-for-woocommerce'),
                'default' => '',
                'class' => 'paycrypto-lnd-field',
                'css' => 'min-height: 120px; font-family: monospace; font-size: 0.85em;',
            ],
            'lnd_verify_ssl' => [
                'title' => __('Verify SSL Certificate', 'paycrypto-me-for-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable SSL certificate verification', 'paycrypto-me-for-woocommerce'),
                'description' => __('Enable for production. If disabled and no certificate is provided, SSL verification is skipped (development only).', 'paycrypto-me-for-woocommerce'),
                'default' => 'yes',
                'class' => 'paycrypto-lnd-field',
            ],
            'lnd_test_connection' => [
                'title' => __('lnd REST Connection Test', 'paycrypto-me-for-woocommerce'),
                'type' => 'lnd_test_button',
                'default' => '',
            ],
        ];
    }

    /**
     * Generate the custom HTML for the node_type field (renders radios without WP sanitization).
     *
     * @param string $field_html
     * @param string $key
     * @param array  $data
     * @param object $wc_settings
     * @return string
     */
    public function generate_node_type_html(...$args)
    {
        if (count($args) === 2 && is_array($args[1])) {
            $data = $args[1];
        } elseif (count($args) >= 3 && is_array($args[2])) {
            $data = $args[2];
        } else {
            $data = array();
        }

        $data = wp_parse_args($data, array('title' => '', 'description' => '', 'desc_tip' => false));

        $field_key = $this->get_field_key('node_type');
        $value = $this->get_option('node_type', 'btcpay');

        $html = '<tr valign="top">';
        $html .= '<th scope="row" class="titledesc"><label for="' . esc_attr($field_key) . '">' . esc_html($data['title']) . ' ' . $this->get_tooltip_html($data) . '</label></th>';
        $html .= '<td class="forminp"><fieldset style="display:flex; align-items:center; gap:12px;"><legend class="screen-reader-text"><span>' . esc_html($data['title']) . '</span></legend>';
        $html .= '<label><input type="radio" name="' . esc_attr($field_key) . '" value="btcpay" ' . checked($value, 'btcpay', false) . '> ' . esc_html__('BTCPay Server', 'paycrypto-me-for-woocommerce') . '</label> ';
        $html .= '<label><input type="radio" name="' . esc_attr($field_key) . '" value="lnd_rest" ' . checked($value, 'lnd_rest', false) . '> ' . esc_html__('lnd REST', 'paycrypto-me-for-woocommerce') . '</label>';
        if (!empty($data['description'])) {
            $html .= '<p class="description">' . wp_kses_post($data['description']) . '</p>';
        }
        $html .= '</fieldset></td></tr>';

        return $html;
    }

    /**
     * Generate the custom HTML for the btcpay_test_button field.
     *
     * @param string $field_html
     * @param string $key
     * @param array  $data
     * @param object $wc_settings
     * @return string
     */
    public function generate_btcpay_test_button_html(...$args)
    {
        if (count($args) === 2 && is_array($args[1])) {
            $data = $args[1];
        } elseif (count($args) >= 3 && is_array($args[2])) {
            $data = $args[2];
        } else {
            $data = array();
        }

        $data = wp_parse_args($data, array('title' => '', 'description' => '', 'desc_tip' => false));

        $html = '<tr valign="top">';
        $html .= '<td class="forminp" style="padding-top:0; padding-left:0;">';
        $html .= '<button id="paycrypto-btcpay-test" type="button" class="button paycrypto-btcpay-field">' . esc_html__('🔌 Test connection', 'paycrypto-me-for-woocommerce') . '</button> ';
        $html .= '<span id="paycrypto-btcpay-test-result" style="margin-left:10px"></span>';
        if (!empty($data['description'])) {
            $html .= '<p class="description">' . wp_kses_post($data['description']) . '</p>';
        }
        $html .= '</td></tr>';

        return $html;
    }

    /**
     * Generate the custom HTML for the lnd_test_button field.
     *
     * @param string $field_html
     * @param string $key
     * @param array  $data
     * @param object $wc_settings
     * @return string
     */
    public function generate_lnd_test_button_html(...$args)
    {
        if (count($args) === 2 && is_array($args[1])) {
            $data = $args[1];
        } elseif (count($args) >= 3 && is_array($args[2])) {
            $data = $args[2];
        } else {
            $data = array();
        }

        $data = wp_parse_args($data, array('title' => '', 'description' => '', 'desc_tip' => false));

        $html = '<tr valign="top">';
        $html .= '<th scope="row" class="titledesc">';
        $html .= '<button id="paycrypto-lnd-test" type="button" class="button paycrypto-lnd-field">' . esc_html__('🔌 Test connection', 'paycrypto-me-for-woocommerce') . '</button> ';
        $html .= '</th>';
        $html .= '<td class="forminp">';
        $html .= '<span id="paycrypto-lnd-test-result"></span>';
        if (!empty($data['description'])) {
            $html .= '<p class="description">' . wp_kses_post($data['description']) . '</p>';
        }
        $html .= '</td>';
        $html .= '</tr>';

        return $html;
    }

    public function ajax_test_btcpay_connection()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'paycrypto-me-for-woocommerce')));
        }

        check_ajax_referer('paycrypto_btcpay_test', 'security');

        $url = isset($_POST['btcpay_url']) ? esc_url_raw(wp_unslash($_POST['btcpay_url'])) : '';
        $api = isset($_POST['btcpay_api_key']) ? sanitize_text_field(wp_unslash($_POST['btcpay_api_key'])) : '';
        $store = isset($_POST['btcpay_store_id']) ? sanitize_text_field(wp_unslash($_POST['btcpay_store_id'])) : '';

        if (empty($url)) {
            wp_send_json_error(array('message' => __('BTCPay URL is required for test.', 'paycrypto-me-for-woocommerce')));
        }

        // Build endpoint to check: prefer store endpoint if provided, else list stores.
        $endpoint = rtrim($url, '/') . '/api/v1/stores';
        if ($store !== '') {
            $endpoint = rtrim($url, '/') . '/api/v1/stores/' . rawurlencode($store);
        }

        $args = array(
            'timeout' => 15,
            'headers' => array('Accept' => 'application/json'),
        );
        if ($api !== '') {
            $args['headers']['Authorization'] = 'token ' . $api;
        }

        $resp = wp_remote_get($endpoint, $args);
        if (is_wp_error($resp)) {
            wp_send_json_error(array('message' => $resp->get_error_message()));
        }

        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);

        if ($code >= 200 && $code < 300) {
            wp_send_json_success(array('message' => sprintf(__('Connection OK (HTTP %d).', 'paycrypto-me-for-woocommerce'), $code)));
        }

        $message = sprintf(__('Request failed (HTTP %d).', 'paycrypto-me-for-woocommerce'), $code);
        if (!empty($body)) {
            $message .= ' ' . wp_strip_all_tags(wp_trim_words($body, 40));
        }

        wp_send_json_error(array('message' => $message));
    }

    public function ajax_test_lnd_connection()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'paycrypto-me-for-woocommerce')));
        }

        check_ajax_referer('paycrypto_lnd_test', 'security');

        $url = isset($_POST['lnd_rest_url']) ? esc_url_raw(wp_unslash($_POST['lnd_rest_url'])) : '';
        $macaroon = isset($_POST['lnd_macaroon_hex']) ? sanitize_text_field(wp_unslash($_POST['lnd_macaroon_hex'])) : '';
        $certificate = isset($_POST['lnd_certificate']) ? wp_kses_post(wp_unslash($_POST['lnd_certificate'])) : '';
        $verify_ssl = isset($_POST['lnd_verify_ssl']) ? sanitize_text_field(wp_unslash($_POST['lnd_verify_ssl'])) : 'yes';

        if (empty($url)) {
            wp_send_json_error(array('message' => __('lnd REST URL is required for test.', 'paycrypto-me-for-woocommerce')));
        }

        $endpoint = rtrim($url, '/') . '/v1/getinfo';

        $args = array(
            'timeout' => 15,
            'headers' => array('Accept' => 'application/json'),
        );

        // Handle SSL verification: use certificate if provided, otherwise use verify_ssl flag
        if (!empty($certificate)) {
            // Save certificate to temp file for wp_remote_get
            $temp_cert = tempnam(sys_get_temp_dir(), 'lnd_cert_');
            if ($temp_cert && file_put_contents($temp_cert, $certificate)) {
                $args['sslcertificates'] = $temp_cert;
            }
        } else {
            // Fall back to verify_ssl flag
            $args['sslverify'] = ($verify_ssl === 'yes');
        }

        if (!empty($macaroon)) {
            $args['headers']['Grpc-Metadata-macaroon'] = $macaroon;
        }

        $resp = wp_remote_get($endpoint, $args);

        // Clean up temp certificate file if created
        if (!empty($temp_cert) && file_exists($temp_cert)) {
            unlink($temp_cert);
        }

        if (is_wp_error($resp)) {
            wp_send_json_error(array('message' => $resp->get_error_message()));
        }

        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);

        if ($code >= 200 && $code < 300) {
            $data = json_decode($body, true);
            $alias = isset($data['alias']) ? $data['alias'] : '';
            $message = sprintf(__('Connection OK (HTTP %d)', 'paycrypto-me-for-woocommerce'), $code);
            if ($alias) {
                $message .= ' - ' . sprintf(__('Node: %s', 'paycrypto-me-for-woocommerce'), esc_html($alias));
            }
            wp_send_json_success(array('message' => $message));
        }

        $message = sprintf(__('Request failed (HTTP %d).', 'paycrypto-me-for-woocommerce'), $code);
        if (!empty($body)) {
            $message .= ' ' . wp_strip_all_tags(wp_trim_words($body, 40));
        }

        wp_send_json_error(array('message' => $message));
    }

    private function _sanitize_text_val($v)
    {
        return is_null($v) ? '' : sanitize_text_field(wp_unslash($v));
    }

    public function validate_btcpay_url_field($key, $value)
    {
        if (is_null($value) || $value === '') {
            return '';
        }
        $val = trim(stripslashes($value));
        $url = esc_url_raw($val);
        if (empty($url)) {
            return '';
        }
        $parts = wp_parse_url($url);
        if (empty($parts['scheme']) || strtolower($parts['scheme']) !== 'https') {
            \WC_Admin_Settings::add_error(esc_html__('BTCPay URL must use HTTPS.', 'paycrypto-me-for-woocommerce'));
            return '';
        }
        return $url;
    }

    public function validate_btcpay_api_key_field($key, $value)
    {
        $val = $this->_sanitize_text_val($value);
        if ($val !== '' && strlen($val) < 20) {
            \WC_Admin_Settings::add_error(esc_html__('BTCPay API key must be at least 20 characters.', 'paycrypto-me-for-woocommerce'));
            return '';
        }
        return $val;
    }

    public function validate_btcpay_store_id_field($key, $value)
    {
        return $this->_sanitize_text_val($value);
    }

    public function validate_btcpay_webhook_secret_field($key, $value)
    {
        $val = $this->_sanitize_text_val($value);
        if ($val !== '' && strlen($val) < 16) {
            \WC_Admin_Settings::add_error(esc_html__('BTCPay webhook secret is shorter than the recommended 16 characters.', 'paycrypto-me-for-woocommerce'));
        }
        return $val;
    }

    public function validate_lnd_rest_url_field($key, $value)
    {
        if (is_null($value) || $value === '') {
            return '';
        }
        $val = trim(stripslashes($value));
        $url = esc_url_raw($val);
        if (empty($url)) {
            return '';
        }
        $parts = wp_parse_url($url);
        if (empty($parts['scheme']) || strtolower($parts['scheme']) !== 'https') {
            \WC_Admin_Settings::add_error(esc_html__('lnd REST URL must use HTTPS.', 'paycrypto-me-for-woocommerce'));
            return '';
        }
        return $url;
    }

    public function validate_lnd_macaroon_hex_field($key, $value)
    {
        $val = $this->_sanitize_text_val($value);
        $val = preg_replace('/\s+/', '', $val); // Remove whitespace
        if ($val !== '') {
            if (strlen($val) < 100) {
                \WC_Admin_Settings::add_error(esc_html__('lnd Macaroon must be at least 100 characters.', 'paycrypto-me-for-woocommerce'));
                return '';
            }
            if (!ctype_xdigit($val)) {
                \WC_Admin_Settings::add_error(esc_html__('lnd Macaroon must be a valid hexadecimal string.', 'paycrypto-me-for-woocommerce'));
                return '';
            }
        }
        return $val;
    }

    public function validate_lnd_certificate_field($key, $value)
    {
        $val = wp_kses_post(wp_unslash($value));
        if ($val === '') {
            return ''; // Certificate is optional
        }
        // Validate basic PEM format
        if (strpos($val, '-----BEGIN CERTIFICATE-----') === false || strpos($val, '-----END CERTIFICATE-----') === false) {
            \WC_Admin_Settings::add_error(esc_html__('Invalid certificate format. Must be valid PEM format starting with -----BEGIN CERTIFICATE-----.', 'paycrypto-me-for-woocommerce'));
            return '';
        }
        return $val;
    }

    public function validate_invoice_expiry_field($key, $value)
    {
        $val = absint($value);
        if ($val < 300) {
            \WC_Admin_Settings::add_error(esc_html__('Invoice Expiry must be at least 300 seconds (5 minutes).', 'paycrypto-me-for-woocommerce'));
            return '3600';
        }
        if ($val > 86400) {
            \WC_Admin_Settings::add_error(esc_html__('Invoice Expiry cannot exceed 86400 seconds (24 hours).', 'paycrypto-me-for-woocommerce'));
            return '3600';
        }
        return strval($val);
    }
}