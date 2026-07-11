<?php
use PHPUnit\Framework\TestCase;
use PayCryptoMe\WooCommerce\PayCryptoMeDBStatementsService;

// hook_spy_*()/esc_sql()/wp_cache_*() fallbacks live in tests/_support/wp-helpers.php
// and tests/_support/paycryptome-shims.php.

class FakeWPDBBitcoinTransactions
{
    public $prefix = 'wp_';
    public array $rows = [];

    public function prepare($query, ...$args)
    {
        $i = 0;
        return preg_replace_callback('/%[ds]/', function () use (&$i, $args) {
            $value = $args[$i++];
            return is_string($value) ? "'" . $value . "'" : (string) $value;
        }, $query);
    }

    public function get_var($query)
    {
        if (preg_match("/SELECT num_confirmations FROM .* WHERE order_id = '?(\d+)'?/", $query, $m)) {
            $order_id = (int) $m[1];
            return $this->rows[$order_id]['num_confirmations'] ?? null;
        }

        return null;
    }

    public function update($table, $data, $where, $format = null, $where_format = null)
    {
        $order_id = $where['order_id'];
        if (!isset($this->rows[$order_id])) {
            return false;
        }
        $this->rows[$order_id] = array_merge($this->rows[$order_id], $data);
        return 1;
    }
}

class OnchainConfirmationsUpdateTest extends TestCase
{
    protected function setUp(): void
    {
        global $wpdb;
        $wpdb = new FakeWPDBBitcoinTransactions();
        $GLOBALS['__wp_cache_store'] = [];
        hook_spy_reset();
    }

    private function seed_transaction(int $order_id, int $confirmations = 0): void
    {
        global $wpdb;
        $wpdb->rows[$order_id] = [
            'order_id'          => $order_id,
            'num_confirmations' => $confirmations,
            'amount_received'   => null,
            'tx_hash'           => null,
        ];
    }

    public function test_update_persists_confirmations_amount_and_txhash_and_returns_true()
    {
        global $wpdb;
        $this->seed_transaction(42, 0);
        $svc = new PayCryptoMeDBStatementsService();

        $result = $svc->update_transaction_confirmations(42, 3, '0.00125000', 'abcd1234');

        $this->assertTrue($result);
        $this->assertSame(3, $wpdb->rows[42]['num_confirmations']);
        $this->assertSame('0.00125000', $wpdb->rows[42]['amount_received']);
        $this->assertSame('abcd1234', $wpdb->rows[42]['tx_hash']);
    }

    public function test_update_fires_status_changed_action_with_old_and_new_confirmations()
    {
        $this->seed_transaction(7, 0);
        $svc = new PayCryptoMeDBStatementsService();

        $svc->update_transaction_confirmations(7, 2, '0.005', 'tx_7');

        $calls = hook_spy_calls('paycryptome_bitcoin_status_changed');
        $this->assertCount(1, $calls);
        $this->assertSame([7, 0, 2], $calls[0]['args']);
    }

    public function test_update_does_not_fire_action_when_confirmations_unchanged()
    {
        $this->seed_transaction(9, 2);
        $svc = new PayCryptoMeDBStatementsService();

        $svc->update_transaction_confirmations(9, 2, '0.005', 'tx_9');

        $this->assertCount(0, hook_spy_calls('paycryptome_bitcoin_status_changed'));
    }

    public function test_update_returns_false_and_fires_no_action_when_order_missing()
    {
        $svc = new PayCryptoMeDBStatementsService();

        $result = $svc->update_transaction_confirmations(999, 3, '0.01', 'tx_x');

        $this->assertFalse($result);
        $this->assertCount(0, hook_spy_calls('paycryptome_bitcoin_status_changed'));
    }
}
