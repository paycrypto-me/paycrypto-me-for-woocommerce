<?php
use PHPUnit\Framework\TestCase;

// current_user_can/check_ajax_referer/wp_send_json_success/wp_send_json_error
// fallbacks (including the $TEST_CURRENT_USER_CAN / $TEST_CHECK_AJAX_REFERER
// control globals used below) live in tests/_support/wp-helpers.php.

class WCGatewayAjaxTest extends TestCase
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

    public function test_ajax_reset_derivation_index_permission_denied()
    {
        // ensure current_user_can returns false for this test
        global $TEST_CURRENT_USER_CAN, $TEST_CHECK_AJAX_REFERER;
        $TEST_CURRENT_USER_CAN = false;
        $TEST_CHECK_AJAX_REFERER = true;

        $gateway = $this->getMockBuilder(\PayCryptoMe\WooCommerce\WC_Gateway_PayCryptoMe::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('WP_JSON_ERROR');

        // Call the ajax handler
        $m = new \ReflectionMethod(\PayCryptoMe\WooCommerce\WC_Gateway_PayCryptoMe::class, 'ajax_reset_derivation_index');
        $m->setAccessible(true);
        $m->invoke($gateway);
    }

    public function test_ajax_reset_derivation_index_success_calls_reset()
    {
        // ensure helpers behave normally
        global $TEST_CURRENT_USER_CAN, $TEST_CHECK_AJAX_REFERER;
        $TEST_CURRENT_USER_CAN = true;
        $TEST_CHECK_AJAX_REFERER = true;

        $gateway = $this->getMockBuilder(\PayCryptoMe\WooCommerce\WC_Gateway_PayCryptoMe::class)
            ->disableOriginalConstructor()
            ->getMock();

        // create fake db service
        $db = $this->getMockBuilder(\PayCryptoMe\WooCommerce\PayCryptoMeDBStatementsService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['reset_derivation_indexes'])
            ->getMock();

        $db->expects($this->once())->method('reset_derivation_indexes')->willReturn(true);

        // inject fake db into gateway instance
        $this->setPrivateProperty($gateway, 'db_statements_service', $db);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('WP_JSON_SUCCESS');

        $m = new \ReflectionMethod(\PayCryptoMe\WooCommerce\WC_Gateway_PayCryptoMe::class, 'ajax_reset_derivation_index');
        $m->setAccessible(true);
        $m->invoke($gateway);
    }
}
