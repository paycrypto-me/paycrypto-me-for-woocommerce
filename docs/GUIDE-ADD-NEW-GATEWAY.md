# [GUIDE] Add a payment gateway

Use `<X>` for the class suffix and `paycrypto_me_<x>` for the gateway ID. Follow the existing On-Chain and Lightning implementations for naming and file layout.

## 1. Register the gateway

In `src/trunk/paycrypto-me-for-woocommerce.php`, include the new gateway class in `WC_PayCryptoMe::includes()` and append its namespaced class name to the gateway list in `WC_PayCryptoMe::add_gateway()`. Include its Blocks class in `WC_PayCryptoMe::load_blocks_support()`. For new tables, extend the shared installer as described in step 5.

## 2. Implement the gateway class

Extend `Abstract_WC_Gateway_PayCryptoMe`. Set the gateway identity and call the parent constructor as in the existing gateways. It sets up the display builder, settings and order-detail hooks.

| Required method | Responsibility |
|---|---|
| `protected function admin_enqueue_scripts_content(WP_Screen\|null $screen)` | Enqueue this gateway's admin scripts and styles on its screen. |
| `public function get_available_networks()` | Return supported networks. |
| `public function get_available_cryptocurrencies($network = null)` | Return supported currencies by network. |
| `protected function init_form_fields_items()` | Return gateway-specific settings. The base class handles shared settings such as express checkout and administrator visibility. |
| `public function build_order_display_args(\WC_Order $order): ?array` | Provide the gateway-specific inputs below. |

## 3. Supply order display arguments

Return `null` when payment metadata indicates the order has no payment from this gateway. Otherwise return the following inputs to `PaymentDisplayDataBuilder::build()`.

```php
[
    'payment_identifier'     => ..., // Address or invoice ID.
    'payment_uri'            => ..., // Used for the QR code and wallet link.
    'logo_path'              => ..., // Logo embedded in the QR code.
    'crypto_network'         => ..., // Network or Lightning node type.
    'network_label'          => ..., // Human-readable network label.
    'crypto_amount'          => ...,
    'crypto_currency'        => ..., // For example, BTC.
    'confirmations_required' => ...,
]
```

The builder reads `_paycrypto_me_fiat_amount`, `_paycrypto_me_fiat_currency` and `_paycrypto_me_payment_expires_at` from order metadata. Supply those values through the processor. The inherited final method `get_order_display_data()` applies `paycryptome_order_display_args` and `paycryptome_order_display_data` and calls the shared builder. Templates, admin screens and add-ons consume that projection independently of rendering and asset loading. See `OrderDisplayArgsTest` and `PaymentDisplayDataBuilderTest`.

## 4. Dispatch the processor

1. Add a factory under `includes/strategies/` patterned on `BitcoinProcessorStrategiesFactory` or `LightningProcessorStrategiesFactory`. Its static `create(\WC_Payment_Gateway $gateway): GatewayProcessorContract` constructs the production processor and its services.
2. Implement `GatewayProcessorContract::process(\WC_Order $order, array $payment_data): array`. Use optional service dependencies with internal defaults where existing callers construct the processor with only the gateway.
3. Add the gateway ID to the switch in `ProcessorStrategiesFactory::create()`. Unknown IDs throw `InvalidArgumentException`.
4. Return the fields that `PaymentProcessor` persists as `_paycrypto_me_*` metadata, including `fiat_amount`, `fiat_currency` and `payment_expires_at` when applicable.

Before dispatch, `PaymentOrderValidator::validate_order()` requires the order payment method to match the gateway ID or its `_express` variant, a positive fiat amount and a nonempty order currency. Exercise both entry points when the gateway offers express checkout.

## 5. Integrate gateway-owned tables

For a gateway with its own tables, follow `PayCryptoMeBitcoinGatewayActivate` for the table inventory and `dbDelta()` syntax. Include its activator in `DbInstaller::run_install()` and its table names in `DbInstaller::tables()`. The bootstrap registers one activation hook for `DbInstaller::activate()`. Use the [schema guide](GUIDE-DB-SCHEMA-UPGRADE.md) for versioning, postconditions, upgrades and replay tests. Update the [retention contract](GUIDE-PLUGIN-ARCHITECTURE.md#uninstall-and-data-retention) and the distributed readme's storage inventory.

## 6. Validate custom settings

Follow `LightningConfigValidator` for stateless validation independent of WooCommerce. Keep a public `validate_<key>_field($key, $value)` method on the gateway for each custom field and delegate to the validator. WooCommerce discovers these methods on the gateway instance. Exercise a real settings save because unit shims do not establish discovery.

## 7. Integrate checkout Blocks

1. Extend `WC_PayCryptoMe_Blocks` and set `protected $name` to the gateway ID. The base class handles availability, asset registration through `AssetManager` and payment method data.
2. Register the instance on `woocommerce_blocks_payment_method_type_registration`, following `includes/blocks/class-wc-gateway-paycrypto-me-lightning-blocks.php`.
3. Add the gateway entry module under `includes/blocks/js/` and import `createPaymentComponents` from `./paycrypto-blocks-shared.js`.
4. Add the matching JS and SCSS entry to `webpack.config.js`. Run `npm run build` from `src/trunk` to generate `assets/blocks/`.

## 8. Test the gateway

- Define shared WP/WC shims in `tests/_support/wp-helpers.php`. Duplicate definitions with different arities make PHPUnit mocks depend on file load order.
- For services implementing `HttpClientContract`, use `FakeHttpClient`, `http_ok()` and `http_error()` in `tests/_support/fake-http-client.php`. Use `hook_spy_calls()` and `hook_spy_reset()` to check hook calls without dispatch.
- Inject fake services and storage through processor constructors. See `BitcoinPaymentProcessorTest`, `AbstractLightningProcessorTest`, `LightningConfigValidatorTest` and `OrderDisplayArgsTest` for focused examples.
- Run the applicable [QA matrix](GUIDE-QA.md#verification-matrix) cases for real storage, classic and Blocks checkout, retry identity and order display.

## 9. Apply shared contracts

- Localize customer-facing strings and settings labels and descriptions with the plugin text domain. Use English literals for admin diagnostics, logs, diagnostic button results and internal order notes. Follow the [catalog policy](GUIDE-TRANSLATION.md#catalog-scope).
- Use `PayCryptoMeException` for general errors and `PayCryptoMePaymentException` for payment-flow errors. See `src/trunk/exceptions/`.
- The shared processor and gateway constructor already fire `paycryptome_before_payment`, `paycryptome_after_payment`, `paycryptome_payment_amount`, `paycryptome_payment_data` and `paycryptome_for_woocommerce_gateway_loaded`. See the [public hook table](GUIDE-EXTENSION-CONTRACTS.md#public-hooks).
- Access BitWasp through `BitcoinAddressService`, which scopes vendor deprecation suppression to prevent output from breaking redirects. Catch exceptions for invalid input and let missing-extension errors propagate. See the [dependency guide](GUIDE-DEPENDENCIES.md).
- `VendorReplaceGuardTest` scans shipped PHP for references to the removed Murmur/BloomFilter package. `PhpFloorConsistencyTest` checks the four PHP minimum-version declarations. Run both for a change affecting dependencies or runtime requirements.
- For asynchronous status notifications, follow `PayCryptoMeLightningDBStatementsService::transition_status()`. Emit the hook only after a compare-and-swap applies the transition. Consumers still need idempotency and their own durable delivery where required.
