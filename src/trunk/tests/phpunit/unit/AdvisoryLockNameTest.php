<?php

use PayCryptoMe\WooCommerce\AdvisoryLockName;
use PHPUnit\Framework\TestCase;

final class AdvisoryLockNameTest extends TestCase
{
    private $previous;

    protected function setUp(): void
    {
        global $wpdb;
        $this->previous = $wpdb;
        $wpdb = new class {
            public $prefix = 'wp_';
            public $identity = ['db_name' => 'shop', 'case_mode' => '0'];
            public function get_row($query, $output)
            {
                return $this->identity;
            }
        };
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = $this->previous;
    }

    public function test_fixed_vectors_and_physical_resource_boundaries(): void
    {
        global $wpdb;
        $table = 'wp_paycrypto_me_bitcoin_derivation_indexes';
        $name = AdvisoryLockName::wallet($table, 1);
        $this->assertSame('pcm_w_6060cdb6b1dd7cfed51e92aae2efe0fc798c9c21f060904c3580708e', $name);
        $this->assertSame('pcm_i_cdc8df7d28ddc2e217f64435f01209ae03a01a267f4c4833e2f862e8', AdvisoryLockName::install());
        $this->assertNotSame($name, AdvisoryLockName::wallet($table, 11));
        $this->assertNotSame($name, AdvisoryLockName::wallet(strtoupper($table), 1));
        foreach (['1', '2'] as $mode) {
            $wpdb->identity['case_mode'] = $mode;
            $this->assertSame($name, AdvisoryLockName::wallet(strtoupper($table), 1));
        }
        $wpdb->identity['db_name'] = 'shop2';
        $this->assertNotSame($name, AdvisoryLockName::wallet($table, 1));
        $first_install = AdvisoryLockName::install();
        $wpdb->prefix = 'wp_2_';
        $this->assertNotSame($first_install, AdvisoryLockName::install());
        $this->assertSame(62, strlen(AdvisoryLockName::wallet(str_repeat('x', 64), PHP_INT_MAX)));
    }

    public function test_database_changes_the_resource(): void
    {
        $first = AdvisoryLockName::install();
        $GLOBALS['wpdb']->identity['db_name'] = 'other';
        $this->assertNotSame($first, AdvisoryLockName::install());
    }

    public function test_wallet_changes_the_resource(): void
    {
        $this->assertNotSame(AdvisoryLockName::wallet('same_table', 1), AdvisoryLockName::wallet('same_table', 2));
    }

    /** @dataProvider invalid_identities */
    public function test_invalid_identity_stops_before_locking($identity): void
    {
        $GLOBALS['wpdb']->identity = $identity;
        $this->expectException(RuntimeException::class);
        AdvisoryLockName::install();
    }

    public function invalid_identities(): array
    {
        return [[null], [['db_name' => '', 'case_mode' => '0']], [['db_name' => 'shop', 'case_mode' => '3']]];
    }
}
