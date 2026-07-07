# PayCrypto.Me for WooCommerce — Context for Agents

## Context and guides

- [docs/RELEASE.md](docs/RELEASE.md) — how to submit to WordPress.org (SVN or direct upload)
- [docs/TRANSLATION.md](docs/TRANSLATION.md) — translation commands and status (7 locales, 100%)
- [docs/ADD-NEW-GATEWAY.md](docs/ADD-NEW-GATEWAY.md) — checklist to implement a third gateway

**Status:** v0.1.0 ready for WordPress.org. All gateways functional, 232 tests passing, translations complete. Premium features (webhook/fiat→sats) reserved for add-on plugin — see "Premium add-on" section below.

---

## What this project is

WordPress plugin (GPL-3.0-or-later) that adds Bitcoin payment gateways to WooCommerce. Non-custodial: the store owner controls the keys. Version: **0.1.0**. Author: PayCrypto.Me (contact@paycrypto.me).

**Two registered gateways, both fully functional:**
- `paycrypto_me` — Bitcoin On-Chain (HD derivation from xPub/ypub/zpub, mainnet + testnet).
- `paycrypto_me_lightning` — Bitcoin Lightning Network (BTCPay Server or lnd REST): invoice creation, resolution, persistence, order-details rendering.

Async webhook status updates and fiat→sats conversion are deliberately out of scope for this free plugin — see "Premium add-on" below.

---

## Directory layout

```
paycrypto-me-for-woocommerce/
├── CLAUDE.md                     ← this file
├── src/trunk/                    ← plugin root (everything that ships)
│   ├── paycrypto-me-for-woocommerce.php   ← entrypoint / plugin header
│   ├── includes/                 ← all PHP logic
│   │   ├── abstract-class-wc-gateway-paycrypto-me.php
│   │   ├── class-wc-gateway-paycrypto-me.php         (On-Chain gateway)
│   │   ├── class-wc-gateway-paycrypto-me-lightning.php (Lightning gateway)
│   │   ├── blocks/               ← WooCommerce Gutenberg block classes + JS sources
│   │   │   ├── js/paycrypto_me-blocks.js              ← JS SOURCE (edit here)
│   │   │   └── js/paycrypto_me_lightning-blocks.js    ← JS SOURCE (edit here)
│   │   ├── processors/           ← payment processor classes
│   │   ├── services/             ← BitcoinAddressService, QrCodeService, DBStatementsService, PaymentDisplayDataBuilder, invoice services
│   │   ├── strategies/           ← ProcessorStrategiesFactory (composition root: wires processors + their services via DI)
│   │   ├── validators/           ← LightningConfigValidator
│   │   └── utils/class-asset-manager.php
│   ├── assets/                   ← compiled/static assets (do NOT edit JS/CSS here directly)
│   │   └── blocks/               ← webpack output from includes/blocks/js/
│   ├── templates/                ← WooCommerce PHP templates (checkout, order-details)
│   ├── exceptions/               ← PayCryptoMeException, PayCryptoMePaymentException
│   ├── tests/                    ← PHPUnit (unit-only, custom WP shims, no real WP)
│   ├── package.json              ← npm scripts for JS build
│   ├── webpack.config.js
│   └── composer.json
├── scripts/                      ← shell scripts (build-translations, release, etc.)
├── docs/
└── docker-compose.yml            ← local dev environment
```

**Critical rule:** Never edit files under `src/trunk/assets/blocks/` directly — they are webpack output. Edit the JS sources in `src/trunk/includes/blocks/js/` and run `npm run build`.

---

## Architecture

### PHP class hierarchy

```
WC_Payment_Gateway  (WooCommerce core)
  └── Abstract_WC_Gateway_PayCryptoMe
        ├── WC_Gateway_PayCryptoMe          (id = paycrypto_me)
        └── WC_Gateway_PayCryptoMe_Lightning (id = paycrypto_me_lightning)
```

Namespace: `PayCryptoMe\WooCommerce`. Autoloaded via Composer classmap from `includes/` and `exceptions/`.

### Payment flow (On-Chain)

1. `WC_Gateway_PayCryptoMe::process_payment($order_id)` → `PaymentProcessor::process_payment()`
2. `PaymentProcessor` validates order + gateway (via `PaymentOrderValidator`), fires hooks, calls `ProcessorStrategiesFactory::create($gateway)`
3. Factory maps `paycrypto_me` → `BitcoinProcessorStrategiesFactory`, which is the **composition root**: builds `new BitcoinPaymentProcessor($gateway, new BitcoinAddressService(), new PayCryptoMeDBStatementsService())`. The processor's constructor params are nullable with an internal `new Service()` fallback, so `new BitcoinPaymentProcessor($gateway)` still works — the factory is just where real wiring happens.
4. `BitcoinPaymentProcessor::process()` (split into `resolve_bitcoin_network()` → `resolve_derived_address()` → `build_payment_uri()`):
   - Static address in `network_identifier` → uses it directly
   - xPub/ypub/zpub → `BitcoinAddressService::generate_address_from_xPub()` with an auto-incremented derivation index
   - Index reservation uses `GET_LOCK` / `RELEASE_LOCK` for atomicity
   - Persists via `PayCryptoMeDBStatementsService`
5. `PaymentProcessor` saves `_paycrypto_me_*` order meta and sets status to `pending`

### Payment flow (Lightning)

1. `WC_Gateway_PayCryptoMe_Lightning::process_payment($order_id)` → `PaymentProcessor::process_payment()` → `ProcessorStrategiesFactory::create($gateway)`
2. Factory maps `paycrypto_me_lightning` → `LightningProcessorStrategiesFactory` (composition root), which routes by the `node_type` setting (`btcpay` or `lnd_rest`) and builds `new BtcpayLightningProcessor($gateway, new BtcpayInvoiceService($http, $gateway), $db)` or the lnd equivalent — both processors extend `AbstractLightningProcessor`. Same nullable-with-fallback constructor pattern as the Bitcoin side.
3. `AbstractLightningProcessor::process()` (template method, `final`):
   - Builds invoice args (order_id, memo, expiry + `base_invoice_args($order)`), applies `paycryptome_lightning_btcpay_invoice_args` / `paycryptome_lightning_lnd_invoice_args`
   - Calls `$this->service->create_invoice($args)` — service is `BtcpayInvoiceService` or `LndRestInvoiceService`, both extending `AbstractLightningInvoiceService` (shared constructor + `parse_response()`) and implementing `LightningInvoiceServiceContract`
   - If `payment_request` comes back empty (BTCPay may generate the BOLT11 asynchronously), `resolve_payment_request()` retries a fixed 2 times with 750ms delay before giving up with `PayCryptoMePaymentException`
   - Persists the invoice via `PayCryptoMeLightningDBStatementsService`
4. `PaymentProcessor` saves order meta and sets status to `pending`, same as On-Chain

### Order-details rendering (shared between gateways)

`Abstract_WC_Gateway_PayCryptoMe` owns `render_admin_order_details_section()`/`render_checkout_order_details_section()`; each gateway only implements the abstract `build_order_display_args(\WC_Order $order): ?array` hook (guard-meta check, network label, crypto amount/currency, confirmations required — the parts that actually differ). The shared `PaymentDisplayDataBuilder` (constructor-injected with `QrCodeService`) turns those args into the final display array (QR code, formatted expiry, `crypto_label`) consumed by `templates/order-details/paycrypto-me-order-details.php`.

### Custom DB tables (created on plugin activation)

All prefixed with `{$wpdb->prefix}`:
- `paycrypto_me_bitcoin_wallet_xpubkeys` — (id, xpub, network)
- `paycrypto_me_bitcoin_derivation_indexes` — (derivation_index, wallet_xpubkeys_id)
- `paycrypto_me_bitcoin_transactions_data` — (order_id, payment_address, derivation_index_id, wallet_xpubkeys_id)
- `paycrypto_me_lightning_invoices` — (order_id, node_type, invoice_id, payment_request, status, expires_at, amount_sats)

### Key services

| Class | File | Does |
|-------|------|------|
| `BitcoinAddressService` | `services/class-bitcoin-address-service.php` | Generate/validate Bitcoin addresses (p2pkh, p2sh-p2wpkh, p2wpkh) from xpub/ypub/zpub using `bitwasp/bitcoin` |
| `PayCryptoMeDBStatementsService` | `services/pay-crypto-me-db-statements-service.php` | CRUD on the 3 On-Chain custom tables; atomic index reservation via MySQL advisory lock |
| `PayCryptoMeLightningDBStatementsService` | `services/class-paycrypto-me-lightning-db-statements-service.php` | CRUD on `paycrypto_me_lightning_invoices` (insert/update status/lookup by order or by invoice id) |
| `AbstractLightningInvoiceService` | `services/abstract-class-lightning-invoice-service.php` | Base for the two Lightning invoice services: shared constructor (`HttpClientContract`, `WC_Payment_Gateway`) + `parse_response()`, parameterized by `error_log_label()`/`payment_failed_message()` |
| `BtcpayInvoiceService` | `services/class-btcpay-invoice-service.php` | Creates/resolves/checks BTCPay Server invoices via REST |
| `LndRestInvoiceService` | `services/class-lnd-rest-invoice-service.php` | Creates/checks lnd invoices via its REST API (macaroon auth, optional TLS cert via `request_with_cert()`) |
| `LightningConnectionTester` | `services/class-lightning-connection-tester.php` | Backs the admin "Test connection" AJAX buttons for BTCPay/lnd (via `HttpClientContract`, never `wp_remote_get` directly) |
| `PaymentDisplayDataBuilder` | `services/class-payment-display-data-builder.php` | Turns a gateway's `build_order_display_args()` output into the final order-details display array (QR, formatted expiry, `crypto_label`) — shared by both gateways' render methods on the abstract class |
| `LightningConfigValidator` | `validators/class-lightning-config-validator.php` | Pure/stateless validation logic for the Lightning gateway's 9 `validate_*_field()` settings + `is_lnd_rest_selected()` decision. The gateway keeps one-line public stubs delegating here (required for WooCommerce's `method_exists($this, 'validate_<key>_field')` dispatch) |
| `QrCodeService` | `services/class-qr-code-service.php` | Generate QR code as data URI (uses `endroid/qr-code`) |
| `AssetManager` | `utils/class-asset-manager.php` | Register WooCommerce Gutenberg block scripts/styles |
| `OrderGatewayMatcher` | `utils/class-order-gateway-matcher.php` | Pure helper: does `$order->get_payment_method()` match a given gateway id (accepting the `{id}_express` block variant)? Shared by `PaymentOrderValidator` and both gateways' `build_order_display_args()` guards so the two accepted values never drift apart |
| `AvailablePaymentGatewaysFilter` | `class-available-payment-gateways-filter.php` | Hooks `woocommerce_available_payment_gateways` to hide the alternate PayCryptoMe gateway on "Pay for order" once the order already has payment meta from one of the two — prevents switching payment rails mid-flow (registered once in `WC_PayCryptoMe::__construct()`) |

### Public hooks

| Hook | Type | When |
|------|------|------|
| `paycryptome_before_payment` | action | Before processor runs |
| `paycryptome_after_payment` | action | After processor runs |
| `paycryptome_payment_amount` | filter | Modify order total before payment |
| `paycryptome_payment_data` | filter | Modify payment data array before processing |
| `paycryptome_for_woocommerce_gateway_loaded` | action | When a gateway instance is constructed |
| `paycryptome_lightning_invoice_memo` / `paycryptome_lightning_invoice_expiry` | filter | Customize the Lightning invoice memo/expiry before creation |
| `paycryptome_lightning_btcpay_invoice_args` / `paycryptome_lightning_lnd_invoice_args` | filter | Full invoice args array before `create_invoice()` (includes `amount`/`currency` already merged) |
| `paycryptome_lightning_payment_data` | filter | Final `$payment_data` returned by the Lightning processor |
| `paycryptome_lightning_btcpay_payment_method_id` / `paycryptome_lightning_btcpay_speed_policy` | filter | BTCPay protocol constants that don't flow through the args array |
| `paycryptome_lightning_status_changed` | action | Fired inside `PayCryptoMeLightningDBStatementsService::update_status($order_id, $old_status, $new_status)` after a successful, actual status change — premium add-on seam (webhook/polling consumers react here instead of monkey-patching) |

**Note:** before adding a new filter for Lightning, check whether the value already flows through `base_invoice_args()`/the `invoice_args_filter()` array — only add a dedicated filter for values hardcoded inside a service that never reach that array.

---

## Development workflow

### Build JS (run from `src/trunk/`)

```bash
npm install          # first time
npm run build        # production build → assets/blocks/
npm run dev          # watch mode
```

### Run tests (from `src/trunk/`)

```bash
composer install
./vendor/bin/phpunit
```

Tests use custom WP shims in `tests/_support/` — no real WordPress needed. Config in `phpunit.xml.dist`. Current suite: 232 tests, 515 assertions, 0 errors.

### Translations

```bash
npm run translate        # .pot + .po + .mo
npm run translate:pot
npm run translate:mo
```

### Composer dependencies (important)

Two dependencies come from private/forked VCS repos:
- `lucas-rosa95/bitcoin` — fork of `bitwasp/bitcoin-php` at `https://github.com/lucas-rosa95/bitcoin-php`
- `bitwasp/buffertools` — also from a fork at `https://github.com/lucas-rosa95/buffertools-php`

Running `composer install` in a fresh environment requires access to these GitHub repos.

---

## Premium add-on: scope boundaries and extension points

Two capabilities are **intentionally absent from this free plugin and reserved for a separate premium add-on plugin** — deliberate product-scope decisions, not development gaps. Do not treat them as unfinished work or "fix" them into the free version.

- **Webhook REST endpoint + async status updates.** The Lightning settings UI references `rest_url('paycrypto-me/v1/webhook')`, but there is deliberately no `register_rest_route()` here. Automatic/async invoice-status confirmation (BTCPay webhook push; lnd polling via `wp_schedule_event`) is a premium-tier feature.
- **Fiat → sats conversion.** Invoices are created zero-amount on purpose. Converting the order's fiat total into an `amount_sats` is a premium-tier feature.

**Delivery model:** the premium features ship as a separate plugin that depends on this base and plugs in via hooks/filters — never as `if (is_premium())` gating inside this repo. The base exposes these extension points so the add-on is zero-core-edit:

| Extension point | How the add-on uses it |
|---|---|
| `PayCryptoMeLightningDBStatementsService::get_by_invoice_id()` | Look up an order when a webhook payload only carries the invoice id (`get_by_order_id()` covers the other case) |
| `paycryptome_lightning_status_changed` action (see "Public hooks") | React to a status change (e.g. call `$order->payment_complete()`) without monkey-patching |
| `paycryptome_lightning_btcpay_invoice_args` / `_lnd_invoice_args` filters | Already receive `$order` + `$gateway` — the add-on computes `amount` in sats here for fiat→sats |
| `woocommerce_settings_api_form_fields_paycrypto_me_lightning` (native WooCommerce filter) | Append settings fields (e.g. webhook secret) without touching `init_form_fields()` |
| Dependency guard (`class_exists()` + min-version check) | Add-on's own responsibility, not a base concern |

---

## Known follow-ups

Two low-value, low-risk cleanups are deliberately deferred (pure extract-method, no duplication reduction, zero test coverage as view/config code — not release blockers): `init_form_fields_items()` (Lightning gateway, long method — the shared `init_form_fields()` itself lives in the abstract class) and `enqueue_checkout_styles()` (abstract gateway, long method). The Lightning gateway's 3 HTML generator methods (`generate_node_type_html`/`generate_btcpay_test_button_html`/`generate_lnd_test_button_html`) could similarly move to a render helper if ever prioritized.

---

## Code style notes

- PHP namespace `PayCryptoMe\WooCommerce` everywhere
- All user-facing strings go through `__()` / `esc_html__()` with text domain `paycrypto-me-for-woocommerce`
- Sanitize all inputs at system boundaries; trust internal data
- No comments explaining WHAT code does; only WHY when non-obvious
