<?php
/** @package WooCommerce\PayCryptoMe */

namespace PayCryptoMe\WooCommerce;

defined('ABSPATH') || exit;

final class UninstallPreferences
{
    public const OPTION = 'paycrypto_me_uninstall_cleanup';

    /** Only an explicit checkbox value authorizes deletion. */
    public static function choices($values): array
    {
        $values = is_array($values) ? $values : [];
        return [
            'logs' => ($values['logs'] ?? null) === 'yes',
            'payments' => ($values['payments'] ?? null) === 'yes',
        ];
    }

    public static function read(): array
    {
        return self::choices(get_option(self::OPTION, []));
    }
}
