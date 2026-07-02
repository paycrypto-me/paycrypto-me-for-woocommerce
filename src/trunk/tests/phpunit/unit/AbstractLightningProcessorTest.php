<?php

use PHPUnit\Framework\TestCase;
use PayCryptoMe\WooCommerce\BtcpayLightningProcessor;
use PayCryptoMe\WooCommerce\LightningInvoiceServiceContract;
use PayCryptoMe\WooCommerce\LightningInvoiceResponse;
use PayCryptoMe\WooCommerce\PayCryptoMeLightningDBStatementsService;
use PayCryptoMe\WooCommerce\PayCryptoMePaymentException;

// WC_Payment_Gateway fallback lives in tests/_support/wp-helpers.php (loaded by
// bootstrap.php before any test file).

class AbstractLightningProcessorTest extends TestCase
{
    private function setPrivateProperty(object $obj, string $name, $value): void
    {
        $rc = new \ReflectionObject($obj);
        while (!$rc->hasProperty($name) && $rc->getParentClass()) {
            $rc = $rc->getParentClass();
        }
        $prop = $rc->getProperty($name);
        $prop->setAccessible(true);
        $prop->setValue($obj, $value);
    }

    private function make_processor(\WC_Payment_Gateway $gateway, $service, $db): BtcpayLightningProcessor
    {
        $processor = $this->getMockBuilder(BtcpayLightningProcessor::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $this->setPrivateProperty($processor, 'gateway', $gateway);
        $this->setPrivateProperty($processor, 'service', $service);
        $this->setPrivateProperty($processor, 'db', $db);

        return $processor;
    }

    public function test_resolves_payment_request_when_initially_empty_before_db_insert(): void
    {
        $order = $this->createMock(\WC_Order::class);
        $order->method('get_id')->willReturn(42);

        $service = $this->createMock(LightningInvoiceServiceContract::class);
        $service->method('create_invoice')->willReturn(new LightningInvoiceResponse('inv1', '', 'New', null));
        $service->method('resolve_payment_request')->willReturnOnConsecutiveCalls('', 'lnbc1resolved');

        $db = $this->createMock(PayCryptoMeLightningDBStatementsService::class);
        $db->expects($this->once())
            ->method('insert_invoice')
            ->with(42, 'btcpay', 'inv1', 'lnbc1resolved', $this->anything(), null);

        $processor = $this->make_processor(new \WC_Payment_Gateway(), $service, $db);
        $result    = $processor->process($order, []);

        $this->assertSame('lnbc1resolved', $result['payment_request']);
        $this->assertSame('lightning:lnbc1resolved', $result['payment_uri']);
    }

    public function test_skips_resolution_when_payment_request_already_present(): void
    {
        $order = $this->createMock(\WC_Order::class);
        $order->method('get_id')->willReturn(43);

        $service = $this->createMock(LightningInvoiceServiceContract::class);
        $service->method('create_invoice')->willReturn(new LightningInvoiceResponse('inv2', 'lnbc1direct', 'OPEN', null));
        $service->expects($this->never())->method('resolve_payment_request');

        $db = $this->createMock(PayCryptoMeLightningDBStatementsService::class);
        $db->expects($this->once())
            ->method('insert_invoice')
            ->with(43, 'btcpay', 'inv2', 'lnbc1direct', $this->anything(), null);

        $processor = $this->make_processor(new \WC_Payment_Gateway(), $service, $db);
        $result    = $processor->process($order, []);

        $this->assertSame('lnbc1direct', $result['payment_request']);
        $this->assertSame('lightning:lnbc1direct', $result['payment_uri']);
    }

    public function test_btcpay_invoice_args_include_order_total_and_currency(): void
    {
        $order = new class extends \WC_Order {
            public function get_id() { return 45; }
            public function get_total() { return '150.50'; }
            public function get_currency() { return 'BRL'; }
        };

        $service = $this->createMock(LightningInvoiceServiceContract::class);
        $service->expects($this->once())
            ->method('create_invoice')
            ->with($this->callback(fn($args) => $args['amount'] === '150.50' && $args['currency'] === 'BRL'))
            ->willReturn(new LightningInvoiceResponse('inv4', 'lnbc1test', 'New', null));

        $db = $this->createMock(PayCryptoMeLightningDBStatementsService::class);

        $processor = $this->make_processor(new \WC_Payment_Gateway(), $service, $db);
        $processor->process($order, []);
    }

    public function test_throws_paycrypto_me_payment_exception_when_resolution_exhausted(): void
    {
        $order = $this->createMock(\WC_Order::class);
        $order->method('get_id')->willReturn(44);

        $service = $this->createMock(LightningInvoiceServiceContract::class);
        $service->method('create_invoice')->willReturn(new LightningInvoiceResponse('inv3', '', 'New', null));
        // Exactly RESOLVE_MAX_ATTEMPTS calls, no more/fewer — proves the loop bound.
        $service->expects($this->exactly(2))->method('resolve_payment_request')->willReturn('');

        $db = $this->createMock(PayCryptoMeLightningDBStatementsService::class);
        $db->expects($this->never())->method('insert_invoice');

        $processor = $this->make_processor(new \WC_Payment_Gateway(), $service, $db);

        $this->expectException(PayCryptoMePaymentException::class);
        $processor->process($order, []);
    }

    public function test_retry_constants_are_two_attempts_750ms_apart(): void
    {
        // Asserted via reflection rather than a live-timed retry: usleep()-ing the
        // real 750ms in a unit test would make the suite slow without adding
        // confidence beyond what the exhaustion test above already proves.
        $rc = new \ReflectionClass(\PayCryptoMe\WooCommerce\AbstractLightningProcessor::class);

        $this->assertSame(2, $rc->getConstant('RESOLVE_MAX_ATTEMPTS'));
        $this->assertSame(750, $rc->getConstant('RESOLVE_DELAY_MS'));
    }
}
