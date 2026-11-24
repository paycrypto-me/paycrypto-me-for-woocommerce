<?php
use PHPUnit\Framework\TestCase;
use PayCryptoMe\WooCommerce\PaymentDisplayDataBuilder;
use PayCryptoMe\WooCommerce\QrCodeService;

// WC_Order / wp_date / HOUR_IN_SECONDS / get_option fallbacks live in
// tests/_support/wp-helpers.php (loaded by bootstrap.php before any test file).

class PaymentDisplayDataBuilderTest extends TestCase
{
    private function make_builder(string $qr_return = 'data:image/png;base64,QR'): PaymentDisplayDataBuilder
    {
        $qr = $this->createMock(QrCodeService::class);
        $qr->method('generate_qr_code_data_uri')->willReturn($qr_return);

        return new PaymentDisplayDataBuilder($qr);
    }

    private function make_order(array $meta = [], $date_created = null): \WC_Order
    {
        $order = $this->createMock(\WC_Order::class);
        $order->method('get_meta')->willReturnCallback(
            fn($key, $single = true, $context = 'view') => $meta[$key] ?? ''
        );
        $order->method('get_status')->willReturn('pending');
        $order->method('needs_payment')->willReturn(true);
        $order->method('get_date_created')->willReturn($date_created);

        return $order;
    }

    private function sample_args(array $overrides = []): array
    {
        return array_merge([
            'payment_identifier'     => 'bc1qexampleaddress',
            'payment_uri'            => 'bitcoin:bc1qexampleaddress?amount=0.01',
            'logo_path'              => '/tmp/logo.png',
            'crypto_network'         => 'mainnet',
            'network_label'          => 'On-Chain',
            'crypto_amount'          => '0.01',
            'crypto_currency'        => 'BTC',
            'confirmations_required' => 3,
        ], $overrides);
    }

    /** @dataProvider order_states */
    public function test_payment_decision($status, $paid, $needs_payment, $refund, $uri, $deadline, $state, $action)
    {
        $order = $this->createMock(\WC_Order::class);
        $order->method('get_status')->willReturn($status);
        $order->method('get_payment_method')->willReturn(str_starts_with($uri, 'bitcoin:') ? 'paycrypto_me' : 'paycrypto_me_lightning');
        $order->method('is_paid')->willReturn($paid);
        $order->method('needs_payment')->willReturn($needs_payment);
        $order->method('get_total')->willReturn('10.00');
        $order->method('get_total_refunded')->willReturn($refund);
        $order->method('get_meta')->willReturnCallback(fn($key) => $key === '_paycrypto_me_payment_expires_ts' ? $deadline : '');
        $qr = $this->createMock(QrCodeService::class);
        $qr->expects($action === 'pay' ? $this->once() : $this->never())->method('generate_qr_code_data_uri');
        $data = (new PaymentDisplayDataBuilder($qr))->build($order, $this->sample_args([
            'crypto_network' => str_starts_with($uri, 'bitcoin:') ? 'mainnet' : 'lightning',
            'payment_uri' => $uri,
        ]));
        $this->assertSame([$state, $action], [$data['payment_state'], $data['payment_action']]);
    }

    public function order_states(): array
    {
        return [
            ['processing', true, false, 0, 'bitcoin:addr', 0, 'paid', 'none'],
            ['completed', true, false, 0, 'lightning:invoice', 1, 'paid', 'none'],
            ['cancelled', false, false, 0, 'lightning:invoice', 0, 'cancelled', 'none'],
            ['refunded', false, false, 0, '', 0, 'refunded', 'none'],
            ['processing', true, false, 9.999, '', 0, 'refunded', 'none'],
            ['ready', true, false, 2, '', 0, 'paid', 'none'],
            ['on-hold', false, false, 0, '', 0, 'on_hold', 'none'],
            ['failed', false, true, 0, '', 0, 'failed', 'retry'],
            ['pending', false, false, 0, '', 0, 'no_payment_due', 'none'],
            ['pending', false, true, 0, 'lightning:invoice', time(), 'expired', 'retry'],
            ['pending', false, true, 0, 'lightning:invoice', 0, 'unavailable', 'retry'],
            ['pending', false, true, 0, 'https://example.org', time()+600, 'unavailable', 'retry'],
            ['pending', false, true, 0, 'bitcoin:addr', 0, 'awaiting_payment', 'pay'],
            ['custom', false, true, 0, '', 0, 'order_status', 'retry'],
            ['custom', false, false, 0, '', 0, 'order_status', 'none'],
        ];
    }

    public function test_build_merges_args_and_order_meta_into_display_array()
    {
        $builder = $this->make_builder('data:image/png;base64,QR');
        $order   = $this->make_order([
            '_paycrypto_me_fiat_amount'         => '199.90',
            '_paycrypto_me_fiat_currency'       => 'USD',
            '_paycrypto_me_payment_expires_at'  => '2',
        ]);

        $data = $builder->build($order, $this->sample_args());

        $this->assertSame('bc1qexampleaddress', $data['payment_identifier']);
        $this->assertSame('bitcoin:bc1qexampleaddress?amount=0.01', $data['payment_uri']);
        $this->assertSame('data:image/png;base64,QR', $data['payment_qr_code']);
        $this->assertSame('199.90', $data['fiat_amount']);
        $this->assertSame('USD', $data['fiat_currency']);
        $this->assertSame('0.01', $data['crypto_amount']);
        $this->assertSame('BTC', $data['crypto_currency']);
        $this->assertSame('Bitcoin', $data['crypto_label']);
        $this->assertSame('On-Chain', $data['network_label']);
        $this->assertSame('mainnet', $data['crypto_network']);
        $this->assertSame('2', $data['expires_at']);
        $this->assertNull($data['expires_at_timestamp']);
        $this->assertSame(3, $data['confirmations_required']);
    }

    public function test_build_exposes_exactly_the_expected_keys()
    {
        $data = $this->make_builder()->build($this->make_order(), $this->sample_args());

        $this->assertSame([
            'payment_identifier',
            'payment_uri',
            'payment_qr_code',
            'fiat_amount',
            'fiat_currency',
            'crypto_amount',
            'crypto_currency',
            'crypto_label',
            'network_label',
            'crypto_network',
            'expires_at',
            'expires_at_timestamp',
            'expires_at_formatted',
            'is_expired',
            'confirmations_required',
            'order_status',
            'payment_state',
            'payment_action',
            'refunded_amount',
        ], array_keys($data));
    }

    public function test_expires_at_formatted_null_when_no_date()
    {
        $data = $this->make_builder()->build(
            $this->make_order(['_paycrypto_me_payment_expires_at' => '2'], null),
            $this->sample_args()
        );

        $this->assertNull($data['expires_at_formatted']);
    }

    public function test_expires_at_formatted_null_when_zero_hours()
    {
        $date = new \DateTime('@0');
        $data = $this->make_builder()->build(
            $this->make_order(['_paycrypto_me_payment_expires_at' => '0'], $date),
            $this->sample_args()
        );

        $this->assertNull($data['expires_at_formatted']);
    }

    public function test_expires_at_formatted_computed_when_date_and_hours_present()
    {
        $date = new \DateTime('@0'); // getTimestamp() === 0
        $data = $this->make_builder()->build(
            $this->make_order(['_paycrypto_me_payment_expires_at' => '2'], $date),
            $this->sample_args()
        );

        // 2 hours after epoch, formatted via the (shimmed) gmdate-based wp_date.
        $this->assertSame(2 * HOUR_IN_SECONDS, $data['expires_at_timestamp']);
        $this->assertNotNull($data['expires_at_formatted']);
        $this->assertStringContainsString(gmdate('', 2 * HOUR_IN_SECONDS), (string) $data['expires_at_formatted']);
    }

    public function test_expires_at_formatted_null_when_show_expiry_false()
    {
        $date = new \DateTime('@0');
        $data = $this->make_builder()->build(
            $this->make_order(['_paycrypto_me_payment_expires_at' => '2'], $date),
            $this->sample_args(['show_expiry' => false])
        );

        // On-chain opts out: expiry isn't enforced, so it must not be shown.
        $this->assertNull($data['expires_at_timestamp']);
        $this->assertNull($data['expires_at_formatted']);
    }

    public function test_absolute_expiry_meta_wins_over_the_hours_anchored_to_the_order_date()
    {
        // A Lightning invoice reused on a checkout retry records its REMAINING hours, which the
        // hours branch would anchor to the order date — a moment already long past. The absolute
        // timestamp the processor writes is the invoice the node will actually honour.
        $order = $this->make_order(
            [
                '_paycrypto_me_payment_expires_at' => '1',
                '_paycrypto_me_payment_expires_ts' => (string) (time() + 6 * HOUR_IN_SECONDS),
            ],
            new \DateTime('@' . (time() - 18 * HOUR_IN_SECONDS))
        );

        $data = $this->make_builder()->build($order, $this->sample_args());

        $this->assertSame((int) $order->get_meta('_paycrypto_me_payment_expires_ts'), $data['expires_at_timestamp']);
        $this->assertFalse($data['is_expired'], 'an invoice with 6h left must not be shown as expired');
    }

    public function test_absolute_expiry_meta_in_the_past_marks_the_payment_expired()
    {
        $order = $this->make_order([
            '_paycrypto_me_payment_expires_ts' => (string) (time() - 60),
        ]);

        $data = $this->make_builder()->build($order, $this->sample_args());

        $this->assertSame((int) $order->get_meta('_paycrypto_me_payment_expires_ts'), $data['expires_at_timestamp']);
        $this->assertTrue($data['is_expired']);
    }

    public function test_absolute_expiry_meta_is_ignored_when_the_gateway_opts_out_of_expiry()
    {
        // On-chain: nothing enforces the timeout, so a past timestamp must never hide the address.
        $order = $this->make_order([
            '_paycrypto_me_payment_expires_ts' => (string) (time() - 60),
        ]);

        $data = $this->make_builder()->build($order, $this->sample_args(['show_expiry' => false]));

        $this->assertFalse($data['is_expired']);
        $this->assertNull($data['expires_at_timestamp']);
        $this->assertNull($data['expires_at_formatted']);
    }

    public function test_build_tolerates_qr_code_service_returning_empty_string()
    {
        // Regression test for C6: an unavailable QR (GD/fileinfo/iconv missing on the host)
        // must not prevent the rest of the payment display data from being built — the
        // address, uri and copy button are what's essential for the customer to still pay.
        $data = $this->make_builder('')->build($this->make_order(), $this->sample_args());

        $this->assertSame('', $data['payment_qr_code']);
        $this->assertSame('bc1qexampleaddress', $data['payment_identifier']);
        $this->assertSame('bitcoin:bc1qexampleaddress?amount=0.01', $data['payment_uri']);
    }

    public function test_crypto_label_maps_btc_to_bitcoin()
    {
        $data = $this->make_builder()->build(
            $this->make_order(),
            $this->sample_args(['crypto_currency' => 'BTC'])
        );

        $this->assertSame('Bitcoin', $data['crypto_label']);
    }

    public function test_build_normalizes_public_contract_types_without_floating_decimals()
    {
        $data = $this->make_builder()->build(
            $this->make_order([
                '_paycrypto_me_fiat_amount'        => '199.90',
                '_paycrypto_me_fiat_currency'      => 'USD',
                '_paycrypto_me_payment_expires_at' => '',
            ]),
            $this->sample_args([
                'crypto_amount'          => '',
                'confirmations_required' => '-2',
            ])
        );

        $this->assertSame('199.90', $data['fiat_amount']);
        $this->assertNull($data['crypto_amount']);
        $this->assertSame('', $data['expires_at']);
        $this->assertSame(0, $data['confirmations_required']);
    }

    public function test_crypto_label_falls_back_to_currency_for_unknown_code()
    {
        $data = $this->make_builder()->build(
            $this->make_order(),
            $this->sample_args(['crypto_currency' => 'XYZ'])
        );

        $this->assertSame('XYZ', $data['crypto_label']);
    }

    public function test_lightning_style_args_pass_through_null_crypto_amount()
    {
        $data = $this->make_builder()->build(
            $this->make_order(),
            $this->sample_args([
                'crypto_amount'          => null,
                'crypto_network'         => 'lightning',
                'network_label'          => 'Lightning Network',
                'confirmations_required' => 0,
            ])
        );

        $this->assertNull($data['crypto_amount']);
        $this->assertSame('lightning', $data['crypto_network']);
        $this->assertSame('Lightning Network', $data['network_label']);
        $this->assertSame(0, $data['confirmations_required']);
        $this->assertSame('Bitcoin', $data['crypto_label']);
    }
}
