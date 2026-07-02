# PayCrypto.Me for WooCommerce — Context for Agents

## Memory files

Detailed context is split into topic files under `.claude/memory/`:

- [project-overview.md](.claude/memory/project-overview.md) — propósito, dois gateways, stack, estrutura de pastas
- [architecture.md](.claude/memory/architecture.md) — hierarquia de classes, fluxo On-Chain, serviços, tabelas DB, blocos Gutenberg, hooks
- [project-wip.md](.claude/memory/project-wip.md) — Lightning core implementado (M1–M5); webhook REST, conversão fiat→sats e blocos Gutenberg do Lightning ainda em WIP
- [dev-workflow.md](.claude/memory/dev-workflow.md) — build JS, PHPUnit, traduções, release, Docker, dependências Composer forked
- [user-lucas.md](.claude/memory/user-lucas.md) — perfil do autor/mantenedor
- [docs/architecture-audit-plan.md](docs/architecture-audit-plan.md) — auditoria SOLID/DRY e de cobertura de testes, com roteiro faseado de remediação (testes críticos antes de quebrar as god classes)

---

## What this project is

WordPress plugin (GPL-3.0) that adds Bitcoin payment gateways to WooCommerce. Non-custodial: the store owner controls the keys. Version: **0.1.0**. Author: Lucas Rosa (lucas.rosa95br@gmail.com).

**Two registered gateways:**
- `paycrypto_me` — Bitcoin On-Chain (HD derivation from xPub/ypub/zpub, mainnet + testnet). **Fully functional.**
- `paycrypto_me_lightning` — Bitcoin Lightning Network (BTCPay Server or lnd REST). **Core invoice flow fully implemented** (invoice creation, resolution, persistence, order-details rendering). Remaining gaps: webhook REST endpoint for async status updates, fiat→sats conversion — see "What is NOT yet implemented" below.

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
│   │   ├── services/             ← BitcoinAddressService, QrCodeService, DBStatementsService
│   │   ├── strategies/           ← ProcessorStrategiesFactory (maps gateway id → processor)
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

### Payment flow (On-Chain, fully implemented)

1. `WC_Gateway_PayCryptoMe::process_payment($order_id)` → `PaymentProcessor::process_payment()`
2. `PaymentProcessor` validates order + gateway, fires hooks, calls `ProcessorStrategiesFactory::create($gateway)`
3. Factory maps `paycrypto_me` → `BitcoinProcessorStrategiesFactory` → `BitcoinPaymentProcessor`
4. `BitcoinPaymentProcessor::process()`:
   - Static address in `network_identifier` → uses it directly
   - xPub/ypub/zpub → `BitcoinAddressService::generate_address_from_xPub()` with an auto-incremented derivation index
   - Index reservation uses `GET_LOCK` / `RELEASE_LOCK` for atomicity
   - Persists via `PayCryptoMeDBStatementsService`
5. `PaymentProcessor` saves `_paycrypto_me_*` order meta and sets status to `pending`

### Payment flow (Lightning, fully implemented)

1. `WC_Gateway_PayCryptoMe_Lightning::process_payment($order_id)` → `PaymentProcessor::process_payment()` → `ProcessorStrategiesFactory::create($gateway)`
2. Factory maps `paycrypto_me_lightning` → `LightningProcessorStrategiesFactory`, which routes by the `node_type` setting (`btcpay` or `lnd_rest`) to `BtcpayLightningProcessor` or `LndRestLightningProcessor` — both extend `AbstractLightningProcessor`
3. `AbstractLightningProcessor::process()` (template method, `final`):
   - Builds invoice args (order_id, memo, expiry + `base_invoice_args($order)`), applies `paycryptome_lightning_btcpay_invoice_args` / `paycryptome_lightning_lnd_invoice_args`
   - Calls `$this->service->create_invoice($args)` — service is `BtcpayInvoiceService` or `LndRestInvoiceService`, both implementing `LightningInvoiceServiceContract`
   - If `payment_request` comes back empty (BTCPay may generate the BOLT11 asynchronously), `resolve_payment_request()` retries a fixed 2 times with 750ms delay before giving up with `PayCryptoMePaymentException`
   - Persists the invoice via `PayCryptoMeLightningDBStatementsService`
4. `PaymentProcessor` saves order meta and sets status to `pending`, same as On-Chain

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
| `PayCryptoMeLightningDBStatementsService` | `services/class-paycrypto-me-lightning-db-statements-service.php` | CRUD on `paycrypto_me_lightning_invoices` (insert/update status/lookup by order) |
| `BtcpayInvoiceService` | `services/class-btcpay-invoice-service.php` | Creates/resolves/checks BTCPay Server invoices via REST |
| `LndRestInvoiceService` | `services/class-lnd-rest-invoice-service.php` | Creates/checks lnd invoices via its REST API (macaroon auth, optional TLS cert) |
| `QrCodeService` | `services/class-qr-code-service.php` | Generate QR code as data URI (uses `endroid/qr-code`) |
| `AssetManager` | `utils/class-asset-manager.php` | Register WooCommerce Gutenberg block scripts/styles |

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

Tests use custom WP shims in `tests/_support/` — no real WordPress needed. Config in `phpunit.xml.dist`.

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

## Scope boundaries: premium roadmap vs. active WIP

The Lightning invoice flow itself (BTCPay + lnd REST, creation/resolution/persistence/order-details rendering) is **done** — see "Payment flow (Lightning, fully implemented)" above. Two capabilities are **intentionally absent from this free version and reserved for a future premium/freemium tier** — they are deliberate product-scope decisions, **not development gaps**. Do not treat them as unfinished work or "fix" them into the free version.

### Reserved for the premium/freemium version (intentional, NOT a gap)

**Delivery model:** these are shipped as a **separate premium add-on plugin** that depends on this base plugin and plugs in via hooks/filters — never as `if (is_premium())` gating inside this repo. The base only needs to expose a few additive extension points to make the add-on zero-core-edit; those are tracked in [docs/architecture-audit-plan.md](docs/architecture-audit-plan.md) under "Costuras para o add-on premium (zero-core-edit)".

**Webhook REST endpoint + async status updates.** The Lightning settings UI references `rest_url('paycrypto-me/v1/webhook')`, but there is deliberately no `register_rest_route()` in the free version. Automatic/async invoice-status confirmation (BTCPay webhook push; lnd polling via `wp_schedule_event`) is a premium-tier feature. The seam exists (the settings reference, the DB `status` column) so the premium layer can plug in without core changes.

**Fiat → sats conversion.** Invoices are created zero-amount on purpose in the free version. Converting the order's fiat total into an `amount_sats` is a premium-tier feature, designed to plug in via the `paycryptome_lightning_btcpay_invoice_args` / `paycryptome_lightning_lnd_invoice_args` filters — no core change required.

### Active WIP (genuine in-progress development, free version)

**Gutenberg blocks for Lightning** — `includes/blocks/js/paycrypto_me_lightning-blocks.js` and its compiled output are being actively worked on. This is the one item here that *is* unfinished free-version work.

### Known architectural debt

See [docs/architecture-audit-plan.md](docs/architecture-audit-plan.md) for a full SOLID/DRY audit and test-coverage gap analysis. Highlights: `WC_Gateway_PayCryptoMe_Lightning` (647 lines) and `PaymentProcessor` (280 lines) are god classes slated for a phased breakup; `PayCryptoMeLightningDBStatementsService` and the Lightning gateway class currently have zero test coverage.

---

## Code style notes

- PHP namespace `PayCryptoMe\WooCommerce` everywhere
- All user-facing strings go through `__()` / `esc_html__()` with text domain `paycrypto-me-for-woocommerce`
- Sanitize all inputs at system boundaries; trust internal data
- No comments explaining WHAT code does; only WHY when non-obvious
- WooCommerce coding standards (PHPCS) — `phpcs` is expected to pass before release
