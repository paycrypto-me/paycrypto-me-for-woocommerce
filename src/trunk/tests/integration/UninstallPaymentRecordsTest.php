<?php

use PayCryptoMe\WooCommerce\DbInstaller;
use PayCryptoMe\WooCommerce\PayCryptoMeDBStatementsService;
use PayCryptoMe\WooCommerce\UninstallCleanup;

final class UninstallPaymentRecordsTest extends SchemaTestCase
{
    public function test_removes_base_fields_from_both_stores_and_can_be_retried(): void
    {
        global $wpdb;
        $prefix = $this->fresh_install();
        $this->with_prefix($prefix, function () use ($wpdb, $prefix) {
            $original_postmeta = $wpdb->postmeta;
            $tables = [$prefix . 'postmeta', $prefix . 'wc_orders_meta'];
            $wpdb->postmeta = $tables[0];
            try {
                foreach ($tables as $table) {
                    $this->assertNotFalse($wpdb->query($wpdb->prepare('CREATE TABLE %i (meta_key varchar(191))', $table)));
                    // Cross the batch boundary and keep two near-matches, including
                    // one that a default case-insensitive MySQL comparison would erase.
                    $this->assertNotFalse($wpdb->query($wpdb->prepare(
                        'INSERT INTO %i VALUES ' . implode(',', array_fill(0, 1001, "('_paycrypto_me_payment_uri')"))
                        . ",('_paycrypto_me_pro_receipt'),('_PAYCRYPTO_ME_payment_uri')",
                        $table
                    )));
                }
                $db = new PayCryptoMeDBStatementsService();
                $wallet = $db->insert_wallet_xpubkey('qa-wallet', 'mainnet');
                $this->assertSame(0, $db->reserve_derivation_index_for_wallet($wallet));
                DbInstaller::activate();
                $this->assertSame(1, $db->reserve_derivation_index_for_wallet($wallet));

                // Exercise only this stage: the integration suite shares its WP
                // options and upload directory with the developer's store.
                $remove = new ReflectionMethod(UninstallCleanup::class, 'remove_payment_records');
                $remove->invoke(null);
                $remove->invoke(null);
                foreach ($tables as $table) {
                    $this->assertEqualsCanonicalizing(
                        ['_paycrypto_me_pro_receipt', '_PAYCRYPTO_ME_payment_uri'],
                        $wpdb->get_col($wpdb->prepare('SELECT meta_key FROM %i', $table))
                    );
                }
                $this->assertFalse(get_option(DbInstaller::VERSION_OPTION));
                foreach (DbInstaller::tables() as $bare) {
                    $this->assertNull($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($prefix . $bare))));
                }
                DbInstaller::activate();
                $this->assertSame(DbInstaller::DB_VERSION, get_option(DbInstaller::VERSION_OPTION));
                $this->assertNull($db->get_wallet_xpubkey_id('qa-wallet', 'mainnet'));
            } finally {
                $wpdb->postmeta = $original_postmeta;
                foreach ($tables as $table) {
                    $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $table));
                }
            }
        });
    }
}
