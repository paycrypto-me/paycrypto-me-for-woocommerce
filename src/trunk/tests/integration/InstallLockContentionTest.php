<?php

use PayCryptoMe\WooCommerce\DbInstaller;
use PayCryptoMe\WooCommerce\AdvisoryLockName;
use PayCryptoMe\WooCommerce\PayCryptoMeDBStatementsService;
use PayCryptoMe\WooCommerce\PayCryptoMeBitcoinGatewayActivate;

/**
 * DbInstallerTest (unit) proves install()'s lock logic against a mocked $wpdb that always answers
 * GET_LOCK/RELEASE_LOCK however the test tells it to — it cannot prove the lock actually excludes a
 * SEPARATE connection, because a mock has no connections. This test opens a real second MySQL
 * connection (bypassing $wpdb entirely) and holds the advisory lock from there, so install() has to
 * contend with genuine cross-connection mutual exclusion, the same as two concurrent PHP-FPM
 * workers would.
 */
class InstallLockContentionTest extends SchemaTestCase
{
    public function test_install_is_refused_while_a_separate_connection_holds_the_lock()
    {
        $prefix = $this->reserve_prefix();
        $other  = $this->open_second_connection();

        $name = $this->with_prefix($prefix, fn() => AdvisoryLockName::install());
        $got = $other->query("SELECT GET_LOCK('" . $name . "', 5)")->fetch_row();
        $this->assertSame('1', $got[0], 'Test setup: the second connection must actually hold the lock');

        try {
            $installed = $this->with_prefix($prefix, fn(): bool => DbInstaller::install());

            $this->assertFalse($installed, 'install() must refuse to run while another connection holds the advisory lock');
            $this->assertSame(
                [],
                get_option(DbInstaller::ERRORS_OPTION, []),
                'Losing the lock race is not a failure — no error should be recorded, and no admin notice should fire'
            );

            global $wpdb;
            $this->assertSame(
                [],
                (array) $wpdb->get_col("SHOW TABLES LIKE '{$prefix}%'"),
                'Losing the lock race must not leave any table behind'
            );
        } finally {
            $other->query("SELECT RELEASE_LOCK('" . $name . "')");
            $other->close();
        }

        // The lock is free now — the same prefix installs cleanly on retry, proving this was
        // specifically the OTHER connection's lock being respected, not some unrelated failure.
        $installed_after_release = $this->with_prefix($prefix, fn(): bool => DbInstaller::install());
        $this->assertTrue($installed_after_release);
    }

    public function test_wallet_timeout_preserves_the_next_index_and_other_wallets_progress()
    {
        $prefix = $this->fresh_install();
        $other = $this->open_second_connection();
        $this->with_prefix($prefix, function () use ($prefix, $other) {
            $db = new PayCryptoMeDBStatementsService();
            $table = $prefix . PayCryptoMeBitcoinGatewayActivate::TABLE_DERIVATION_INDEXES;
            $name = AdvisoryLockName::wallet($table, 1);
            try {
                $this->assertSame('1', $other->query("SELECT GET_LOCK('$name', 0)")->fetch_row()[0]);
                $this->assertSame(0, $db->reserve_derivation_index_for_wallet(2, 0));
                try {
                    $db->reserve_derivation_index_for_wallet(1, 0);
                    $this->fail('Reservation should time out on its own wallet.');
                } catch (\RuntimeException $error) {
                    $this->assertSame('Could not obtain DB lock for wallet.', $error->getMessage());
                }
            } finally {
                $other->close();
            }
            $this->assertSame(0, $db->reserve_derivation_index_for_wallet(1, 0));
        });
    }

    public function test_reservation_write_error_frees_the_lock_for_another_session(): void
    {
        global $wpdb;
        $prefix = $this->fresh_install();
        $table = $prefix . PayCryptoMeBitcoinGatewayActivate::TABLE_DERIVATION_INDEXES;
        $this->assertNotFalse($wpdb->query("ALTER TABLE `$table` ADD CHECK (derivation_index > 0)"));
        $this->with_prefix($prefix, function () use ($table, $wpdb) {
            $service = new PayCryptoMeDBStatementsService();
            $name = AdvisoryLockName::wallet($table, 1);
            $old = $wpdb->suppress_errors(true);
            try {
                $service->reserve_derivation_index_for_wallet(1, 0);
                $this->fail('The disposable table constraint should reject index zero.');
            } catch (\RuntimeException $error) {
                $this->assertSame('Failed to insert derivation index.', $error->getMessage());
            } finally {
                $wpdb->suppress_errors($old);
            }
            $other = $this->open_second_connection();
            try {
                $this->assertSame('1', $other->query("SELECT GET_LOCK('$name', 0)")->fetch_row()[0]);
            } finally {
                $other->close();
            }
            $this->assertSame('0', $wpdb->get_var("SELECT COUNT(*) FROM `$table`"));
        });
    }

    private function open_second_connection(): \mysqli
    {
        $host = DB_HOST;
        $port = 3306;

        if (str_contains($host, ':')) {
            [$host, $port_string] = explode(':', $host, 2);
            $port = (int) $port_string;
        }

        $connection = new \mysqli($host, DB_USER, DB_PASSWORD, DB_NAME, $port);

        $this->assertSame(0, $connection->connect_errno, "Could not open a second MySQL connection: {$connection->connect_error}");

        return $connection;
    }
}
