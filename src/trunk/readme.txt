=== PayCrypto.Me for WooCommerce ===
Contributors: paycryptome, lucasrosa95
Tags: woocommerce, payments, crypto, bitcoin, lightning-network
Donate link: https://paycrypto.me/
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Requires Plugins: woocommerce
WC requires at least: 6.5
WC tested up to: 10.9
Stable tag: 0.5.1
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Self-hosted, non-custodial Bitcoin payments for WooCommerce — On-Chain (HD wallet) and Lightning Network (BTCPay Server or lnd), no middleman.

== Description ==

PayCrypto.Me for WooCommerce lets your store accept Bitcoin directly into wallets and nodes you control — no custodial processor, no third-party API holding your funds, no percentage cut on every sale.

Two independent, fully self-hosted payment methods, both included:

**Bitcoin On-Chain**
Give the plugin an account-level xPub, yPub or zPub (or a single address) and it derives a fresh, never-reused receiving address for every order via standard HD derivation — your wallet software does the rest. The extended key must be the account node at BIP32 depth 3 (`m/purpose'/coin_type'/account'`); the plugin derives the external receiving path `0/index` with non-hardened indexes from 0 through 2,147,483,647. Works on mainnet or testnet, so you can rehearse the full checkout flow with worthless test coins before going live.

**Bitcoin Lightning Network**
Connect the plugin straight to your own BTCPay Server instance or lnd node (REST API, with macaroon authentication and optional TLS certificate pinning). Invoices are created and shown to the customer in seconds — ideal for instant, low-fee payments. A built-in "Test connection" button in the settings screen confirms your node is reachable before you enable the method.

= Built for a modern WooCommerce store =

- **Checkout, your way** — works with both the WooCommerce Blocks (Cart & Checkout) experience and the classic shortcode checkout.
- **High-Performance Order Storage (HPOS)** — declared and tested compatible with WooCommerce's Custom Order Tables.
- **Express Payment button** — an optional one-click "Buy with Bitcoin" button on the checkout page, with a configurable icon position.
- **QR code everywhere it matters** — a scannable payment QR (with copy-to-clipboard and "Open in wallet" deep link) on the Thank You page, in My Account → Orders, and on the admin order screen, so both you and the customer always see the same payment details.
- **Non-custodial by design** — the plugin never takes possession of funds; it only derives addresses and requests invoices from infrastructure you own.
- **Debug logging** — payment diagnostics are available through the native WooCommerce logger (WooCommerce → Status → Logs, source `paycrypto_me`), so troubleshooting a stuck order doesn't require guesswork.
- **Translation-ready** — text domain `paycrypto-me-for-woocommerce`, with complete translations included for Portuguese (Brazil), Spanish, French, German, Italian, Russian and Chinese (Simplified).
- **Developer-friendly** — before/after payment hooks, filters over the invoice arguments sent to BTCPay/lnd, and a filter over the final payment data, so custom logic can hook in without touching plugin code.

= What this plugin intentionally does not do =

To keep the free plugin simple and auditable, three things are left out on purpose and reserved for an upcoming official Pro add-on that plugs into this same base via hooks — no fork, no code duplication:

- **Automatic payment confirmation.** Today, order status is moved forward manually once you've verified the payment yourself (e.g. in your node or block explorer). Automatic confirmation via BTCPay webhooks / lnd polling is planned for the Pro add-on.
- **On-chain confirmation tracking and transaction history.** The base stores the receiving address and derivation context; the Pro add-on owns blockchain polling, transaction records and confirmation-specific data in its own tables.
- **Fiat → sats conversion.** The base plugin sends the order total and currency to BTCPay Server. With lnd it requests an invoice without a fixed amount. Automatic conversion by the plugin is planned for the Pro add-on.

Bitcoin is currently the only supported cryptocurrency (on-chain and Lightning) — this keeps the codebase small and well-tested rather than spreading support thin across many chains.

== Installation ==

1. Make sure WooCommerce is installed and active — the plugin will show an admin notice and stay inactive otherwise.
2. Upload the plugin folder to `/wp-content/plugins/` (or install it via your usual deployment workflow) and activate it from the WordPress Plugins screen.
3. Go to **WooCommerce → Settings → Payments** and you'll see two new methods: "Bitcoin" (On-Chain) and "Bitcoin Lightning". Enable whichever one (or both) you want to accept.
4. **On-Chain:** open its settings and paste your account-level xPub/yPub/zPub (recommended) or a single receiving address, choose mainnet or testnet, and adjust the payment timeout and number of confirmations required to your risk tolerance.
5. **Lightning:** open its settings, choose your node type (BTCPay Server or lnd REST), fill in the connection details (URL, API key/macaroon, optional TLS certificate), and use the "Test connection" button to confirm the plugin can reach it before enabling the method.
6. Testing: switch the On-Chain gateway to testnet, place a test order and confirm the full flow end to end before going live with mainnet.

Notes:
- For troubleshooting, enable debug logging in the gateway settings and check WooCommerce → Status → Logs (source `paycrypto_me`).
- An xPub/yPub/zPub cannot spend your funds, but it can reveal the addresses and activity of that
  wallet account. Treat it as privacy-sensitive financial data and protect access to your WordPress
  database and backups. BTCPay API keys and lnd macaroons are authentication secrets with stronger
  privileges: use least-privilege credentials and protect them accordingly. Never enter a wallet
  seed or private key into this plugin.
- Only Bitcoin is currently supported (on-chain and Lightning). Support for additional networks may be considered in future updates.
- Payment confirmation is currently a manual, admin-driven step — see "What this plugin intentionally does not do" above.
- **PHP extensions:** deriving addresses from an xPub/yPub/zPub requires the `gmp` extension. Without it, that route is unavailable (with an admin notice explaining it), but you can still accept On-Chain payments by configuring a single fixed bech32 address (`bc1…`/`tb1…`), which needs no such extension — every order is then paid to that same address, which is worse for privacy but works. Lightning is unaffected either way. The payment QR code requires `gd`; if it's missing, the order-details page still shows the address/invoice and a copy button, just without the QR image.

== Screenshots ==

1. Bitcoin and Lightning options, express buttons, billing fields and order summary in WooCommerce Blocks.
2. The classic shortcode checkout on mobile, with both payment methods and the complete order form.
3. Mobile order overview, testnet QR code, wallet link, payment status and billing details.
4. The complete WooCommerce order screen, including payment details, order actions, items and notes.
5. Both gateways in WooCommerce payment settings, with navigation and status controls.
6. Express payment, a testnet receiving address and uninstall choices.
7. BTCPay/lnd selection, example connection settings and uninstall choices.
8. Full mobile WooCommerce Blocks checkout with Bitcoin on-chain, Lightning and express buttons, using the Onyx theme and fictional customer data.

== Frequently Asked Questions ==

= Which cryptocurrencies are supported? =
Bitcoin only, through two independent methods: On-Chain (mainnet/testnet, address derived from your account-level xPub/yPub/zPub or testnet tPub/upub/vpub) and Lightning Network (via your own BTCPay Server or lnd node).

= Does the plugin take custody of my funds at any point? =
No. On-Chain payments go straight to addresses derived from your own extended public key; Lightning invoices are created and settled directly by your own BTCPay Server or lnd node. The plugin never holds keys or funds.

= Can I run both On-Chain and Lightning at the same time? =
Yes — enable both gateways and customers choose whichever they prefer at checkout.

= Do I need to run my own Lightning node? =
Yes, you need access to a BTCPay Server instance or an lnd node with its REST API reachable from your WordPress host. The plugin is a client to infrastructure you already run or control.

= Does it work with WooCommerce Blocks checkout? =
Yes, both gateways support the Blocks-based Cart & Checkout as well as the classic shortcode checkout, and both declare compatibility with High-Performance Order Storage (HPOS).

= How do I test payments safely before going live? =
Switch the On-Chain gateway's network to testnet and place a test order, or use your Lightning node's own testing/regtest setup if it supports one — the plugin itself only exposes mainnet/testnet for On-Chain.

= Where can I find payment logs, and what happens to them when I uninstall the plugin? =
Open WooCommerce → Status → Logs and look for the source `paycrypto_me`. WooCommerce manages log retention. By default, deleting the plugin leaves those logs in place. To remove its native WooCommerce logs on deletion, select the log checkbox in either gateway’s Danger Area and save.

= Does the order status update automatically once the customer pays? =
Not in the free plugin — see "What this plugin intentionally does not do" in the description. Automatic confirmation is planned for a future Pro add-on.

= Does the plugin store my wallet's private keys? =
No. The plugin never asks for or stores a wallet seed or private key. For HD address derivation it
stores the account-level extended public key (xPub/yPub/zPub or testnet tPub/upub/vpub) you provide. An extended public key cannot authorize
spending, but it can derive the account's public addresses and reveal its transaction history, so it
should still be treated as privacy-sensitive financial data.

== Privacy ==

= What information does the store keep? =

The plugin links each Bitcoin payment to its WooCommerce order ID so the store can show payment instructions, handle checkout retries and reconcile orders. Someone with access to the order can associate these records with the customer.

- **On-chain:** The store keeps the receiving address with the order. For an HD wallet, dedicated tables also keep the account-level extended public key, network and derivation indexes so future orders can use fresh addresses. The extended public key is readable and can reveal the wallet account's addresses and activity.
- **Lightning:** The store keeps the node type, invoice ID, payment request, status, expiry and amount in satoshis when supplied. The gateway settings hold the node URL, authentication credentials and optional TLS certificate.
- **Orders and logs:** WooCommerce order metadata contains payment instructions, including the wallet link. Payment logs can include the order ID, amounts, currencies, network, a shortened payment identifier and diagnostic errors. Restrict access to orders, logs, database copies and backups.

= Who receives payment information? =

The plugin generates on-chain addresses and payment QR images on the WordPress server. The on-chain QR and wallet link contain the receiving address, an order reference, the billing first name when provided, and the Bitcoin amount when supplied. Opening the link or scanning the code gives those details to the wallet application. When the customer pays, the receiving address and transaction amount become public on the Bitcoin blockchain. The order reference and billing name are wallet instructions and are separate from the blockchain transaction. Reusing a fixed address makes payments to the same store easier to link.

For Lightning, the plugin contacts the endpoint chosen by the store when testing the connection and when creating or retrieving an invoice. BTCPay Server receives the order ID, order total, currency, invoice description (empty by default) and checkout settings including expiry. lnd receives the expiry and a memo (empty by default), plus an amount if supplied by an extension. A hosted node provider can access the requests its service receives. Extensions can change invoice fields. The base plugin sends no payment telemetry to PayCrypto.Me.

= How long are records kept, and how can a customer make a request? =

In either gateway’s **Danger Area**, choose what WordPress should erase when you delete the plugin. Both checkboxes start unchecked, including after upgrading. The choices are shared by both gateways on the same site. You can change them until uninstall. Saving, updating or deactivating the plugin keeps the records.

- **Neither selected:** The store keeps the payment tables, order payment details and logs. WooCommerce continues to apply its own log retention settings.
- **Logs only:** Removes entries with source `paycrypto_me` in the native WooCommerce log database and matching files (including rotations) in the configured log directory. Payment records remain. External log destinations, archived directories and backups have their own retention arrangements. On multisite, a shared log directory can be cleaned only if all sites using it authorize log cleanup.
- **Payment records only:** Removes this plugin's payment metadata from classic and HPOS order storage and drops its four tables: `{prefix}paycrypto_me_bitcoin_wallet_xpubkeys`, `{prefix}paycrypto_me_bitcoin_derivation_indexes`, `{prefix}paycrypto_me_bitcoin_transactions_data` and `{prefix}paycrypto_me_lightning_invoices`. Logs remain.
- **Both selected:** Applies both cleanups.

Uninstall always removes gateway settings, including stored Lightning credentials. Orders, totals, payment method and order notes (including payment initiation and status notes) remain. **Deleting derivation history can cause address reuse if you reinstall with the same extended public key.** Retained payment tables have no automatic expiry. Invoice expiry is a payment deadline. Deleting local records leaves invoices at the node, wallet records, backups and confirmed blockchain transactions outside the scope of the cleanup.

Customers should direct privacy requests to the store using the contact in its privacy policy. The plugin does not register a WordPress personal-data exporter or eraser for its payment tables. When handling a request, the store should check the relevant order IDs against those tables and account for copies held by its providers.

= Suggested wording for the store's privacy policy =

Adapt the paragraph below for the payment methods you offer. Fill in the provider and processing location if you use a hosted node, the applicable retention periods or criteria, and a monitored contact. Explain the legal basis and rights applicable to your store and customers before publishing.

“When you choose Bitcoin, we link the payment address or Lightning invoice to your order to provide payment instructions and reconcile your payment. For on-chain payments, opening the wallet link or scanning the QR code shares payment details, including your billing first name if supplied and your order reference, with your wallet. The receiving address and amount of a confirmed transaction are public on the Bitcoin blockchain. For Lightning payments, we request an invoice from [node operator and location]. We retain order-linked payment records for [period or criteria] and payment logs for [period]. To ask about your payment data or exercise your privacy rights, contact [store privacy contact].”

== Changelog ==

= 0.5.2 =
* Keep the payment status and confirmation details readable in narrow order panels and compact the admin payment QR and address.
* Replace the legacy payment-method logos with the faceted PayCrypto.Me P: Bitcoin on-chain and a violet Lightning glyph, retaining network-specific express and QR icons.
* Refresh all directory screenshots from the current demo with the new product thumbnail and payment-method branding.

= 0.5.1 =
* Fix: Align the Lightning webhook preview and its disabled BTCPay field with the gateway settings, and keep the preview text readable on narrow screens.
* Refresh the Bitcoin On-Chain and Lightning settings screenshots in the WordPress.org directory using English demo data.

= 0.5.0 =
* Add separate opt-ins for deleting WooCommerce logs and local Bitcoin payment records on uninstall. Expand privacy and retention disclosures.

= 0.4.3 =
* Refresh the WordPress.org icon, banners and real checkout/settings screenshots with the approved PayCrypto.Me identity.
* Redesign the optional donation section with a compact layout, adaptive light/dark presentation, a full selectable address and non-blocking copy feedback.
* Update the Bitcoin donation address and bundled branded QR, with validated wallet URI and scanning checks. Order-payment QR generation is unchanged.
* Update the donation section translations in all seven bundled locales.
* Update bundled sodium_compat from 2.5.0 to the patched 2.5.2 release and refresh lock metadata.
* Assign explicit PNG/SVG MIME types to WordPress.org directory artwork during SVN preparation.
* Keep the WordPress Tested up to metadata in readme.txt only, as required by the current Plugin Check.

= 0.4.2 =
* Updated WordPress.org contributor metadata.

= 0.4.1 =
* Fixed Bitcoin On-Chain derivation accepting indexes outside the non-hardened BIP32 range; invalid indexes are now rejected before derivation.
* Fixed unsupported extended-public-key prefixes and forced address types falling back to P2WPKH; ambiguous Bitcoin output policies now fail closed with an explicit error.
* Fixed account-boundary validation by requiring configured extended public keys to be account-level BIP32 nodes at depth 3 before deriving `0/index`; root, purpose-level, coin-level and external-chain keys are rejected.

= 0.4.0 =
* Added `Abstract_WC_Gateway_PayCryptoMe::get_order_display_data()` as a public, non-rendering payment presentation data API for add-ons, including an `expires_at_timestamp` field in the shared display projection.

= 0.3.0 =
* Added a versioned payment-status projection capability registry and an invoice-identified, compare-and-swap Lightning status API for the Pro add-on.
* Fixed concurrent Lightning status write-backs so at most one transition action is published, and delayed updates for expired invoices cannot settle a replacement invoice for the same order.

= 0.2.2 =
* Removed legacy on-chain confirmation columns and their unused base-plugin API; confirmation tracking remains exclusive to the Pro add-on.

= 0.2.1 =
* Standardized translatable strings around reusable product-name constants, complete sentence templates, translator context and reorderable placeholders.
* Added block-script translation catalogs for all seven locales, retaining only the two runtime-consumable JSON files per locale instead of redundant source-path copies.
* Documented required translation and schema operations so the distributable passes Plugin Check without warnings; runtime behavior is unchanged.
* Kept the official plugin name unchanged across locales so WordPress.org recognizes the approved “for WooCommerce” trademark pattern in every site language.

= 0.2.0 =
* Fixed-address On-Chain orders are now recorded in the payments table and safely reused on checkout retries.
* Database upgrades now run only on admin/update paths, are serialized and preserve forward-only schema versions during rollback/reinstall cycles.
* Missing payment tables are repaired automatically, and a partially failed database change can no longer be recorded as successful.
* Concurrent submissions of the same fixed-address On-Chain order now reuse the winning persisted payment instead of showing a false failure.
* Concurrent Lightning submissions, including replacement of an expired invoice, now return the invoice that won the persistence race so the customer, order metadata and webhook lookup stay aligned.

= 0.1.2 =
* Fixed: saving the Bitcoin On-Chain settings works again on hosts that display PHP errors (typically a staging site with WP_DEBUG on), where notices from the Bitcoin library printed on the screen and broke the redirect after saving with "headers already sent". The payment page is quiet the same way.
* Changed: the bundled libraries were updated to the versions built for PHP 8.1, which this plugin has always required. They were being installed as if the site ran PHP 7.4, so the download carried an encryption compatibility layer a full version behind and one package that never ran at all. Both are gone and the plugin ships smaller. This is routine maintenance, not a security fix — no advisory applied to the previous versions — and payments, invoices and QR codes work exactly as before.
* Changed: the Bitcoin libraries now come from their official source instead of a personal copy. The same addresses are derived, verified against all 60 address test vectors.
* Fixed: on a site running a PHP older than 8.1, the plugin now stops loading and explains why in the admin instead of taking the whole site down. WordPress already prevents activating or updating it below 8.1, so this only affects a site whose PHP was downgraded after the plugin was already active.

= 0.1.1 =
* On-chain payments now work on hosts without the PHP GMP extension, as long as a single fixed bech32 address (bc1…/tb1…) is configured. On those hosts a perfectly valid xPub used to be rejected as "not valid for the selected network" — a host limitation reported as your mistake.
* A gateway that is enabled but cannot take payments no longer vanishes from checkout without explanation: its settings screen now lists exactly what is missing (a PHP extension, the network/xPub, or the BTCPay/lnd credentials for the selected node type).
* Fixed: a BTCPay Server or lnd URL that could not be stored was saved empty while the page reported "settings saved".
* Fixed: the connection tester now shows the real transport error (DNS, TLS, timeout) instead of "Request failed (HTTP 0)", and says so when a TLS certificate could not be written to a temporary file.
* Fixed: an expired Lightning invoice is shown as "Expired" instead of "Awaiting Payment", without a QR code no wallet would accept. An invoice reused on a checkout retry is no longer shown as expired while the node would still settle it.
* Fixed: copying the payment address on the admin order screen no longer submits the order form.
* Fixed: the On-Chain "Hide for Non-Admin Users" setting no longer hides the Lightning gateway as well.
* Fixed: a database table that fails to install is retried instead of being recorded as up to date, and the warning stays visible until the problem is actually solved.
* Fixed: an order-details panel that fails to render now says so instead of showing an empty page, and a QR code that cannot be generated is logged.
* Changed: admin errors, warnings and logs are always in English now. Everything your customers read stays translated (7 locales, 100%).

= 0.1.0 =
* Initial public release.
* Bitcoin On-Chain gateway: HD address derivation from xPub/yPub/zPub, mainnet and testnet, configurable payment timeout and required confirmations.
* Bitcoin Lightning gateway: BTCPay Server and lnd REST support, with an in-admin connection tester for each.
* WooCommerce Blocks (Cart & Checkout) and classic shortcode checkout support, including an optional Express Payment ("Buy with Bitcoin") button.
* High-Performance Order Storage (Custom Order Tables) compatibility.
* Payment QR code with copy-to-clipboard and "open in wallet" link on the Thank You page, My Account and admin order screens.
* Debug logging via the WooCommerce logger.
* Initial translations for pt_BR, es_ES, fr_FR, de_DE, it_IT, ru_RU and zh_CN.
* Developer extension points reserved for the upcoming Pro add-on, with no effect on the free plugin: amount-enforced lnd invoices, order-details display filters, and dedicated on-chain payment filters. The Pro add-on owns on-chain confirmation tracking and transaction history in its own persistence.

== Upgrade Notice ==

= 0.5.2 =
Updated payment-method icons and a more compact, readable payment panel for WooCommerce orders.

= 0.5.1 =
Fixes the Lightning settings layout and keeps the webhook preview readable on narrow screens.

= 0.5.0 =
Existing records are preserved by default. Review the two optional uninstall choices in either gateway’s Danger Area.

= 0.4.3 =
Refreshed branding and a compact optional donation section with a new Bitcoin donation address. Customer payment processing is unchanged.

= 0.4.2 =
Updates WordPress.org contributor metadata; payment behavior and settings are unchanged.

= 0.4.1 =
Fixes Bitcoin On-Chain extended-public-key and address-index validation. Existing supported account-level xPub/yPub/zPub and tPub/upub/vpub configurations remain compatible; keys must represent the account node at BIP32 depth 3.

= 0.4.0 =
Adds a public payment presentation data API for add-ons. Existing payment behavior, records and settings are unchanged.

= 0.3.0 =
Adds the versioned payment-status projection contract used by the Pro add-on and hardens concurrent Lightning status updates. Existing payment records and settings are preserved.

= 0.2.2 =
Removes legacy on-chain confirmation fields from the On-Chain payment table. Existing payment addresses and derivation records are preserved.

= 0.2.1 =
Improves translation consistency, adds working Checkout block translations for all seven bundled locales, and passes Plugin Check without warnings. Payment behavior and existing settings are unchanged.

= 0.2.0 =
Adds persistent fixed-address payment records and hardens schema repair/upgrades and concurrent On-Chain/Lightning checkout retries. Existing data and settings are preserved on upgrade; rollback to 0.1.2 was validated as safe.

= 0.1.2 =
Fixes saving the On-Chain settings on hosts that display PHP errors. Bundled libraries updated to the versions built for the PHP 8.1 this plugin already requires (maintenance, not a security fix), and a site below PHP 8.1 now gets an explanation instead of a fatal error.

= 0.1.1 =
Admin errors, warnings and logs are now always in English; customer-facing text stays translated. A gateway that cannot take payments explains why instead of vanishing from checkout, and on-chain works without the GMP extension when a fixed bech32 address is configured.

= 0.1.0 =
Initial release.

== Support ==

For support, visit https://paycrypto.me/ or open an issue on the plugin's GitHub repository.

== Credits ==

Developed by PayCrypto.Me — https://paycrypto.me/

Built with the open-source `bitwasp/bitcoin` library for HD key derivation and `endroid/qr-code` for QR code generation.
