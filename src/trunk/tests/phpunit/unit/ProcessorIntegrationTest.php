<?php

namespace PayCryptoMe\WooCommerce {
    // Service classes are loaded via the Composer autoloader; this test replaces
    // the processor's private properties with anonymous subclasses (see below)
    // rather than faking these classes at the namespace level.
}

namespace {

use PHPUnit\Framework\TestCase;

// __/esc_html/get_bloginfo/WC_Payment_Gateway/WC_Order fallbacks live in
// tests/_support/wp-helpers.php (loaded by bootstrap.php before any test file).

require_once __DIR__ . '/../../../includes/processors/class-bitcoin-payment-processor.php';

class ProcessorIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        // no-op; stubs are declared at file scope
    }

    public function test_processor_generates_and_returns_payment_data()
    {
        $gateway = new class extends \WC_Payment_Gateway {
            private $opts = [
                'network_identifier' => 'xpub_test',
                'selected_network' => 'testnet',
            ];

            public function get_option($key, $empty_value = null) { return $this->opts[$key] ?? $empty_value; }
            public function register_paycrypto_me_log($msg, $level = 'info') { }
        };

        $order = new class extends \WC_Order {
            public function __construct() {}
            public function get_id() { return 9001; }
            public function get_billing_first_name($context = 'view') { return 'Alice'; }
        };

        $processorClass = '\\PayCryptoMe\\WooCommerce\\BitcoinPaymentProcessor';
        $processor = new $processorClass($gateway);

        // Replace private services with test stubs via reflection to avoid dependencies
        $rp = new \ReflectionObject($processor);
        $prop = $rp->getProperty('bitcoin_address_service');
        $prop->setAccessible(true);
        $addrStub = new class extends \PayCryptoMe\WooCommerce\BitcoinAddressService {
            public function validate_extended_pubkey(string $xPub, \BitWasp\Bitcoin\Network\NetworkInterface $network, ?callable $logger = null): bool { return true; }
            public function generate_address_from_xPub(string $xPub, int $index, \BitWasp\Bitcoin\Network\NetworkInterface $bitcoin_network, ?string $forceType = null, ?callable $logger = null): string { return 'btc:1TestAddress' . (string)$index; }
            public function build_bitcoin_payment_uri(string $address, ?float $amount = null, ?string $label = null, ?string $message = null): string { return 'bitcoin:1TestAddress?amount=0.001'; }
        };
        $prop->setValue($processor, $addrStub);

        $propDb = $rp->getProperty('db');
        $propDb->setAccessible(true);
        $dbStub = new class extends \PayCryptoMe\WooCommerce\PayCryptoMeDBStatementsService {
            public function get_by_order_id(int $order_id): ?array { return null; }
            public function get_wallet_xpubkey_id(string $xpub, string $network): ?int { return null; }
            public function insert_wallet_xpubkey(string $xpub, string $network): int { return 1; }
            public function reserve_derivation_index_for_wallet(int $wallet_xpubkeys_id, int $lock_timeout = 10) { return 0; }
            public function insert_address(int $order_id, int $derivation_index, string $payment_address, int $wallet_xpub_id): bool { return true; }
        };
        $propDb->setValue($processor, $dbStub);

        $payment_data = ['crypto_amount' => 0.001];

        $result = $processor->process($order, $payment_data);

        $this->assertArrayHasKey('payment_address', $result);
        $this->assertArrayHasKey('derivation_index', $result);
        $this->assertArrayHasKey('payment_uri', $result);
        $this->assertSame('btc:1TestAddress0', $result['payment_address']);
        $this->assertSame(0, $result['derivation_index']);
    }
}

}
