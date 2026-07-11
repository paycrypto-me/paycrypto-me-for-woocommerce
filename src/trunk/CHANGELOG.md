
# Changelog

All notable changes to this project are documented in this file.

## Unreleased

 - Add support for additional blockchain networks (planned).
 - Add automatic payment confirmation (webhook/polling), reserved for a future premium add-on.
 - Add fiat → sats conversion, reserved for a future premium add-on.

## 0.1.0

- Initial public release.
- Bitcoin On-Chain gateway: HD address derivation from xPub/yPub/zPub, mainnet and testnet.
- Bitcoin Lightning gateway: BTCPay Server and lnd REST support, with an in-admin connection tester.
- Support for WooCommerce Blocks and Custom Order Tables.
- Internationalization and translations included.
- Extension points reserved for the upcoming premium add-on, with no effect on the free plugin: an optional `value` (sats) arg honored by lnd invoice creation; `PayCryptoMeDBStatementsService::update_transaction_confirmations()` plus a `paycryptome_bitcoin_status_changed` action for on-chain confirmation tracking; order-details display filters (`paycryptome_order_display_args`, `paycryptome_order_display_data`); dedicated on-chain filters (`paycryptome_bitcoin_payment_uri`, `paycryptome_bitcoin_payment_data`); and the payment gateway is now passed to the `paycryptome_payment_amount`/`paycryptome_payment_data` filters.

## Upgrade Notice

= 0.1.0 =

Initial release.

