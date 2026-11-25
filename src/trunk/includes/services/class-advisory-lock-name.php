<?php
/**
 * Physical resource identities for the plugin's MySQL advisory locks.
 *
 * @package WooCommerce\PayCryptoMe
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

/** @internal The v1 identity must remain stable across schema versions. */
final class AdvisoryLockName
{
    public static function install(): string
    {
        global $wpdb;

        return self::build('i', $wpdb->prefix . PayCryptoMeBitcoinGatewayActivate::TABLE_WALLETS);
    }

    public static function wallet(string $indexes_table, int $wallet_id): string
    {
        return self::build('w', $indexes_table, $wallet_id);
    }

    private static function build(string $kind, string $table, ?int $wallet_id = null): string
    {
        global $wpdb;

        // Let the server lowercase the database name (which may contain non-ASCII
        // characters). WordPress table prefixes and our table suffixes are ASCII.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The current database and identifier rules belong to the live connection.
        $identity = $wpdb->get_row(
            'SELECT @@lower_case_table_names AS case_mode, CASE WHEN @@lower_case_table_names = 0 THEN DATABASE() ELSE LOWER(DATABASE()) END AS db_name',
            ARRAY_A
        );

        $mode = (string) ($identity['case_mode'] ?? '');
        $database = $identity['db_name'] ?? null;
        if (!is_string($database) || $database === '' || !in_array($mode, ['0', '1', '2'], true)) {
            throw new \RuntimeException('Could not identify the database for the payment table lock.');
        }

        $parts = ['v1', $kind, $database, $mode === '0' ? $table : strtolower($table)];
        if ($wallet_id !== null) {
            $parts[] = (string) $wallet_id;
        }

        return 'pcm_' . $kind . '_' . substr(hash('sha256', implode("\0", $parts)), 0, 56);
    }
}
