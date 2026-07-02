<?php

namespace PayCryptoMe\WooCommerce {
    // All strategy/processor classes are loaded via Composer autoloader
}

namespace {

use PHPUnit\Framework\TestCase;
use PayCryptoMe\WooCommerce\ProcessorStrategiesFactory;
use PayCryptoMe\WooCommerce\BitcoinPaymentProcessor;
use PayCryptoMe\WooCommerce\BtcpayLightningProcessor;

class ProcessorStrategiesFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        // wpdb stub needed by the Bitcoin/Lightning processors' DB service constructors
        if (!isset($GLOBALS['wpdb'])) {
            $GLOBALS['wpdb'] = new class {
                public string $prefix = 'wp_';
            };
        }
    }

    private function make_gateway(string $id): \WC_Payment_Gateway
    {
        $gateway = new class extends \WC_Payment_Gateway {
            public function get_option($key, $empty_value = null) { return $empty_value; }
        };
        $gateway->id = $id;

        return $gateway;
    }

    public function test_unknown_gateway_id_throws_invalid_argument_exception(): void
    {
        $gateway = $this->make_gateway('some_unrelated_gateway');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("There isn't any processor strategy for gateway ID: some_unrelated_gateway");

        ProcessorStrategiesFactory::create($gateway);
    }

    public function test_bitcoin_gateway_id_dispatches_to_bitcoin_processor(): void
    {
        $gateway = $this->make_gateway('paycrypto_me');

        $processor = ProcessorStrategiesFactory::create($gateway);

        $this->assertInstanceOf(BitcoinPaymentProcessor::class, $processor);
    }

    public function test_lightning_gateway_id_dispatches_to_lightning_processor(): void
    {
        $gateway = $this->make_gateway('paycrypto_me_lightning');

        $processor = ProcessorStrategiesFactory::create($gateway);

        $this->assertInstanceOf(BtcpayLightningProcessor::class, $processor);
    }
}

} // end global namespace
