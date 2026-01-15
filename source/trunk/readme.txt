
=== PayCrypto.Me for WooCommerce ===

PayCrypto.Me Payments for WooCommerce offers a complete solution that allows your customers to pay using many cryptocurrencies in your store.

Contributors: paycrypto-me
Donate link: https://paycrypto.me/
Tags: woocommerce, payments, cryptocurrency, bitcoin, lightning, crypto, gateway, paycrypto
Requires at least: 5.0
Tested up to: 6.9
Stable tag: 0.1.0
Requires PHP: 7.4
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

== Description ==

PayCrypto.Me Payments for WooCommerce offers a complete solution that allows your customers to pay using many cryptocurrencies in your store.

Key features:

- Accept Bitcoin (on-chain) and Lightning payments
- Non-custodial: funds go directly to merchant wallets
- Automatic order processing and payment status updates
- Compatible with WooCommerce Blocks and Custom Order Tables
- Internationalization ready (see /languages)
- Debug logging using the WooCommerce logger

== Installation ==

1. Upload the plugin folder to /wp-content/plugins/ or install via your deployment workflow.
2. Activate the plugin via the WordPress Plugins screen.
3. Go to WooCommerce → Settings → Payments and enable "PayCrypto.Me".
4. Configure your wallet (xPub / on-chain address / Lightning) and options (timeout, confirmations, network).
5. Testing: enable testnet mode in settings and create a test order. Ensure your webhook/callback endpoint is reachable.

Notes:
- Callback URL pattern: (ex.) `https://yourstore.com/?wc-api=paycrypto_me&action=callback` — confirm in gateway settings.
- For troubleshooting enable WooCommerce logs (WooCommerce → Status → Logs) and select `paycrypto_me`.

== Screenshots ==

1. Plugin settings page (network selection, xPub/lightning address, timeout, confirmations) — source/assets/screenshot-1.jpg
2. Checkout page with PayCrypto.Me option and QR code — source/assets/screenshot-2.jpg
3. Order details page showing payment status and QR code — source/assets/screenshot-3.jpg
4. Admin order details with payment metadata — source/assets/screenshot-4.jpg
5. Order details (alternate/mobile view) — source/assets/screenshot-5.jpg
6. Plugin banner (large) — source/assets/banner-1544x500.png
7. Plugin banner (small) — source/assets/banner-772x250.png

== Frequently Asked Questions ==

= Which cryptocurrencies are supported? =
Bitcoin (BTC) — on-chain and Lightning. Additional networks may be supported via PayCrypto.Me.

= Where are payment logs stored? =
Uses `wc_get_logger()` with the source `paycrypto_me`. Access logs via WooCommerce → Status → Logs.

= How do I test payments? =
Use testnet mode and PayCrypto.Me test credentials. Create test orders and confirm webhook delivery.

== Changelog ==

= 0.1.0 =
* Initial public release.
* PayCrypto.Me integration for on-chain and Lightning payments.
* Support for WooCommerce Blocks and Custom Order Tables.
* Internationalization and translations included.

== Upgrade Notice ==

= 0.1.0 =
Initial release.

== Support ==

For support visit https://paycrypto.me/ or open an issue on the GitHub repository.

== Credits ==

Developed by PayCrypto.Me — https://paycrypto.me/

