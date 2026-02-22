<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       Abstract_WC_Gateway_PayCryptoMe
 * @extends     WC_Payment_Gateway
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

abstract class Abstract_WC_Gateway_PayCryptoMe extends \WC_Payment_Gateway
{
    protected $hide_for_non_admin_users;
    protected $configured_networks;
    protected $debug_log;
    protected $payment_timeout_hours;
    protected $payment_number_confirmations;
    protected $support_btc_address = 'bc1qgvc07956sxuudk3jku6n03q5vc9tkrvkcar7uw';
    protected $support_btc_payment_address = 'PM8TJdrkRoSqkCWmJwUMojQCG1rEXsuCTQ4GG7Gub7SSMYxaBx7pngJjhV8GUeXbaJujy8oq5ybpazVpNdotFftDX7f7UceYodNGmffUUiS5NZFu4wq4';

    public function __construct()
    {
        $this->has_fields = true;

        $this->supports = ['products', 'pre-orders'];

        $this->init_form_fields();
        $this->init_settings();

        add_action('woocommerce_admin_order_data_after_order_details', array($this, 'render_admin_order_details_section'));
        add_action('woocommerce_order_details_before_order_table', array($this, 'render_checkout_order_details_section'));
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_checkout_styles'));

        do_action('paycryptome_for_woocommerce_gateway_loaded', $this);
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));

        add_action('wp_ajax_paycryptome_reset_derivation_index', array($this, 'ajax_reset_derivation_index'));
    }

    abstract protected function admin_enqueue_scripts_content(WP_Screen|null $screen);
    abstract public function get_available_networks();
    abstract public function get_available_cryptocurrencies($network = null);
    abstract protected function init_form_fields_items();

    public function admin_enqueue_scripts()
    {
        $screen = get_current_screen();

        $section = isset($_GET['section']) ? sanitize_text_field(wp_unslash($_GET['section'])) : '';

        if ($screen && $screen->id === 'woocommerce_page_wc-settings' && strpos($section, 'paycrypto_me') === 0) {
            wp_enqueue_style(
                'paycrypto-me-admin',
                WC_PayCryptoMe::plugin_url() . '/assets/paycrypto-me-admin.css',
                array(),
                filemtime(WC_PayCryptoMe::plugin_abspath() . 'assets/paycrypto-me-admin.css')
            );
            wp_enqueue_script(
                'paycrypto-me-admin',
                WC_PayCryptoMe::plugin_url() . '/assets/paycrypto-me-admin.js',
                array(),
                filemtime(WC_PayCryptoMe::plugin_abspath() . 'assets/paycrypto-me-admin.js'),
                true
            );
            wp_localize_script(
                'paycrypto-me-admin',
                'PayCryptoMeAdminData',
                array(
                    'networks' => $this->get_available_networks(),
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('paycrypto_me_nonce'),
                )
            );
        }
        $this->admin_enqueue_scripts_content($screen);
    }

    public function check_cryptocurrency_support($currency, $network = null)
    {
        $normalized_currency = strtoupper($currency);
        $available_cryptos = $this->get_available_cryptocurrencies($network);
        return \in_array($normalized_currency, $available_cryptos, true);
    }

    public function get_configured_networks()
    {
        return $this->configured_networks;
    }

    public function get_network_config($network_type = null)
    {
        $available_networks = $this->get_available_networks();
        if ($network_type && isset($available_networks[$network_type])) {
            return $available_networks[$network_type];
        }

        return $available_networks['mainnet'];
    }

    public function init_form_fields()
    {
        $network_options = array();
        $available_networks = $this->get_available_networks();

        foreach ($available_networks as $key => $network) {
            $network_options[$key] = $network['name'];
        }

        $selected_network_item = !$network_options ? [] : [
            'selected_network' => array(
                'title' => __('Network', 'paycrypto-me-for-woocommerce'),
                'type' => 'select',
                'options' => $network_options,
                'description' => __('Select the network for payments.', 'paycrypto-me-for-woocommerce'),
                'default' => 'mainnet',
                'required' => true,
            )
        ];

        $this->form_fields = array_merge(
            [
                'enabled' => array(
                    'title' => __('Enable/Disable', 'paycrypto-me-for-woocommerce'),
                    'label' => __('Enable', 'paycrypto-me-for-woocommerce') . ' ' . $this->method_title,
                    'type' => 'checkbox',
                    'default' => 'yes',
                ),
                'title' => array(
                    'title' => __('Title', 'paycrypto-me-for-woocommerce'),
                    'type' => 'text',
                    'description' => __('Payment method name displayed on Checkout page.', 'paycrypto-me-for-woocommerce'),
                    'default' => __('Pay with Bitcoin', 'paycrypto-me-for-woocommerce'),
                ),
                'description' => array(
                    'title' => __('Description', 'paycrypto-me-for-woocommerce'),
                    'type' => 'textarea',
                    'description' => __('Payment method description displayed on Checkout page.', 'paycrypto-me-for-woocommerce'),
                    'default' => __('Pay directly from your Bitcoin wallet. Place your order to view the QR code and payment instructions.', 'paycrypto-me-for-woocommerce'),
                ),
            ],
            $selected_network_item,
            $this->init_form_fields_items(),
            [
                'hide_for_non_admin_users' => array(
                    'title' => __('Hide for Non-Admin Users', 'paycrypto-me-for-woocommerce'),
                    'label' => __('Show only for administrators.', 'paycrypto-me-for-woocommerce'),
                    'type' => 'checkbox',
                    'default' => 'no',
                    'description' => __('If enabled, only administrators will see the payment method on Checkout page.', 'paycrypto-me-for-woocommerce'),
                ),
                'debug_log' => array(
                    'title' => __('Debug', 'paycrypto-me-for-woocommerce'),
                    'label' => __('Enable debugging messages', 'paycrypto-me-for-woocommerce'),
                    'type' => 'checkbox',
                    'default' => 'yes',
                    'description' => __('Debug logs will be saved to WooCommerce > Status > Logs.', 'paycrypto-me-for-woocommerce'),
                ),
                'paycrypto_me_donate' => array(
                    'type' => 'title',
                    'title' => __('Support the development!', 'paycrypto-me-for-woocommerce'),
                    'description' => '<div class="paycrypto-support-box">
                    <div>
                        <img src="' . WC_PayCryptoMe::plugin_url() . '/assets/wallet_address_qrcode.png">
                    </div>
                    <div>
                        <strong>Enjoying the plugin?</strong> Send some BTC to support:
                        <div style="display: flex; align-items: center; margin-top: 8px;">
                            <span id="btc-address-admin" class="support-content">' . esc_html($this->support_btc_address) . '</span>
                            <button type="button" id="copy-btc-admin" class="support-btn">Copy</button>
                        </div>
                    </div>
                </div>',
                ),
            ]
        );
    }

    public function is_available()
    {
        if ('yes' !== $this->enabled) {
            return false;
        }
        if ('yes' === $this->hide_for_non_admin_users && !current_user_can('manage_options')) {
            return false;
        }

        return true;
    }

    public function process_pre_order_payment($order)
    {
        return PaymentProcessor::instance()->process_payment($order->get_id(), $this);
    }

    public function process_payment($order_id)
    {
        return PaymentProcessor::instance()->process_payment($order_id, $this);
    }

    public function enqueue_checkout_styles()
    {
        if (is_checkout() || is_wc_endpoint_url('order-pay')) {
            $css_file = WC_PayCryptoMe::plugin_url() . '/assets/css/frontend/paycrypto-me-styles.css';
            $css_path = WC_PayCryptoMe::plugin_abspath() . 'assets/css/frontend/paycrypto-me-styles.css';

            if (file_exists($css_path)) {
                wp_enqueue_style(
                    'paycrypto-me-checkout',
                    $css_file,
                    array(),
                    filemtime($css_path)
                );
            }
        }

        if (is_order_received_page() || is_account_page()) {
            $css_file = WC_PayCryptoMe::plugin_url() . '/assets/css/frontend/paycrypto-me-order-details.css';
            $css_path = WC_PayCryptoMe::plugin_abspath() . 'assets/css/frontend/paycrypto-me-order-details.css';

            if (file_exists($css_path)) {
                wp_enqueue_style(
                    'paycrypto-me-order-details',
                    $css_file,
                    array(),
                    filemtime($css_path)
                );
            }
        }
    }

    public function register_paycrypto_me_log($message, $level = 'info')
    {
        if ($this->debug_log === 'yes') {
            $safe_message = wp_strip_all_tags((string) $message);
            \PayCryptoMe\WooCommerce\WC_PayCryptoMe::log($safe_message, $level);
        }
    }

    public function get_payment_method_data()
    {
        return [
            'icon' => $this->icon ?? '',
            'gateway_id' => $this->id ?? '',
            'debug_log' => $this->debug_log ?? 'no',
            'title' => $this->title ?: 'PayCrypto.Me',
            'description' => $this->description ?? '',
            'supports' => $this->supports ?? ['products'],
            'crypto_currency' => $this->get_available_cryptocurrencies()[0] ?? '',
        ];
    }
}