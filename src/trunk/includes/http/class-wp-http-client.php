<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       WpHttpClient
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

class WpHttpClient implements HttpClientContract
{
    public function post(string $url, array $args): array
    {
        $response = wp_remote_post($url, $args);
        return \is_wp_error($response) ? [] : $response;
    }

    public function get(string $url, array $args): array
    {
        $response = wp_remote_get($url, $args);
        return \is_wp_error($response) ? [] : $response;
    }
}
