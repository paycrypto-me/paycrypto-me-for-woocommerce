<?php
/** @package WooCommerce\PayCryptoMe */

defined('WP_UNINSTALL_PLUGIN') || exit;

// WordPress runs uninstall.php with the plugin inactive. Neither WooCommerce
// nor the Composer bootstrap is required to remove settings and payment data.
require_once __DIR__ . '/includes/services/class-uninstall-preferences.php';
require_once __DIR__ . '/includes/services/class-uninstall-cleanup.php';
require_once __DIR__ . '/includes/services/class-db-installer.php';
require_once __DIR__ . '/includes/services/class-paycrypto-me-bitcoin-gateway-activate.php';
require_once __DIR__ . '/includes/services/class-paycrypto-me-lightning-gateway-activate.php';

\PayCryptoMe\WooCommerce\UninstallCleanup::run();
