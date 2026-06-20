# PayCrypto.Me for WooCommerce — Context for Agents

## Memory files

Detailed context is split into topic files under `.claude/memory/`:

- [project-overview.md](.claude/memory/project-overview.md) — propósito, dois gateways, stack, estrutura de pastas
- [architecture.md](.claude/memory/architecture.md) — hierarquia de classes, fluxo On-Chain, serviços, tabelas DB, blocos Gutenberg, hooks
- [project-wip.md](.claude/memory/project-wip.md) — Lightning incompleto (factory, invoice, webhook), ícones e blocos em WIP
- [dev-workflow.md](.claude/memory/dev-workflow.md) — build JS, PHPUnit, traduções, release, Docker, dependências Composer forked
- [user-lucas.md](.claude/memory/user-lucas.md) — perfil do autor/mantenedor

---

## What this project is

WordPress plugin (GPL-3.0) that adds Bitcoin payment gateways to WooCommerce. Non-custodial: the store owner controls the keys. Version: **0.1.0**. Author: Lucas Rosa (lucas@ipag.com.br).

**Two registered gateways:**
- `paycrypto_me` — Bitcoin On-Chain (HD derivation from xPub/ypub/zpub, mainnet + testnet). **Fully functional.**
- `paycrypto_me_lightning` — Bitcoin Lightning Network (BTCPay Server or lnd REST). **Work in progress — invoice flow not yet implemented.**

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

### Custom DB tables (created on plugin activation)

All prefixed with `{$wpdb->prefix}`:
- `paycrypto_me_bitcoin_wallet_xpubkeys` — (id, xpub, network)
- `paycrypto_me_bitcoin_derivation_indexes` — (derivation_index, wallet_xpubkeys_id)
- `paycrypto_me_bitcoin_transactions_data` — (order_id, payment_address, derivation_index_id, wallet_xpubkeys_id)

### Key services

| Class | File | Does |
|-------|------|------|
| `BitcoinAddressService` | `services/class-bitcoin-address-service.php` | Generate/validate Bitcoin addresses (p2pkh, p2sh-p2wpkh, p2wpkh) from xpub/ypub/zpub using `bitwasp/bitcoin` |
| `PayCryptoMeDBStatementsService` | `services/pay-crypto-me-db-statements-service.php` | CRUD on the 3 custom tables; atomic index reservation via MySQL advisory lock |
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

## What is NOT yet implemented (WIP)

### Lightning Network invoice flow

`LightningPaymentProcessor::process()` (`includes/processors/class-lightning-payment-processor.php`) does **not** create a real Lightning invoice. It currently just returns `payment_data` with a placeholder address. The file contains commented-out code showing the planned BTCPay and lnd REST integration.

**Also missing:** `ProcessorStrategiesFactory` only handles `paycrypto_me` (On-Chain). The `paycrypto_me_lightning` case throws `InvalidArgumentException` — the factory must be extended when the Lightning flow is ready.

**Also missing:** The webhook REST endpoint `paycrypto-me/v1/webhook` referenced in the Lightning settings has not been registered yet.

### Gutenberg blocks for Lightning

`includes/blocks/js/paycrypto_me_lightning-blocks.js` and its compiled output are being actively worked on.

---

## Code style notes

- PHP namespace `PayCryptoMe\WooCommerce` everywhere
- All user-facing strings go through `__()` / `esc_html__()` with text domain `paycrypto-me-for-woocommerce`
- Sanitize all inputs at system boundaries; trust internal data
- No comments explaining WHAT code does; only WHY when non-obvious
- WooCommerce coding standards (PHPCS) — `phpcs` is expected to pass before release
