<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

/**
 * Generic HTTP client contract.
 *
 * Convention: every service that makes HTTP calls injects this interface —
 * never call wp_remote_post / wp_remote_get directly.
 */
interface HttpClientContract
{
    /**
     * @param array $args wp_remote_post-compatible args (headers, body, timeout, sslverify, sslcertificates…)
     * @return array wp_remote_* response array
     */
    public function post(string $url, array $args): array;

    /**
     * @param array $args wp_remote_get-compatible args
     * @return array wp_remote_* response array
     */
    public function get(string $url, array $args): array;
}
