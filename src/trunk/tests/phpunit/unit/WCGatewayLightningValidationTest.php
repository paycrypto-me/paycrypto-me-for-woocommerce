<?php
use PHPUnit\Framework\TestCase;
use PayCryptoMe\WooCommerce\WC_Gateway_PayCryptoMe_Lightning;

// WC_Payment_Gateway/WC_Admin_Settings/esc_url_raw/wp_parse_url fallbacks live in
// tests/_support/wp-helpers.php (loaded by bootstrap.php before any test file).

class WCGatewayLightningValidationTest extends TestCase
{
    protected function setUp(): void
    {
        $_POST = [];
        \WC_Admin_Settings::$errors = [];
    }

    private function make_gateway(array $extraMockedMethods = []): WC_Gateway_PayCryptoMe_Lightning
    {
        $gateway = $this->getMockBuilder(WC_Gateway_PayCryptoMe_Lightning::class)
            ->disableOriginalConstructor()
            ->onlyMethods(array_merge(['register_paycrypto_me_log'], $extraMockedMethods))
            ->getMock();
        $gateway->id = 'paycrypto_me_lightning';

        return $gateway;
    }

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

    private function invoke(object $gateway, string $method, ...$args)
    {
        $m = new \ReflectionMethod(WC_Gateway_PayCryptoMe_Lightning::class, $method);
        $m->setAccessible(true);
        return $m->invoke($gateway, ...$args);
    }

    private function select_node_type(WC_Gateway_PayCryptoMe_Lightning $gateway, string $node_type): void
    {
        $_POST[$gateway->get_field_key('node_type')] = $node_type;
    }

    // --- _is_lnd_rest_selected() -------------------------------------------------

    public function test_is_lnd_rest_selected_defaults_to_btcpay_when_post_missing()
    {
        $gateway = $this->make_gateway();
        $this->assertFalse($this->invoke($gateway, '_is_lnd_rest_selected'));
    }

    public function test_is_lnd_rest_selected_true_when_post_says_lnd_rest()
    {
        $gateway = $this->make_gateway();
        $this->select_node_type($gateway, 'lnd_rest');
        $this->assertTrue($this->invoke($gateway, '_is_lnd_rest_selected'));
    }

    public function test_is_lnd_rest_selected_false_when_post_says_btcpay()
    {
        $gateway = $this->make_gateway();
        $this->select_node_type($gateway, 'btcpay');
        $this->assertFalse($this->invoke($gateway, '_is_lnd_rest_selected'));
    }

    // --- validate_btcpay_url_field ------------------------------------------------

    public function test_validate_btcpay_url_field_accepts_https_when_btcpay_selected()
    {
        $gateway = $this->make_gateway();
        $this->select_node_type($gateway, 'btcpay');

        $result = $gateway->validate_btcpay_url_field('btcpay_url', 'https://btcpay.example.com');

        $this->assertSame('https://btcpay.example.com', $result);
        $this->assertEmpty(\WC_Admin_Settings::$errors);
    }

    public function test_validate_btcpay_url_field_rejects_http_when_btcpay_selected()
    {
        $gateway = $this->make_gateway();
        $this->select_node_type($gateway, 'btcpay');

        $result = $gateway->validate_btcpay_url_field('btcpay_url', 'http://btcpay.example.com');

        $this->assertSame('', $result);
        $this->assertNotEmpty(\WC_Admin_Settings::$errors);
    }

    public function test_validate_btcpay_url_field_bypasses_https_check_when_lnd_rest_selected()
    {
        $gateway = $this->make_gateway();
        $this->select_node_type($gateway, 'lnd_rest');

        $result = $gateway->validate_btcpay_url_field('btcpay_url', 'http://btcpay.example.com');

        $this->assertSame('http://btcpay.example.com', $result);
        $this->assertEmpty(\WC_Admin_Settings::$errors);
    }

    public function test_validate_btcpay_url_field_null_returns_empty_string()
    {
        $gateway = $this->make_gateway();
        $this->assertSame('', $gateway->validate_btcpay_url_field('btcpay_url', null));
    }

    // --- validate_btcpay_api_key_field ---------------------------------------------

    public function test_validate_btcpay_api_key_field_accepts_long_key_when_btcpay_selected()
    {
        $gateway = $this->make_gateway();
        $this->select_node_type($gateway, 'btcpay');
        $key = str_repeat('a', 20);

        $this->assertSame($key, $gateway->validate_btcpay_api_key_field('btcpay_api_key', $key));
        $this->assertEmpty(\WC_Admin_Settings::$errors);
    }

    public function test_validate_btcpay_api_key_field_rejects_short_key_when_btcpay_selected()
    {
        $gateway = $this->make_gateway();
        $this->select_node_type($gateway, 'btcpay');

        $result = $gateway->validate_btcpay_api_key_field('btcpay_api_key', 'short');

        $this->assertSame('', $result);
        $this->assertNotEmpty(\WC_Admin_Settings::$errors);
    }

    public function test_validate_btcpay_api_key_field_skips_length_check_when_lnd_rest_selected()
    {
        $gateway = $this->make_gateway();
        $this->select_node_type($gateway, 'lnd_rest');

        $result = $gateway->validate_btcpay_api_key_field('btcpay_api_key', 'short');

        $this->assertSame('short', $result);
        $this->assertEmpty(\WC_Admin_Settings::$errors);
    }

    // --- validate_btcpay_store_id_field ---------------------------------------------

    public function test_validate_btcpay_store_id_field_just_sanitizes()
    {
        $gateway = $this->make_gateway();
        $this->assertSame('store123', $gateway->validate_btcpay_store_id_field('btcpay_store_id', ' store123 '));
        $this->assertSame('', $gateway->validate_btcpay_store_id_field('btcpay_store_id', null));
    }

    // --- validate_btcpay_payment_method_id_field ------------------------------------

    public function test_validate_btcpay_payment_method_id_field_defaults_when_empty()
    {
        $gateway = $this->make_gateway();
        $this->assertSame('BTC-LN', $gateway->validate_btcpay_payment_method_id_field('btcpay_payment_method_id', ''));
    }

    public function test_validate_btcpay_payment_method_id_field_keeps_custom_value()
    {
        $gateway = $this->make_gateway();
        $this->assertSame('BTC-LN-CUSTOM', $gateway->validate_btcpay_payment_method_id_field('btcpay_payment_method_id', 'BTC-LN-CUSTOM'));
    }

    // --- validate_btcpay_webhook_secret_field ---------------------------------------

    public function test_validate_btcpay_webhook_secret_field_warns_but_keeps_short_value_when_btcpay_selected()
    {
        $gateway = $this->make_gateway();
        $this->select_node_type($gateway, 'btcpay');

        $result = $gateway->validate_btcpay_webhook_secret_field('btcpay_webhook_secret', 'short');

        // Unlike the other validators this one does NOT clear the value on failure.
        $this->assertSame('short', $result);
        $this->assertNotEmpty(\WC_Admin_Settings::$errors);
    }

    public function test_validate_btcpay_webhook_secret_field_no_warning_when_lnd_rest_selected()
    {
        $gateway = $this->make_gateway();
        $this->select_node_type($gateway, 'lnd_rest');

        $result = $gateway->validate_btcpay_webhook_secret_field('btcpay_webhook_secret', 'short');

        $this->assertSame('short', $result);
        $this->assertEmpty(\WC_Admin_Settings::$errors);
    }

    // --- validate_lnd_rest_url_field -------------------------------------------------

    public function test_validate_lnd_rest_url_field_accepts_https_when_lnd_rest_selected()
    {
        $gateway = $this->make_gateway();
        $this->select_node_type($gateway, 'lnd_rest');

        $result = $gateway->validate_lnd_rest_url_field('lnd_rest_url', 'https://lnd.example.com');

        $this->assertSame('https://lnd.example.com', $result);
        $this->assertEmpty(\WC_Admin_Settings::$errors);
    }

    public function test_validate_lnd_rest_url_field_rejects_http_when_lnd_rest_selected()
    {
        $gateway = $this->make_gateway();
        $this->select_node_type($gateway, 'lnd_rest');

        $result = $gateway->validate_lnd_rest_url_field('lnd_rest_url', 'http://lnd.example.com');

        $this->assertSame('', $result);
        $this->assertNotEmpty(\WC_Admin_Settings::$errors);
    }

    public function test_validate_lnd_rest_url_field_bypasses_https_check_when_btcpay_selected()
    {
        $gateway = $this->make_gateway();
        $this->select_node_type($gateway, 'btcpay');

        $result = $gateway->validate_lnd_rest_url_field('lnd_rest_url', 'http://lnd.example.com');

        $this->assertSame('http://lnd.example.com', $result);
        $this->assertEmpty(\WC_Admin_Settings::$errors);
    }

    // --- validate_lnd_macaroon_hex_field ----------------------------------------------

    public function test_validate_lnd_macaroon_hex_field_accepts_valid_hex_when_lnd_rest_selected()
    {
        $gateway = $this->make_gateway();
        $this->select_node_type($gateway, 'lnd_rest');
        $hex = str_repeat('ab', 60); // 120 hex chars

        $this->assertSame($hex, $gateway->validate_lnd_macaroon_hex_field('lnd_macaroon_hex', $hex));
        $this->assertEmpty(\WC_Admin_Settings::$errors);
    }

    public function test_validate_lnd_macaroon_hex_field_strips_whitespace()
    {
        $gateway = $this->make_gateway();
        $this->select_node_type($gateway, 'lnd_rest');
        $hex = str_repeat('ab', 60);
        $withSpaces = implode(' ', str_split($hex, 10));

        $this->assertSame($hex, $gateway->validate_lnd_macaroon_hex_field('lnd_macaroon_hex', $withSpaces));
    }

    public function test_validate_lnd_macaroon_hex_field_rejects_too_short_when_lnd_rest_selected()
    {
        $gateway = $this->make_gateway();
        $this->select_node_type($gateway, 'lnd_rest');

        $result = $gateway->validate_lnd_macaroon_hex_field('lnd_macaroon_hex', 'deadbeef');

        $this->assertSame('', $result);
        $this->assertNotEmpty(\WC_Admin_Settings::$errors);
    }

    public function test_validate_lnd_macaroon_hex_field_rejects_non_hex_when_lnd_rest_selected()
    {
        $gateway = $this->make_gateway();
        $this->select_node_type($gateway, 'lnd_rest');
        $notHex = str_repeat('z', 120);

        $result = $gateway->validate_lnd_macaroon_hex_field('lnd_macaroon_hex', $notHex);

        $this->assertSame('', $result);
        $this->assertNotEmpty(\WC_Admin_Settings::$errors);
    }

    public function test_validate_lnd_macaroon_hex_field_skips_validation_when_btcpay_selected()
    {
        $gateway = $this->make_gateway();
        $this->select_node_type($gateway, 'btcpay');

        $result = $gateway->validate_lnd_macaroon_hex_field('lnd_macaroon_hex', 'deadbeef');

        $this->assertSame('deadbeef', $result);
        $this->assertEmpty(\WC_Admin_Settings::$errors);
    }

    // --- validate_lnd_certificate_field -------------------------------------------------

    public function test_validate_lnd_certificate_field_accepts_valid_pem_when_lnd_rest_selected()
    {
        $gateway = $this->make_gateway();
        $this->select_node_type($gateway, 'lnd_rest');
        $pem = "-----BEGIN CERTIFICATE-----\nMIIB...\n-----END CERTIFICATE-----";

        $this->assertSame($pem, $gateway->validate_lnd_certificate_field('lnd_certificate', $pem));
        $this->assertEmpty(\WC_Admin_Settings::$errors);
    }

    public function test_validate_lnd_certificate_field_rejects_malformed_when_lnd_rest_selected()
    {
        $gateway = $this->make_gateway();
        $this->select_node_type($gateway, 'lnd_rest');

        $result = $gateway->validate_lnd_certificate_field('lnd_certificate', 'not-a-cert');

        $this->assertSame('', $result);
        $this->assertNotEmpty(\WC_Admin_Settings::$errors);
    }

    public function test_validate_lnd_certificate_field_empty_value_short_circuits()
    {
        $gateway = $this->make_gateway();
        $this->select_node_type($gateway, 'lnd_rest');

        $this->assertSame('', $gateway->validate_lnd_certificate_field('lnd_certificate', ''));
        $this->assertEmpty(\WC_Admin_Settings::$errors);
    }

    public function test_validate_lnd_certificate_field_skips_validation_when_btcpay_selected()
    {
        $gateway = $this->make_gateway();
        $this->select_node_type($gateway, 'btcpay');

        $result = $gateway->validate_lnd_certificate_field('lnd_certificate', 'not-a-cert');

        $this->assertSame('not-a-cert', $result);
        $this->assertEmpty(\WC_Admin_Settings::$errors);
    }

    // --- validate_invoice_expiry_field -------------------------------------------------

    public function test_validate_invoice_expiry_field_accepts_value_within_range()
    {
        $gateway = $this->make_gateway();
        $this->assertSame('3600', $gateway->validate_invoice_expiry_field('invoice_expiry', '3600'));
    }

    public function test_validate_invoice_expiry_field_accepts_lower_boundary()
    {
        $gateway = $this->make_gateway();
        $this->assertSame('300', $gateway->validate_invoice_expiry_field('invoice_expiry', '300'));
    }

    public function test_validate_invoice_expiry_field_accepts_upper_boundary()
    {
        $gateway = $this->make_gateway();
        $this->assertSame('86400', $gateway->validate_invoice_expiry_field('invoice_expiry', '86400'));
    }

    public function test_validate_invoice_expiry_field_rejects_too_low()
    {
        $gateway = $this->make_gateway();

        $result = $gateway->validate_invoice_expiry_field('invoice_expiry', '60');

        $this->assertSame('3600', $result);
        $this->assertNotEmpty(\WC_Admin_Settings::$errors);
    }

    public function test_validate_invoice_expiry_field_rejects_too_high()
    {
        $gateway = $this->make_gateway();

        $result = $gateway->validate_invoice_expiry_field('invoice_expiry', '99999999');

        $this->assertSame('3600', $result);
        $this->assertNotEmpty(\WC_Admin_Settings::$errors);
    }

    // --- is_available() ---------------------------------------------------------------

    public function test_is_available_false_when_disabled()
    {
        $gateway = $this->make_gateway(['get_option']);
        $gateway->enabled = 'no';
        $this->setPrivateProperty($gateway, 'hide_for_non_admin_users', 'no');

        $this->assertFalse($gateway->is_available());
    }

    public function test_is_available_false_when_btcpay_config_incomplete()
    {
        $gateway = $this->make_gateway(['get_option']);
        $gateway->enabled = 'yes';
        $this->setPrivateProperty($gateway, 'hide_for_non_admin_users', 'no');
        $gateway->method('get_option')->willReturnCallback(fn ($key, $empty = null) => match ($key) {
            'node_type'       => 'btcpay',
            'btcpay_url'      => 'https://btcpay.example.com',
            'btcpay_api_key'  => '',
            'btcpay_store_id' => 'store123',
            default           => $empty,
        });

        $this->assertFalse($gateway->is_available());
    }

    public function test_is_available_true_when_btcpay_config_complete()
    {
        $gateway = $this->make_gateway(['get_option']);
        $gateway->enabled = 'yes';
        $this->setPrivateProperty($gateway, 'hide_for_non_admin_users', 'no');
        $gateway->method('get_option')->willReturnCallback(fn ($key, $empty = null) => match ($key) {
            'node_type'       => 'btcpay',
            'btcpay_url'      => 'https://btcpay.example.com',
            'btcpay_api_key'  => 'apikey456',
            'btcpay_store_id' => 'store123',
            default           => $empty,
        });

        $this->assertTrue($gateway->is_available());
    }

    public function test_is_available_false_when_lnd_rest_config_incomplete()
    {
        $gateway = $this->make_gateway(['get_option']);
        $gateway->enabled = 'yes';
        $this->setPrivateProperty($gateway, 'hide_for_non_admin_users', 'no');
        $gateway->method('get_option')->willReturnCallback(fn ($key, $empty = null) => match ($key) {
            'node_type'        => 'lnd_rest',
            'lnd_rest_url'     => 'https://lnd.example.com',
            'lnd_macaroon_hex' => '',
            default            => $empty,
        });

        $this->assertFalse($gateway->is_available());
    }

    public function test_is_available_true_when_lnd_rest_config_complete()
    {
        $gateway = $this->make_gateway(['get_option']);
        $gateway->enabled = 'yes';
        $this->setPrivateProperty($gateway, 'hide_for_non_admin_users', 'no');
        $gateway->method('get_option')->willReturnCallback(fn ($key, $empty = null) => match ($key) {
            'node_type'        => 'lnd_rest',
            'lnd_rest_url'     => 'https://lnd.example.com',
            'lnd_macaroon_hex' => 'deadbeef',
            default            => $empty,
        });

        $this->assertTrue($gateway->is_available());
    }
}
