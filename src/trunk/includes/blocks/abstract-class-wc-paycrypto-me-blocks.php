<?php

namespace PayCryptoMe\WooCommerce;

defined('ABSPATH') || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

abstract class WC_PayCryptoMe_Blocks extends AbstractPaymentMethodType
{
    protected $gateway;

    public function initialize()
    {
        $gateways = WC()->payment_gateways->payment_gateways();
        $this->gateway = $gateways[$this->name] ?? null;
    }

    public function is_active()
    {
        if (!function_exists('WC') || !WC()->payment_gateways) {
            return false;
        }

        if (!$this->gateway) {
            return false;
        }

        return $this->gateway->is_available();
    }

    public function get_payment_method_script_handles()
    {
        $assets = $this->register_blocks_assets();

        $version = $assets['version'] ?? WC_PayCryptoMe::VERSION;

        $dependencies = $assets['dependencies'] ?? ['wp-blocks', 'wp-element', 'wp-i18n'];

        $this->register_blocks_translations();

        $this->register_blocks_styles($version);

        $registered_scripts = $this->register_blocks_scripts($dependencies, $version);

        return $registered_scripts;
    }

    public function get_payment_method_data()
    {
        return $this->gateway ? $this->gateway->get_payment_method_data() : [];
    }

    public function get_setting($name, $default = '')
    {
        return isset($this->settings[$name]) ? $this->settings[$name] : $default;
    }

    //

    private function register_blocks_translations()
    {
        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations(
                $this->name . "-blocks",
                'paycrypto-me-for-woocommerce',
                WC_PayCryptoMe::plugin_abspath() . '/languages'
            );
        }
    }

    private function register_blocks_styles($version = false)
    {
        $style_path = WC_PayCryptoMe::plugin_abspath() . "/assets/blocks/{$this->name}-blocks-style.css";

        if (!file_exists($style_path)) {
            return;
        }

        $style_url = WC_PayCryptoMe::plugin_url() . "/assets/blocks/{$this->name}-blocks-style.css" . ($version ? "?v=$version" : '');

        wp_register_style(
            $this->name . '-blocks-style',
            $style_url,
            [],
            $version
        );
    }
    private function register_blocks_scripts($dependencies = [], $version = false)
    {
        $script_path = WC_PayCryptoMe::plugin_abspath() . "/assets/blocks/{$this->name}-blocks-script.js";

        if (!file_exists($script_path)) {
            return;
        }

        $script_url = WC_PayCryptoMe::plugin_url() . "/assets/blocks/{$this->name}-blocks-script.js" . ($version ? "?v=$version" : '');

        wp_register_script(
            $this->name . "-blocks",
            $script_url,
            $dependencies,
            $version,
            true
        );

        return [$this->name . "-blocks"];
    }

    private function register_blocks_assets()
    {
        $assets_path = WC_PayCryptoMe::plugin_abspath() . "/assets/blocks/{$this->name}-blocks.asset.php";

        if (!file_exists($assets_path)) {
            return;
        }

        $script_asset = file_exists($assets_path)
            ? require($assets_path)
            : [
                'dependencies' => ['wc-blocks-checkout'],
                'version' => '1.0.0'
            ];

        return $script_asset;
    }
}