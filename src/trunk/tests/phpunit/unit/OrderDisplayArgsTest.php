<?php
use PHPUnit\Framework\TestCase;
use PayCryptoMe\WooCommerce\WC_Gateway_PayCryptoMe;
use PayCryptoMe\WooCommerce\WC_Gateway_PayCryptoMe_Lightning;

// Characterization tests for the per-gateway display args that feed
// PaymentDisplayDataBuilder::build(). Captures the exact values each gateway
// contributed before the render_*_order_details_section() methods were moved
// up into the abstract base (audit Fase 3+, item DRY entre gateways).

class OrderDisplayArgsTest extends TestCase
{
    private function make_order(array $meta = [], string $payment_method = ''): \WC_Order
    {
        $order = $this->createMock(\WC_Order::class);
        $order->method('get_meta')->willReturnCallback(
            fn($key, $single = true, $context = 'view') => $meta[$key] ?? ''
        );
        $order->method('get_payment_method')->willReturn($payment_method);

        return $order;
    }

    private function make_bitcoin_gateway(): WC_Gateway_PayCryptoMe
    {
        $gateway = $this->getMockBuilder(WC_Gateway_PayCryptoMe::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['register_paycrypto_me_log'])
            ->getMock();
        // disableOriginalConstructor() skips the id assignment normally done in __construct().
        $gateway->id = 'paycrypto_me';

        return $gateway;
    }

    private function make_lightning_gateway(): WC_Gateway_PayCryptoMe_Lightning
    {
        $gateway = $this->getMockBuilder(WC_Gateway_PayCryptoMe_Lightning::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['register_paycrypto_me_log'])
            ->getMock();
        $gateway->id = 'paycrypto_me_lightning';

        return $gateway;
    }

    // --- On-Chain gateway --------------------------------------------------------

    public function test_bitcoin_returns_null_when_no_payment_address()
    {
        $args = $this->make_bitcoin_gateway()->build_order_display_args($this->make_order());
        $this->assertNull($args);
    }

    public function test_bitcoin_maps_mainnet_meta_to_display_args()
    {
        $order = $this->make_order([
            '_paycrypto_me_payment_address'              => 'bc1qexampleaddress',
            '_paycrypto_me_payment_uri'                  => 'bitcoin:bc1qexampleaddress',
            '_paycrypto_me_crypto_network'               => 'mainnet',
            '_paycrypto_me_crypto_amount'                => '0.005',
            '_paycrypto_me_crypto_currency'              => 'BTC',
            '_paycrypto_me_payment_number_confirmations' => '3',
        ], 'paycrypto_me');

        $args = $this->make_bitcoin_gateway()->build_order_display_args($order);

        $this->assertSame('bc1qexampleaddress', $args['payment_identifier']);
        $this->assertSame('bitcoin:bc1qexampleaddress', $args['payment_uri']);
        $this->assertStringContainsString('assets/bitcoin-icon.png', $args['logo_path']);
        $this->assertSame('mainnet', $args['crypto_network']);
        $this->assertSame('On-Chain', $args['network_label']);
        $this->assertSame('0.005', $args['crypto_amount']);
        $this->assertSame('BTC', $args['crypto_currency']);
        $this->assertSame(3, $args['confirmations_required']);
    }

    public function test_bitcoin_testnet_network_label()
    {
        $order = $this->make_order([
            '_paycrypto_me_payment_address' => 'tb1qexample',
            '_paycrypto_me_crypto_network'  => 'testnet',
        ], 'paycrypto_me');

        $args = $this->make_bitcoin_gateway()->build_order_display_args($order);

        $this->assertSame('Testnet', $args['network_label']);
    }

    public function test_bitcoin_unknown_network_label_falls_back_to_network()
    {
        $order = $this->make_order([
            '_paycrypto_me_payment_address' => 'addr',
            '_paycrypto_me_crypto_network'  => 'regtest',
        ], 'paycrypto_me');

        $args = $this->make_bitcoin_gateway()->build_order_display_args($order);

        $this->assertSame('regtest', $args['network_label']);
    }

    public function test_bitcoin_returns_null_when_payment_method_mismatches_gateway()
    {
        $order = $this->make_order([
            '_paycrypto_me_payment_address' => 'bc1qexampleaddress',
        ], 'paycrypto_me_lightning');

        $args = $this->make_bitcoin_gateway()->build_order_display_args($order);

        $this->assertNull($args);
    }

    public function test_bitcoin_returns_args_when_payment_method_is_express_variant()
    {
        $order = $this->make_order([
            '_paycrypto_me_payment_address' => 'bc1qexampleaddress',
        ], 'paycrypto_me_express');

        $args = $this->make_bitcoin_gateway()->build_order_display_args($order);

        $this->assertNotNull($args);
        $this->assertSame('bc1qexampleaddress', $args['payment_identifier']);
    }

    // --- Lightning gateway -------------------------------------------------------

    public function test_lightning_returns_null_when_no_payment_request()
    {
        $args = $this->make_lightning_gateway()->build_order_display_args($this->make_order());
        $this->assertNull($args);
    }

    public function test_lightning_maps_meta_to_display_args()
    {
        $order = $this->make_order([
            '_paycrypto_me_payment_request' => 'lnbc1exampleinvoice',
            '_paycrypto_me_payment_uri'     => 'lightning:lnbc1exampleinvoice',
        ], 'paycrypto_me_lightning');

        $args = $this->make_lightning_gateway()->build_order_display_args($order);

        $this->assertSame('lnbc1exampleinvoice', $args['payment_identifier']);
        $this->assertSame('lightning:lnbc1exampleinvoice', $args['payment_uri']);
        $this->assertStringContainsString('assets/lightning-network-icon.png', $args['logo_path']);
        $this->assertSame('lightning', $args['crypto_network']);
        $this->assertSame('Lightning Network', $args['network_label']);
        $this->assertNull($args['crypto_amount']);
        $this->assertSame('BTC', $args['crypto_currency']);
        $this->assertSame(0, $args['confirmations_required']);
    }

    public function test_lightning_returns_null_when_payment_method_mismatches_gateway()
    {
        $order = $this->make_order([
            '_paycrypto_me_payment_request' => 'lnbc1exampleinvoice',
        ], 'paycrypto_me');

        $args = $this->make_lightning_gateway()->build_order_display_args($order);

        $this->assertNull($args);
    }

    public function test_lightning_returns_args_when_payment_method_is_express_variant()
    {
        $order = $this->make_order([
            '_paycrypto_me_payment_request' => 'lnbc1exampleinvoice',
        ], 'paycrypto_me_lightning_express');

        $args = $this->make_lightning_gateway()->build_order_display_args($order);

        $this->assertNotNull($args);
        $this->assertSame('lnbc1exampleinvoice', $args['payment_identifier']);
    }
}
