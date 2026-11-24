<?php

use PayCryptoMe\WooCommerce\PaymentProcessor;
use PHPUnit\Framework\TestCase;

/** Runs against the active WooCommerce order store (classic or HPOS). */
class OrderPaymentPresentationTest extends TestCase
{
    private WC_Order $order;

    protected function setUp(): void
    {
        $this->order = wc_create_order();
        $this->order->set_payment_method('paycrypto_me');
        $this->order->set_total('10');
        $this->order->update_meta_data('_paycrypto_me_payment_address', 'qa-reference');
        $this->order->update_meta_data('_paycrypto_me_payment_uri', 'bitcoin:qareference');
        $this->order->update_meta_data('_paycrypto_me_crypto_network', 'mainnet');
        $this->order->update_meta_data('_paycrypto_me_crypto_currency', 'BTC');
        $this->order->update_meta_data('_paycrypto_me_fiat_amount', '10');
        $this->order->update_meta_data('_paycrypto_me_fiat_currency', 'USD');
        $this->order->save();
    }

    protected function tearDown(): void
    {
        $this->order->delete(true);
    }

    public function test_real_filters_control_expiry_and_rendering_without_reopening_the_invoice(): void
    {
        $gateway = WC()->payment_gateways()->payment_gateways()['paycrypto_me'];
        $this->order->update_meta_data('_paycrypto_me_payment_expires_ts', time() - 1);
        $pre = static function ($args) {
            $args['show_expiry'] = true;
            $args['crypto_amount'] = '0.125';
            return $args;
        };
        $post = static function ($data) {
            $data['payment_qr_code'] = 'injected-image';
            return $data;
        };
        add_filter('paycryptome_order_display_args', $pre);
        add_filter('paycryptome_order_display_data', $post);
        try {
            $data = $gateway->get_order_display_data($this->order);
            $this->assertSame('expired', $data['payment_state']);
            $this->assertSame('0.125', $data['crypto_amount']);
            $this->order->update_status('processing');
            foreach (['render_checkout_order_details_section', 'render_admin_order_details_section'] as $renderer) {
                ob_start();
                try {
                    $gateway->$renderer($this->order);
                    $html = ob_get_contents();
                } finally {
                    ob_end_clean();
                }
                $this->assertStringContainsString(esc_html__('Payment recorded by the store', 'paycrypto-me-for-woocommerce'), $html);
                $this->assertStringNotContainsString('bitcoin:qareference', $html);
                $this->assertStringNotContainsString('injected-image', $html);
                $this->assertSame($renderer === 'render_admin_order_details_section', str_contains($html, 'data-address='));
            }
        } finally {
            remove_filter('paycryptome_order_display_args', $pre);
            remove_filter('paycryptome_order_display_data', $post);
        }
    }

    public function test_draft_method_changes_and_missing_references_omit_the_card(): void
    {
        $gateway = WC()->payment_gateways()->payment_gateways()['paycrypto_me'];
        $this->order->set_payment_method('paycrypto_me_express');
        $this->assertSame('pay', $gateway->get_order_display_data($this->order)['payment_action']);
        $this->order->set_status('checkout-draft');
        $this->assertNull($gateway->get_order_display_data($this->order));
        $this->order->set_status('pending');
        $this->order->set_payment_method('cod');
        $this->assertNull($gateway->get_order_display_data($this->order));
        $this->order->set_payment_method('paycrypto_me');
        $this->order->delete_meta_data('_paycrypto_me_payment_address');
        $this->assertNull($gateway->get_order_display_data($this->order));
    }

    public function test_retry_inside_an_existing_transaction_keeps_the_callers_rollback(): void
    {
        global $wpdb;
        $this->order->update_status('failed');
        $wpdb->query('START TRANSACTION');
        try {
            $finish = new ReflectionMethod(PaymentProcessor::class, 'update_order_after_payment');
            $finish->invoke(new PaymentProcessor(), $this->order, [], 'failed');
            $this->assertSame('pending', $this->order->get_status());
        } finally {
            $wpdb->query('ROLLBACK');
            clean_post_cache($this->order->get_id());
            wp_cache_flush();
        }
        $this->assertSame('failed', wc_get_order($this->order->get_id())->get_status());
    }

    public function test_repeated_views_preserve_stock_notes_and_refund_history(): void
    {
        $product = new WC_Product_Simple();
        $product->set_name('Presentation fixture');
        $product->set_regular_price('10');
        $product->set_manage_stock(true);
        $product->set_stock_quantity(5);
        $product->save();
        $gateway = WC()->payment_gateways()->payment_gateways()['paycrypto_me'];
        try {
            $this->order->add_product($product);
            $this->order->calculate_totals();
            foreach (['on-hold' => 'on_hold', 'processing' => 'paid', 'completed' => 'paid'] as $status => $state) {
                $this->order->update_status($status);
                $note_count = count(wc_get_order_notes(['order_id' => $this->order->get_id()]));
                for ($i = 0; $i < 2; ++$i) {
                    $fresh = wc_get_order($this->order->get_id());
                    $data = $gateway->get_order_display_data($fresh);
                    $this->assertSame($state, $data['payment_state']);
                    $this->assertSame('none', $data['payment_action']);
                    $this->assertSame($status, $fresh->get_status());
                }
                $this->assertCount($note_count, wc_get_order_notes(['order_id' => $fresh->get_id()]));
                $this->assertEquals(4, wc_get_product($product->get_id())->get_stock_quantity());
            }
            $refund = wc_create_refund(['order_id' => $this->order->get_id(), 'amount' => '3.55']);
            $this->assertInstanceOf(WC_Order_Refund::class, $refund);
            $data = $gateway->get_order_display_data(wc_get_order($this->order->get_id()));
            $this->assertSame('3.55', $data['refunded_amount']);
            $this->assertSame('paid', $data['payment_state']);
        } finally {
            $product->delete(true);
        }
    }

    /** @dataProvider retry_paths */
    public function test_retry_respects_the_persisted_status($original, $intervening, $expected): void
    {
        $this->order->update_status($original);
        $attempt = wc_get_order($this->order->get_id());
        if ($intervening !== null) {
            wc_get_order($this->order->get_id())->update_status($intervening);
        }

        $finish = new ReflectionMethod(PaymentProcessor::class, 'update_order_after_payment');
        $finish->invoke(new PaymentProcessor(), $attempt, ['lightning_invoice_id' => 'issued-reference'], $original);
        $current = wc_get_order($this->order->get_id());
        $this->assertSame($expected, $current->get_status());
        $this->assertSame('issued-reference', $current->get_meta('_paycrypto_me_lightning_invoice_id'));
    }

    public function retry_paths(): array
    {
        return [
            ['pending', 'processing', 'processing'],
            ['failed', 'completed', 'completed'],
            ['failed', 'cancelled', 'cancelled'],
            ['failed', 'on-hold', 'on-hold'],
            ['failed', null, 'pending'],
        ];
    }
}
