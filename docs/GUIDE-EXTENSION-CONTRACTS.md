# [GUIDE] Public hooks and the Pro add-on

## Public hooks

| Hook | Type | When |
|------|------|------|
| `paycryptome_before_payment` | action | Before processor runs |
| `paycryptome_after_payment` | action | After processor runs |
| `paycryptome_payment_amount` | filter | Modify order total before payment. Args: `($amount, $order_id, $gateway)` |
| `paycryptome_payment_data` | filter | Modify payment data array before processing. Args: `($payment_data, $order_id, $gateway)`. This is where a third party fills `crypto_amount` (fiat→crypto) — it flows into the on-chain BIP21 URI and is persisted as order meta |
| `paycryptome_for_woocommerce_gateway_loaded` | action | When a gateway instance is constructed |
| `paycryptome_order_display_args` | filter | Order-details render, **pre-build**: `($args, $order, $gateway)` — augment the gateway's display args before `PaymentDisplayDataBuilder::build()` (e.g. flip `show_expiry`, set `crypto_amount`) |
| `paycryptome_order_display_data` | filter | Order-details render, **post-build**: `($display_data, $order, $gateway)` — adjust already-computed display fields (QR, labels) |
| `paycryptome_bitcoin_payment_uri` | filter | On-chain BIP21 URI: `($uri, $order, $payment_address, $crypto_amount, $gateway)` |
| `paycryptome_bitcoin_payment_data` | filter | Final `$payment_data` returned by the Bitcoin processor: `($payment_data, $order, $gateway)` — on-chain analogue of `paycryptome_lightning_payment_data` (fires on both static-address and derived-address paths) |
| `paycryptome_lightning_invoice_memo` / `paycryptome_lightning_invoice_expiry` | filter | Customize the Lightning invoice memo/expiry before creation |
| `paycryptome_lightning_btcpay_invoice_args` / `paycryptome_lightning_lnd_invoice_args` | filter | Full invoice args array before `create_invoice()` (includes `amount`/`currency` already merged). `LndRestInvoiceService::create_invoice()` also honors an optional `value` key (sats) — free plugin never sets it; the Pro fiat→sats add-on sets it here to enforce the invoice amount. |
| `paycryptome_lightning_payment_data` | filter | Final `$payment_data` returned by the Lightning processor |
| `paycryptome_lightning_btcpay_payment_method_id` / `paycryptome_lightning_btcpay_speed_policy` | filter | BTCPay protocol constants that don't flow through the args array |
| `paycryptome_lightning_status_changed` | action | `($order_id, $old_status, $new_status, $invoice_id)`, fired only when `PayCryptoMeLightningDBStatementsService::transition_status()` applies its atomic CAS. At most one action is emitted per applied transition; it is a post-write notification, not durable delivery or a distributed lock. Existing 3-argument listeners remain compatible. |

**Note:** before adding a new filter for Lightning, check whether the value already flows through `base_invoice_args()`/the `invoice_args_filter()` array — only add a dedicated filter for values hardcoded inside a service that never reach that array.

---

## Pro add-on: scope boundaries and extension points

Three capabilities are **intentionally absent from this free plugin and reserved for a separate Pro add-on plugin** — deliberate product-scope decisions, not development gaps. Do not treat them as unfinished work or "fix" them into the free version. The approved implementation plan for that separate add-on lives in its own repo, at [`docs/PREMIUM-ADDON.md`](https://github.com/paycrypto-me/paycrypto-me-pro/blob/main/docs/PREMIUM-ADDON.md) — the plan doc itself kept its original filename/history (the repo was renamed from `paycrypto-me-premium` alongside the product rename), see its own naming note.

- **Webhook REST endpoint + async status updates.** The Lightning settings UI references `rest_url('paycrypto-me/v1/webhook')`, but there is deliberately no `register_rest_route()` here. Automatic/async invoice-status confirmation (BTCPay webhook push; lnd polling via `wp_schedule_event`) is a Pro-tier feature.
- **On-chain confirmation tracking.** The base records the receiving address and derivation context only. The Pro add-on owns confirmation polling, transaction history and confirmation-specific persistence; it must not add those fields back to the base table.
- **Fiat → sats conversion.** Invoices are created zero-amount on purpose. Converting the order's fiat total into an `amount_sats` is a Pro-tier feature.

**Delivery model:** the Pro features ship as a separate plugin that depends on this base and plugs in via hooks/filters — never as `if (is_premium())` gating inside this repo. The base exposes these extension points so the add-on is zero-core-edit:

| Extension point | How the add-on uses it |
|---|---|
| `PaymentStatusProjectionCapabilities::all()` | Detect `lightning_invoice_status_cas = 1` before projecting status; absent/zero degrades without fatal. `onchain_confirmation_progress = 0` is deliberate: that state remains only in Pro. |
| `PayCryptoMeLightningDBStatementsService::transition_status()` | Project a Pro decision into the exact Base invoice using atomic compare-and-swap; inspect the typed result instead of guessing from a boolean. |
| `paycryptome_lightning_status_changed` action (see "Public hooks") | Optional post-write notification. Never use it as the only durable trigger for `$order->payment_complete()`; Pro retries from its own persisted decision. |
| `paycryptome_lightning_btcpay_invoice_args` / `_lnd_invoice_args` filters | Already receive `$order` + `$gateway` — the add-on computes `amount` in sats here for fiat→sats. For lnd, set the `value` key (sats) to enforce the amount on the invoice (BTCPay converts fiat itself, so it needs no `value`). |
| `woocommerce_settings_api_form_fields_paycrypto_me_lightning` (native WooCommerce filter) | Append settings fields (e.g. webhook secret) without touching `init_form_fields()` |
| Dependency guard (`class_exists()` + min-version check) | Add-on's own responsibility, not a base concern |

The base payment-details template currently renders only the base payment data. A future official
rendering slot may let Pro append its own transaction section; until that contract exists, Pro must
not replace the base template or assume that arbitrary keys added through the display-data filters
will be rendered.

**The base owns the stable integration boundary.** Pro functionality remains a separate plugin and
must use documented hooks, filters and read-only base services rather than editing base internals or
sharing its schema. New base extension points, such as an official order-details rendering slot,
must be added deliberately and documented here before the Pro add-on depends on them. Licensing SDK
remains an add-on concern (that is why the Freemius SDK lives only there). See §2 and §8.1 of
[`docs/PREMIUM-ADDON.md`](https://github.com/paycrypto-me/paycrypto-me-pro/blob/main/docs/PREMIUM-ADDON.md)
in the add-on's repo.

---
