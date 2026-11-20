<?php

use PayCryptoMe\WooCommerce\UninstallPreferences;
use PHPUnit\Framework\TestCase;

final class UninstallPreferencesTest extends TestCase
{
    /** @dataProvider choices */
    public function test_only_explicit_choices_authorize_cleanup($value, bool $logs, bool $payments): void
    {
        self::assertSame(['logs' => $logs, 'payments' => $payments], UninstallPreferences::choices($value));
    }

    public static function choices(): array
    {
        return [
            [null, false, false],
            [new stdClass(), false, false],
            ['yes', false, false],
            [['logs' => 1, 'payments' => ['yes']], false, false],
            [['logs' => 'yes'], true, false],
            [['payments' => 'yes'], false, true],
            [['logs' => 'yes', 'payments' => 'yes'], true, true],
        ];
    }
}
