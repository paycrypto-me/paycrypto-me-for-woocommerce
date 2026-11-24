<?php
defined('ABSPATH') || exit;

// A separate override path keeps legacy active-payment theme templates off closed orders.
// Load the bundled layout directly. WooCommerce already resolved any summary override.
require \PayCryptoMe\WooCommerce\WC_PayCryptoMe::plugin_abspath() . 'templates/order-details/paycrypto-me-order-details.php';
