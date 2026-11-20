<?php
/** @package WooCommerce\PayCryptoMe */

namespace PayCryptoMe\WooCommerce;

defined('ABSPATH') || exit;

final class UninstallCleanup
{
    // Exact base fields written by PaymentProcessor and its two strategies. Extensions
    // can add fields to payment_data and retain ownership of those fields.
    private const META_FIELDS = [
        'crypto_amount', 'crypto_currency', 'crypto_network', 'fiat_amount', 'fiat_currency',
        'payment_expires_at', 'payment_expires_ts', 'payment_number_confirmations',
        'payment_address', 'payment_uri', 'derivation_index', 'payment_request', 'lightning_invoice_id',
    ];

    public static function run(): void
    {
        if (!defined('WP_UNINSTALL_PLUGIN')) {
            return;
        }
        $sites = is_multisite() ? get_sites(['fields' => 'ids', 'number' => 0]) : [get_current_blog_id()];
        $protected_dirs = [];
        // Older WooCommerce versions define WC_LOG_DIR at bootstrap, before
        // switch_to_blog(). Only carry a genuinely custom path across sites.
        $legacy_dir = null;
        if (defined('WC_LOG_DIR') && !method_exists(\Automattic\WooCommerce\Utilities\LoggingUtil::class, 'get_log_directory')) {
            $default = wp_upload_dir(null, false)['basedir'] . '/wc-logs';
            if (!defined('WC_VERSION') || untrailingslashit(WC_LOG_DIR) !== $default) {
                $legacy_dir = WC_LOG_DIR;
            }
        }
        // A network may route several sites to the same directory. Its filenames
        // carry no site ID, so deleting a source there cannot isolate one store.
        if (count($sites) > 1) {
            foreach ($sites as $site) {
                switch_to_blog($site);
                try {
                    $dir = realpath(self::log_directory($legacy_dir));
                    if ($dir !== false && !UninstallPreferences::read()['logs']) {
                        $protected_dirs[$dir] = true;
                    }
                } finally {
                    restore_current_blog();
                }
            }
        }

        $failed = [];
        foreach ($sites as $site) {
            if (is_multisite()) {
                switch_to_blog($site);
            }
            try {
                self::clean_site($protected_dirs, $legacy_dir);
            } catch (\RuntimeException $error) {
                $failed[] = sprintf('Site %d: %s', $site, $error->getMessage());
            } finally {
                if (is_multisite()) {
                    restore_current_blog();
                }
            }
        }
        if ($failed) {
            $message = 'PayCrypto.Me uninstall incomplete. Correct the problem and retry deletion. ' . implode(' ', $failed);
            if (wp_doing_ajax()) {
                wp_send_json_error(['delete' => 'plugin', 'slug' => 'paycrypto-me-for-woocommerce', 'plugin' => WP_UNINSTALL_PLUGIN, 'errorMessage' => $message]);
            }
            wp_die(esc_html($message));
        }
    }

    private static function clean_site(array $protected_dirs, ?string $legacy_dir): void
    {
        $choices = UninstallPreferences::read();
        foreach (['woocommerce_paycrypto_me_settings', 'woocommerce_paycrypto_me_lightning_settings', DbInstaller::ERRORS_OPTION] as $key) {
            self::remove_option($key);
        }
        delete_transient(DbInstaller::RETRY_TRANSIENT);
        delete_transient(DbInstaller::HEALTH_TRANSIENT);

        if ($choices['logs']) {
            self::remove_logs($protected_dirs, self::log_directory($legacy_dir));
        }
        if ($choices['payments']) {
            self::remove_payment_records();
        }
        self::remove_option(UninstallPreferences::OPTION);
    }

    private static function remove_option(string $key): void
    {
        if (!delete_option($key) && get_option($key, null) !== null) {
            throw new \RuntimeException('Could not delete option ' . esc_html($key));
        }
    }

    private static function log_directory(?string $legacy_dir): string
    {
        if ($legacy_dir !== null) {
            return $legacy_dir;
        }
        if (method_exists(\Automattic\WooCommerce\Utilities\LoggingUtil::class, 'get_log_directory')) {
            return \Automattic\WooCommerce\Utilities\LoggingUtil::get_log_directory(false);
        }
        $uploads = wp_upload_dir(null, false);
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Honor WooCommerce’s own directory setting when it is inactive.
        return apply_filters('woocommerce_log_directory', $uploads['basedir'] . '/wc-logs/');
    }

    private static function remove_logs(array $protected_dirs, string $dir): void
    {
        global $wpdb;
        if (is_dir($dir)) {
            // Both native WC file handlers use source[.rotation]-date-hash.log.
            // Do not glob by prefix: paycrypto_me-other is a different source.
            foreach (new \DirectoryIterator($dir) as $file) {
                if (!$file->isFile() || $file->isLink()
                    || !preg_match('/^paycrypto_me(?:\.\d+)?-\d{4}-\d{2}-\d{2}-[a-f0-9]{32}\.log$/D', $file->getFilename())) {
                    continue;
                }
                if (isset($protected_dirs[realpath($dir)])) {
                    throw new \RuntimeException('This log directory is shared with a site that retained its logs. Separate the logs before retrying.');
                }
                wp_delete_file($file->getPathname());
                if (file_exists($file->getPathname())) {
                    throw new \RuntimeException('Could not delete a WooCommerce log file.');
                }
            }
        }
        $table = $wpdb->prefix . 'woocommerce_log';
        if (self::table_exists($table)) {
            self::delete_matching_rows($table, 'source', 'paycrypto_me');
        }
    }

    private static function table_exists(string $table): bool
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall must inspect actual schema, including partially removed tables.
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if ($wpdb->last_error !== '') {
            throw new \RuntimeException('Could not inspect the database tables.');
        }
        return $found === $table;
    }

    private static function remove_payment_records(): void
    {
        global $wpdb;
        try {
            foreach ([$wpdb->postmeta, $wpdb->prefix . 'wc_orders_meta'] as $table) {
                if (self::table_exists($table)) {
                    foreach (self::META_FIELDS as $field) {
                        self::delete_matching_rows($table, 'meta_key', '_paycrypto_me_' . $field);
                    }
                }
            }
        } finally {
            // Direct deletes cover both order stores with WooCommerce inactive.
            // Invalidate cached orders, metadata and wallet IDs even on partial failure.
            wp_cache_flush();
        }

        $tables = array_map(static fn($name) => $wpdb->prefix . $name, DbInstaller::tables());
        $identifiers = implode(', ', array_fill(0, count($tables), '%i'));
        // The placeholder list contains only %i tokens. Drop the four related tables together.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Explicitly authorized uninstall DDL; each identifier is individually prepared.
        if ($wpdb->query($wpdb->prepare("DROP TABLE IF EXISTS $identifiers", ...$tables)) === false) {
            throw new \RuntimeException('Could not remove the payment tables.');
        }
        self::remove_option(DbInstaller::VERSION_OPTION);
    }

    private static function delete_matching_rows(string $table, string $column, string $value): void
    {
        global $wpdb;
        do {
            // Compare exactly under case-insensitive store collations. Limit each write and allow retry.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall deletes from native storage even with WooCommerce inactive.
            $rows = $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE %i = BINARY %s LIMIT 1000', $table, $column, $value));
            if ($rows === false) {
                throw new \RuntimeException('Could not delete payment metadata or WooCommerce log entries.');
            }
        } while ($rows === 1000);
    }
}
