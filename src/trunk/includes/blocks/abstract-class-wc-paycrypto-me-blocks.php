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
        \PayCryptoMe\WooCommerce\AssetManager::register_block_assets($this->name);
        return \PayCryptoMe\WooCommerce\AssetManager::get_block_handles($this->name);
    }

    public function get_payment_method_data()
    {
        return $this->gateway ? $this->gateway->get_payment_method_data() : [];
    }

    public function get_setting($name, $default = '')
    {
        return isset($this->settings[$name]) ? $this->settings[$name] : $default;
    }
}