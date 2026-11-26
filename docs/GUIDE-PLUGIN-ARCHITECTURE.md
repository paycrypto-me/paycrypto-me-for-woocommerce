# [GUIDE] Plugin architecture

The [schema guide](GUIDE-DB-SCHEMA-UPGRADE.md) owns database lifecycle and storage contracts. The [QA guide](GUIDE-QA.md) owns verification commands. See the [extension contracts](GUIDE-EXTENSION-CONTRACTS.md) for the hook table and the [dependency guide](GUIDE-DEPENDENCIES.md) for Composer.

## Directory layout

```
paycrypto-me-for-woocommerce/
├── AGENTS.md                     ← this file
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
│   │   └── utils/                 ← AssetManager, OrderGatewayMatcher, EnvironmentRequirements
│   ├── assets/                   ← static admin sources and compiled Blocks output
│   │   └── blocks/               ← webpack output from includes/blocks/js/
│   ├── templates/                ← WooCommerce PHP templates (checkout, order-details)
│   ├── exceptions/               ← PayCryptoMeException, PayCryptoMePaymentException
│   ├── tests/                    ← PHPUnit unit tests with WP shims and integration tests against real MySQL
│   ├── uninstall.php             ← deletes settings options (incl. secrets); keeps payment records by default; optional payment/log cleanup
│   ├── package.json              ← npm scripts for JS build
│   ├── webpack.config.js
│   └── composer.json
├── scripts/                      ← shell scripts (build-translations, release, smoke-minimal-host, etc.)
├── docs/
└── docker-compose.yml            ← dev stack (`wordpress`+`wp_db`+`cron`) + ephemeral `release` build service (profile `release`)
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
   - account-level xPub/ypub/zpub/tpub/upub/vpub at BIP32 depth 3 → `BitcoinAddressService::generate_address_from_xPub()` with an auto-incremented non-hardened derivation index; the gateway derives the relative `0/index` path and rejects indexes above `2147483647`
   - On first use of an extended key, concurrent checkouts obtain the same wallet ID through the unique (xPub, network) pair. Each order then reserves its own index for that wallet. A failed wallet write stops checkout before reservation.
   - Index reservation uses `GET_LOCK` / `RELEASE_LOCK` for atomicity
   - Persists via `PayCryptoMeDBStatementsService`
   - An order that already has a row (checkout retry, `order-pay`) reuses the address on file
     as-is and does **not** re-validate it against the currently selected network — switching
     mainnet↔testnet after the order already has a row keeps showing the original address
     (deliberate: the address the customer already saw wins). A concurrent insert that loses the
     race (`insert_address()`/`insert_static_address()` returning false because another request
     just recorded the same order) re-reads and returns that row instead of failing the checkout —
     see `PayCryptoMeDBStatementsService::insert_address()`'s docblock and
     `BitcoinPaymentProcessor::resolve_static_address()`/`resolve_derived_address()`.
5. `PaymentProcessor` saves `_paycrypto_me_*` order meta. An order already pending needs no status write. A retry moves back to pending through WooCommerce CRUD only while the authoritative order row still holds its starting status.

### Payment flow (Lightning)

1. `WC_Gateway_PayCryptoMe_Lightning::process_payment($order_id)` → `PaymentProcessor::process_payment()` → `ProcessorStrategiesFactory::create($gateway)`
2. Factory maps `paycrypto_me_lightning` → `LightningProcessorStrategiesFactory` (composition root), which routes by the `node_type` setting (`btcpay` or `lnd_rest`) and builds `new BtcpayLightningProcessor($gateway, new BtcpayInvoiceService($http, $gateway), $db)` or the lnd equivalent — both processors extend `AbstractLightningProcessor`. Same nullable-with-fallback constructor pattern as the Bitcoin side.
3. `AbstractLightningProcessor::process()` (template method, `final`):
   - First checks `$this->db->get_by_order_id()`: a still-valid (unexpired) existing invoice is reused as-is — no new invoice is created at the node. This mirrors the on-chain `resolve_derived_address()` reuse branch and matters because WooCommerce reuses the same order across checkout retries and the `order-pay` endpoint.
   - Otherwise builds invoice args (order_id, memo, expiry + `base_invoice_args($order)`), applies `paycryptome_lightning_btcpay_invoice_args` / `paycryptome_lightning_lnd_invoice_args`
   - Calls `$this->service->create_invoice($args)` — service is `BtcpayInvoiceService` or `LndRestInvoiceService`, both extending `AbstractLightningInvoiceService` (shared constructor + `parse_response()`) and implementing `LightningInvoiceServiceContract`
   - If `payment_request` comes back empty (BTCPay may generate the BOLT11 asynchronously), `resolve_payment_request()` retries a fixed 2 times with 750ms delay before giving up with `PayCryptoMePaymentException`
   - Persists the invoice via `PayCryptoMeLightningDBStatementsService::insert_invoice()` (new order) or compare-and-swap `replace_invoice()` (order had an expired invoice). If either write loses a double-submit race, the processor re-reads and returns the winner's stored invoice so order meta and webhook lookup stay aligned; a genuine persistence failure with no stored row still raises `PayCryptoMePaymentException`.
4. `PaymentProcessor` saves order meta and applies the same conditional retry transition as On-Chain.

### Reporting failures honestly (validation, availability, environment)

Three rules exist because a valid mainnet `zpub` was once rejected with *"not valid for the
selected network"* on a host without the `gmp` extension — a host defect reported as the store
owner's mistake:

1. **`\Error` is never treated as invalid input.** `BitcoinAddressService::validate_extended_pubkey()`
   / `validate_bitcoin_address()` catch **`\Exception`** only (every genuine "this isn't a valid
   key/address" failure is one: `Base58ChecksumFailure`, `ParserOutOfRange`,
   `InvalidArgumentException`). A missing extension surfaces as `\Error` and propagates;
   `WC_Gateway_PayCryptoMe::validate_xpub_address()`/`validate_network_identifier()` convert it into
   a `PayCryptoMeException`, and `process_admin_options()` reports it as an internal error that is
   explicitly *not* the key's fault. Never widen those catches back to `\Throwable`.
2. **Environment is checked before validation, but only where it matters.**
   `process_admin_options()` skips validation and just saves when the submitted identifier needs
   the GMP math and the extension is absent (blocking the form would also lock the admin out of the
   title, the enable checkbox and everything else on the screen). The dependency is narrower than
   the whole gateway: `BitcoinAddressService::requires_gmp_math()` returns false for a bech32
   identifier, because `bitwasp/bech32` is pure PHP while xPubs and base58 addresses reach
   `Base58::decode()`/`gmp_init()`. So **a host without GMP still takes on-chain payments to a fixed
   bc1/tb1 address** — only xPub derivation is impossible there.
   `validate_bitcoin_address()` routes bech32 to `validate_segwit_address()` for exactly this
   reason: `AddressCreator::fromString()` tries base58 first and its `catch (\Exception)` cannot stop
   the `\Error` raised there. `WC_Gateway_PayCryptoMe::admin_options()` renders a warning on the
   settings screen pointing the merchant at that route.
3. **One source for "why is this gateway hidden".** Each gateway implements
   `unavailability_reasons(): array{environment: string[], configuration: string[]}`;
   `Abstract_WC_Gateway_PayCryptoMe::is_available()` derives from it and
   `render_unavailability_notice()` renders it, so the applied reason and the displayed reason
   cannot drift. Concrete gateways must not re-implement `is_available()`.
   The notice renders **only on that gateway's own settings section**
   (`on_own_settings_screen()`: screen `woocommerce_page_wc-settings` + `$_GET['section']` matching
   `$this->id` **exactly** — a prefix match would put the On-Chain notice back on the Lightning
   screen, since `paycrypto_me` is a prefix of `paycrypto_me_lightning`). It used to render on
   every WooCommerce screen, which meant both gateways posted their notice side by side on each
   other's settings page, on Orders and on Plugins — each one out of its own method's domain.
   A gateway that already prints its environment reasons inline from `admin_options()` returns true
   from `renders_environment_notice_inline()` (the On-Chain one does, via
   `render_missing_extension_notice()`) so the same host defect isn't stated twice on one screen.
   The `admin_notices` hook lives in `WC_PayCryptoMe::render_gateway_unavailability_notices()` — one
   registration that loops the loaded gateways — **never in the gateway constructor**: WooCommerce
   rebuilds every gateway after a settings save (`WC_Settings_Payment_Gateways::save()` →
   `WC_Payment_Gateways::init()`), and WordPress cannot dedupe two callbacks bound to two distinct
   objects, so a per-instance hook printed the warning twice on the screen just saved (and the
   first copy came from the pre-save instance, whose `$this->settings` snapshot was already stale).
   The `enabled` check is made when rendering, not when hooking, for the same reason.

The plugin's own load path follows the same rule. The bundled `vendor/` is resolved for the PHP
floor in `Requires PHP:`, so Composer's generated `platform_check.php` throws from
`vendor/autoload.php` on anything older — a site-wide fatal that never names the plugin. The
entrypoint checks `PHP_VERSION_ID` **before** that require and returns with an admin notice instead,
exactly like the neighbouring guard for a missing `vendor/`. WordPress already blocks activation and
updates below the header floor, so what this catches is a site whose PHP was downgraded afterwards.
That floor is written in four places — plugin header, `readme.txt`, `config.platform.php` and this
guard — and `PhpFloorConsistencyTest` fails if any of them drifts, because they only mean anything
together (see "Composer dependencies" below for what the pin drifting costs).

The same principle applies to silent degradation: `QrCodeService` takes an optional `?callable
$logger` (forwarded by `PaymentDisplayDataBuilder::build()` from the gateway) so a QR that cannot be
drawn is reported instead of producing a blank order page, `HttpClientContract::ERROR_KEY` carries
the transport reason so a DNS/TLS failure isn't shown as "HTTP 0", and `LightningConfigValidator`
raises an error when `esc_url_raw()` empties a URL instead of silently saving nothing.

The inverse case — silencing *accepted third-party* noise — is scoped just as tightly.
`BitcoinAddressService::suppress_vendor_deprecations()` masks **only** `E_DEPRECATED`, and only
around the `bitwasp` calls, because those libraries emit `Use of "parent" in callables` (and
tentative return-type) notices mid-request that printed during the On-Chain settings save and broke
its post-save redirect ("headers already sent"). It restores `error_reporting()` in a `finally` and
**never catches** — a missing-extension `\Error` still propagates under rule 1, and the eventual
PHP 9 fatal (a thrown `Error`, not a diagnostic) is not hidden. It is not a general tool: never
widen it to silence our own deprecations. See
[docs/archive/DONE-CRYPTO-DEPRECATION-CONTINGENCY.md](archive/DONE-CRYPTO-DEPRECATION-CONTINGENCY.md)
(local archive, see the QA guide for recovery).

### Order-details rendering (shared between gateways)

`Abstract_WC_Gateway_PayCryptoMe` owns `render_admin_order_details_section()`/`render_checkout_order_details_section()`; each gateway only implements the abstract `build_order_display_args(\WC_Order $order): ?array` hook (guard-meta check, network label, crypto amount/currency, confirmations required — the parts that actually differ). The shared `PaymentDisplayDataBuilder` (constructor-injected with `QrCodeService`) turns those args into the final display array (QR code, formatted expiry, `crypto_label`) consumed by `templates/order-details/paycrypto-me-order-details.php`.

That template renders in two very different contexts: the customer's order page (no surrounding form) and the admin order screen, where it sits **inside WooCommerce's order `<form>`**. Every `<button>` there must therefore carry an explicit `type="button"` — the HTML default is `submit`, so the copy-address button used to save the order and answer "Order updated." on every click. `OrderDetailsTemplateMarkupTest` pins this, since `wc_get_template()` is stubbed in the unit suite and nothing else can see the markup.

The order presentation is recomputed on read. `get_order_display_data()` returns null for checkout drafts, other methods and missing identifiers. Its additional keys are `order_status` (WooCommerce slug), `payment_state` (localized by the template), `payment_action` (`pay`, `retry`, `none`) and `refunded_amount` (decimal string in the order currency). Consumers must gate payment controls on `payment_action`. The identifier and URI remain historical references. `is_expired` describes the request deadline even if the order has since been paid. Filters that change eligibility should update the action and state together. Native retry URLs are generated only in the authorized customer view.

Stable `payment_state` values are `refunded`, `cancelled`, `paid`, `on_hold`, `failed`, `no_payment_due`, `expired`, `unavailable`, `awaiting_payment` and `order_status`. The last value delegates the human-readable label to WooCommerce. Partial refunds are an additional amount; a paid order stays paid after a partial refund.

The active theme override remains `woocommerce/order-details/paycrypto-me-order-details.php`. Closed orders and native retries use `woocommerce/order-details/paycrypto-me-order-summary.php`. Its bundled layout exposes the historical reference only in admin. Neither rendering nor projection writes an order or an invoice. WooCommerce manages stock and status transitions.

### Uninstall and data retention

Keep visibility and debugging with general settings. Reserve the Danger Area for actions with data-loss consequences. Both gateways use the same per-site option, `paycrypto_me_uninstall_cleanup`. WordPress deletion removes gateway settings and credentials. Saving consent requires `manage_options`, the nonce for the current gateway and successful form validation. Each unchecked or absent choice preserves its corresponding records at uninstall. For the merchant-facing retention rules and wallet address reuse warning, maintain [the plugin readme](../src/trunk/readme.txt).

When changing storage, review `UninstallCleanup` alongside the writer. Match Base order meta keys exactly in classic and HPOS storage, including synchronized copies. Match the exact WooCommerce log source in both native handlers. Keep consent until cleanup succeeds so deletion can be retried. On multisite, a shared log directory requires consent from every site using it before its files can be removed.

### Key services

| Class | File | Does |
|-------|------|------|
| `BitcoinAddressService` | `services/class-bitcoin-address-service.php` | Generate/validate Bitcoin addresses (p2pkh, p2sh-p2wpkh, p2wpkh) from account-level xpub/ypub/zpub/tpub/upub/vpub nodes at BIP32 depth 3 using `bitwasp/bitcoin`; fail closed on unsupported policies and non-hardened indexes outside `0..2147483647`; `requires_gmp_math()`/`validate_segwit_address()` keep the bech32 path usable on hosts without the GMP extension |
| `PayCryptoMeDBStatementsService` | `services/pay-crypto-me-db-statements-service.php` | CRUD on the 3 On-Chain custom tables; atomic index reservation via MySQL advisory lock; `release_derivation_index()` refunds a reserved index if derivation/persistence fails afterward, so a systemic failure (missing GMP, invalid xpub) can't burn through the wallet's BIP-44 gap limit; `insert_static_address()` records a fixed-address payment through the same `insert_address()` INSERT and `exists_for_order()` guard, using the `WALLET_ID_STATIC_ADDRESS` sentinel |
| `PayCryptoMeLightningDBStatementsService` | `services/class-paycrypto-me-lightning-db-statements-service.php` | CRUD on `paycrypto_me_lightning_invoices`; `transition_status()` compare-and-swaps `order_id` + invoice identity + expected status and returns a typed outcome, so concurrent write-backs emit at most one transition and a delayed webhook cannot settle a replacement invoice; `update_status()` remains as a deprecated compatibility wrapper |
| `PaymentStatusProjectionCapabilities` | `contracts/class-payment-status-projection-capabilities.php` | Versioned discovery API for add-ons: Lightning invoice CAS is v1 and on-chain confirmation projection is explicitly absent (`0`) |
| `LightningStatusTransitionResult` | `invoices/class-lightning-status-transition-result.php` | Immutable result of a Lightning projection attempt: `applied`, `already_applied`, `conflict`, `not_found` or `error`, with invoice/current-state diagnostics |
| `AbstractLightningInvoiceService` | `services/abstract-class-lightning-invoice-service.php` | Base for the two Lightning invoice services: shared constructor (`HttpClientContract`, `WC_Payment_Gateway`) + `parse_response()`, parameterized by `error_log_label()`/`payment_failed_message()` |
| `BtcpayInvoiceService` | `services/class-btcpay-invoice-service.php` | Creates/resolves/checks BTCPay Server invoices via REST |
| `LndRestInvoiceService` | `services/class-lnd-rest-invoice-service.php` | Creates/checks lnd invoices via its REST API (macaroon auth, optional TLS cert via `request_with_cert()`) |
| `LightningConnectionTester` | `services/class-lightning-connection-tester.php` | Backs the admin "Test connection" AJAX buttons for BTCPay/lnd (via `HttpClientContract`, never `wp_remote_get` directly) |
| `PaymentDisplayDataBuilder` | `services/class-payment-display-data-builder.php` | Turns a gateway's `build_order_display_args()` output into the final order-details display array (QR, formatted expiry, `is_expired`, `crypto_label`) — shared by both gateways' render methods on the abstract class. Expiry comes from `_paycrypto_me_payment_expires_ts` (absolute, written by the Lightning processor) when present; the legacy `_paycrypto_me_payment_expires_at` hours meta is only a fallback, because it is anchored to the order's creation date and a reused invoice's remaining hours don't start there |
| `LightningConfigValidator` | `validators/class-lightning-config-validator.php` | Pure/stateless validation logic for the Lightning gateway's 9 `validate_*_field()` settings + `is_lnd_rest_selected()` decision. The gateway keeps one-line public stubs delegating here (required for WooCommerce's `method_exists($this, 'validate_<key>_field')` dispatch) |
| `QrCodeService` | `services/class-qr-code-service.php` | Generate QR code as data URI (uses `endroid/qr-code`) |
| `AssetManager` | `utils/class-asset-manager.php` | Register WooCommerce Gutenberg block scripts/styles |
| `EnvironmentRequirements` | `utils/class-environment-requirements.php` | Which PHP extensions a capability needs (`gmp` on-chain; `gd`/`iconv`/`fileinfo` for QR) and which the host is missing, plus `describe()` for the user-facing message. Single source for the settings-save guard, `unavailability_reasons()` and `QrCodeService` — a missing extension must never be reported as bad user input |
| `DbInstaller` | `services/class-db-installer.php` | Activation/upgrade of the 4 custom tables + the failed-install admin notice; owns `DB_VERSION`. `activate()` (zero-argument, the activation hook target) force-repairs regardless of the recorded version; `install(bool $force = false)` is serialized on the table set’s `AdvisoryLockName::install()` lock; `maybe_upgrade()` is forward-only (`version_compare`), hooked on `admin_init`/`upgrader_process_complete` (never the front-end path), and throttled-probes (`paycrypto_me_db_health_check`, 12h) that the 4 tables still exist even when the version is current; `is_current()` is what other code asks before depending on a newer schema; `tables()` is the one source for the 4 bare table names |
| `DbDeltaRunner` | `services/class-db-delta-runner.php` | Runs one table's `dbDelta()` and verifies it actually applied: `$wpdb->last_error` first, then a `dbDelta($sql, false)` dry run for anything still structurally missing (F5 in the schema guide) — `$wpdb->last_error` alone misses a failing non-final statement. Used by both `*GatewayActivate::activate()` methods, one call per table |
| `OrderGatewayMatcher` | `utils/class-order-gateway-matcher.php` | Pure helper: does `$order->get_payment_method()` match a given gateway id (accepting the `{id}_express` block variant)? Shared by `PaymentOrderValidator` and both gateways' `build_order_display_args()` guards so the two accepted values never drift apart |
| `AvailablePaymentGatewaysFilter` | `class-available-payment-gateways-filter.php` | Hooks `woocommerce_available_payment_gateways` to hide the alternate PayCryptoMe gateway on "Pay for order" once the order already has payment meta from one of the two — prevents switching payment rails mid-flow (registered once in `WC_PayCryptoMe::__construct()`) |

**Gateway registration:** `WC_PayCryptoMe::add_gateway()` always registers both gateways. It used to read the On-Chain gateway's `hide_for_non_admin_users` and, when set, register *neither* — silently hiding Lightning too, ignoring Lightning's own setting. `Abstract_WC_Gateway_PayCryptoMe::is_available()` applies each gateway's own value, which is the only place that decision belongs.
