<?php
use PHPUnit\Framework\TestCase;
use PayCryptoMe\WooCommerce\OrderGatewayMatcher;

class OrderGatewayMatcherTest extends TestCase
{
    private function make_order(string $payment_method): \WC_Order
    {
        $order = $this->createMock(\WC_Order::class);
        $order->method('get_payment_method')->willReturn($payment_method);

        return $order;
    }

    public function test_matches_exact_gateway_id()
    {
        $this->assertTrue(OrderGatewayMatcher::matches($this->make_order('paycrypto_me'), 'paycrypto_me'));
    }

    public function test_matches_express_variant()
    {
        $this->assertTrue(OrderGatewayMatcher::matches($this->make_order('paycrypto_me_express'), 'paycrypto_me'));
    }

    public function test_does_not_match_other_gateway_id()
    {
        $this->assertFalse(OrderGatewayMatcher::matches($this->make_order('paycrypto_me_lightning'), 'paycrypto_me'));
    }

    public function test_does_not_match_other_gateway_express_variant()
    {
        $this->assertFalse(OrderGatewayMatcher::matches($this->make_order('paycrypto_me_lightning_express'), 'paycrypto_me'));
    }

    public function test_does_not_match_empty_payment_method()
    {
        $this->assertFalse(OrderGatewayMatcher::matches($this->make_order(''), 'paycrypto_me'));
    }
}
