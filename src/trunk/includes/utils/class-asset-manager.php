<?php

namespace PayCryptoMe\WooCommerce;

defined('ABSPATH') || exit;

class AssetManager
{
    public static function get_plugin_abspath()
    {
        return WC_PayCryptoMe::plugin_abspath();
    }

    public static function get_plugin_url()
    {
        return WC_PayCryptoMe::plugin_url();
    }

    public static function get_asset_data($slug)
    {
        $assets_path = self::get_plugin_abspath() . "/assets/blocks/{$slug}-blocks.asset.php";

        if (file_exists($assets_path)) {
            $data = require $assets_path;
            if (is_array($data)) {
                return $data;
            }
        }

        return [
            'dependencies' => [],
            'version' => WC_PayCryptoMe::VERSION,
        ];
    }

    public static function register_block_assets($slug)
    {
        $handles = [];
        $asset = self::get_asset_data($slug);
        $version = isset($asset['version']) ? $asset['version'] : WC_PayCryptoMe::VERSION;
        $script_deps = isset($asset['dependencies']) ? $asset['dependencies'] : [];

        $script_file = "{$slug}-blocks.js";
        $script_path = self::get_plugin_abspath() . "/assets/blocks/{$script_file}";
        if (file_exists($script_path)) {
            $handle = $slug . '-blocks';
            $url = self::get_plugin_url() . "/assets/blocks/{$script_file}";
            wp_register_script($handle, $url, $script_deps, $version, true);

            if (function_exists('wp_set_script_translations')) {
                wp_set_script_translations($handle, 'paycrypto-me-for-woocommerce', self::get_plugin_abspath() . '/languages');
            }

            $handles[] = $handle;
        }

        $style_file = "{$slug}-blocks.css";
        $style_path = self::get_plugin_abspath() . "/assets/blocks/{$style_file}";
        if (file_exists($style_path)) {
            $style_handle = $slug . '-blocks-style';
            $style_url = self::get_plugin_url() . "/assets/blocks/{$style_file}";
            wp_register_style($style_handle, $style_url, [], $version);
            $handles[] = $style_handle;
        }

        return $handles;
    }

    public static function get_block_handles($slug)
    {
        $handles = [];

        $script_handle = $slug . '-blocks';
        if (wp_script_is($script_handle, 'registered') || wp_script_is($script_handle, 'enqueued')) {
            $handles[] = $script_handle;
        }

        // Do not return style handles here. WooCommerce expects this method to
        // return script handles only; returning style handles causes the
        // returned handles to be treated as script dependencies and leads to
        // warnings about missing script registrations.

        return $handles;
    }

    public static function get_block_style_handles($slug)
    {
        $handles = [];

        $style_handle = $slug . '-blocks-style';
        if (wp_style_is($style_handle, 'registered') || wp_style_is($style_handle, 'enqueued')) {
            $handles[] = $style_handle;
        }

        return $handles;
    }
}
