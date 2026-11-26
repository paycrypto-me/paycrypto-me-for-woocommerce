# PayCrypto.Me for WooCommerce

Base plugin for Bitcoin On-Chain (`paycrypto_me`) and Lightning (`paycrypto_me_lightning`, BTCPay Server or lnd REST). Payment funds go directly to merchant-controlled wallets and nodes. Async confirmation, webhooks, on-chain monitoring and fiat-to-sats conversion belong to the separate Pro add-on.

WordPress.org serves **0.6.1**. The repository contains the current version **0.6.1**.
PHP namespace `PayCryptoMe\WooCommerce`.

## Development workflow

1. Read the guide for the area being changed and identify the behavior, persistence and operational contracts the change crosses. Reuse the smallest existing test and fixture that exercise the real failure boundary.
2. Reproduce findings against the relevant baseline and record observable impact. If behavior depends on MySQL, WooCommerce or the browser, use that boundary to prove it. Preserve unrelated findings in their own issue with evidence and scope.
3. Run the focused regression, then the applicable suites in the [QA matrix](docs/GUIDE-QA.md#verification-matrix). Track the tested commit or ZIP digest, cases, outcomes and skips. A skip or a green job that exercised zero tests gives no coverage.
4. Write repository documentation in English. Update the canonical guide that owns the contract. Run `./scripts/check-docs-drift.sh`. Keep this entry point within **16 KiB**. Put operational detail and historical evidence in the linked guides and records.
5. For a release, start at the [release runbook](docs/GUIDE-RELEASE.md). Validate the exact ZIP, prepare SVN, hand off credentialed publication and compare the public download. Follow the approval already given for external writes.

## Choose the guide

| Working on | Read |
|---|---|
| Gateways, availability, orders, templates or storage retention | [Architecture](docs/GUIDE-PLUGIN-ARCHITECTURE.md) |
| Public hooks or Pro integration | [Extension contracts](docs/GUIDE-EXTENSION-CONTRACTS.md) |
| Composer, PHP compatibility or crypto dependencies | [Dependencies](docs/GUIDE-DEPENDENCIES.md) |
| Tables, migrations, advisory locks or install and repair | [Schema and storage](docs/GUIDE-DB-SCHEMA-UPGRADE.md) |
| Unit, MySQL, browser, audit evidence or maintaining docs | [QA and documentation](docs/GUIDE-QA.md) |
| Version bump, package, Git tags or WordPress.org SVN | [Release](docs/GUIDE-RELEASE.md) |
| Adding a payment method | [Add a gateway](docs/GUIDE-ADD-NEW-GATEWAY.md) |
| Translatable strings or catalogs | [i18n conventions](docs/GUIDE-I18N-CONVENTIONS.md), [translations](docs/GUIDE-TRANSLATION.md) |
| WordPress.org artwork or the admin donation card | [Directory branding](docs/GUIDE-DIRECTORY-BRANDING.md), [donation branding](docs/GUIDE-DONATION-BRANDING.md) |

## Rules that apply across the plugin

- The distributable source starts in `src/trunk`. Edit Blocks sources in `src/trunk/includes/blocks/js` and build with `npm run build` from `src/trunk`. Generated `src/trunk/assets/blocks` files come from webpack.
- Keep stable payment identities on retries and under concurrent requests. Orders that already have an address or still-valid invoice reuse it. Use WooCommerce CRUD for status transitions and preserve HPOS and classic storage behavior.
- Preserve unsettled and historical payment records across changes. Schema work must cover fresh install and upgrade. Pro reads the Base contract through documented hooks and services.
- Report missing PHP capabilities and infrastructure failures accurately. Catch `\Exception` for invalid Bitcoin input, keeping `\Error` available to the environment error path. Fixed bech32 checkout can run without GMP.
- Payment detail rendering is read-only. Gate payment UI on `payment_action`, and use explicit `type="button"` inside the admin order form.
- Merchant consent governs optional deletion of payment history and logs. Check writers, readers and uninstall together for persistence changes.
- Use the project text domain for customer-facing and settings UI strings. Read the i18n guide for diagnostic strings, placeholders and catalog generation.
- Run browser and SQL QA against disposable stores, with synthetic wallet and node fixtures. Keep sessions and credentials out of shared evidence. For diagrams, prefer the local Mermaid MCP server when available.
