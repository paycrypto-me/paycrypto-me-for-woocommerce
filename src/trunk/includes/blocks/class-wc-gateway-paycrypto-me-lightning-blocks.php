<?php

namespace PayCryptoMe\WooCommerce;

defined('ABSPATH') || exit;

final class WC_Gateway_PayCryptoMe_Lightning_Blocks extends WC_PayCryptoMe_Blocks
{
    protected $name = 'paycrypto_me_lightning';
}

add_action(
    'woocommerce_blocks_payment_method_type_registration',
    function ($payment_method_registry) {
        $payment_method_registry->register(
            new \PayCryptoMe\WooCommerce\WC_Gateway_PayCryptoMe_Lightning_Blocks()
        );
    }
);