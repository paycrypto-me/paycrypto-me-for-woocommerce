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
        if (\is_wp_error($response)) {
            WC_PayCryptoMe::log(
                \sprintf('HTTP POST error to %s: %s', esc_url_raw($url), esc_html($response->get_error_message())),
                'error'
            );
            return [];
        }
        return $response;
    }

    public function get(string $url, array $args): array
    {
        $response = wp_remote_get($url, $args);
        if (\is_wp_error($response)) {
            WC_PayCryptoMe::log(
                \sprintf('HTTP GET error to %s: %s', esc_url_raw($url), esc_html($response->get_error_message())),
                'error'
            );
            return [];
        }
        return $response;
    }
}
