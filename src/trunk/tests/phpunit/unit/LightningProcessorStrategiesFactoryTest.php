<?php

namespace PayCryptoMe\WooCommerce {
    // All strategy/processor classes are loaded via Composer autoloader
}

namespace {

use PHPUnit\Framework\TestCase;
use PayCryptoMe\WooCommerce\LightningProcessorStrategiesFactory;
use PayCryptoMe\WooCommerce\BtcpayLightningProcessor;
use PayCryptoMe\WooCommerce\LndRestLightningProcessor;

class LightningProcessorStrategiesFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        // wpdb stub needed by PayCryptoMeLightningDBStatementsService constructor
        if (!isset($GLOBALS['wpdb'])) {
            $GLOBALS['wpdb'] = new class {
                public string $prefix = 'wp_';
            };
        }
    }

    private function make_gateway(array $opts): \WC_Payment_Gateway
    {
        return new class($opts) extends \WC_Payment_Gateway {
            private array $opts;
            public function __construct(array $opts) { $this->opts = $opts; }
            public function get_option($key, $empty_value = null) { return $this->opts[$key] ?? $empty_value; }
        };
    }

    public function test_btcpay_node_type_returns_btcpay_processor(): void
    {
        $gateway   = $this->make_gateway(['node_type' => 'btcpay']);
        $processor = LightningProcessorStrategiesFactory::create($gateway);

        $this->assertInstanceOf(BtcpayLightningProcessor::class, $processor);
    }

    public function test_lnd_rest_node_type_returns_lnd_rest_processor(): void
    {
        $gateway   = $this->make_gateway(['node_type' => 'lnd_rest']);
        $processor = LightningProcessorStrategiesFactory::create($gateway);

        $this->assertInstanceOf(LndRestLightningProcessor::class, $processor);
    }

    public function test_missing_node_type_defaults_to_btcpay_processor(): void
    {
        $gateway   = $this->make_gateway([]);
        $processor = LightningProcessorStrategiesFactory::create($gateway);

        $this->assertInstanceOf(BtcpayLightningProcessor::class, $processor);
    }
}

} // end global namespace
