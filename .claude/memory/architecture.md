---
name: architecture
description: "Arquitetura PHP do plugin — classes principais, responsabilidades e como se relacionam"
metadata: 
  node_type: memory
  type: project
  originSessionId: bb8519f4-c6c4-4565-868e-f947b0f2ee52
---

## Arquitetura PHP

### Hierarquia de classes dos gateways

```
WC_Payment_Gateway (WooCommerce)
  └── Abstract_WC_Gateway_PayCryptoMe        (abstract-class-wc-gateway-paycrypto-me.php)
        ├── WC_Gateway_PayCryptoMe            (On-Chain, id=paycrypto_me)
        └── WC_Gateway_PayCryptoMe_Lightning  (Lightning, id=paycrypto_me_lightning)
```

### Fluxo de pagamento (On-Chain)

1. `WC_Gateway_PayCryptoMe::process_payment($order_id)` → delega para `PaymentProcessor::process_payment`
2. `PaymentProcessor` valida pedido e gateway, dispara hooks, chama `ProcessorStrategiesFactory::create($gateway)`
3. Para `paycrypto_me` → `BitcoinProcessorStrategiesFactory` → retorna `BitcoinPaymentProcessor`
4. `BitcoinPaymentProcessor::process()`:
   - Se `network_identifier` é um endereço estático → usa direto
   - Se é xPub → usa `BitcoinAddressService::generate_address_from_xPub()` com índice de derivação incremental
   - Persiste índice e endereço via `PayCryptoMeDBStatementsService`
5. `PaymentProcessor` salva metadados no pedido (`_paycrypto_me_*`) e status para `pending`

### Fluxo de pagamento (Lightning)

1. `LightningProcessorStrategiesFactory::create($gateway)` mapeia `node_type` (`btcpay` ou `lnd_rest`) → `BtcpayLightningProcessor` ou `LndRestLightningProcessor`, ambos extends `AbstractLightningProcessor`.
2. `AbstractLightningProcessor::process()` monta `$args` (order_id, memo, expiry + o que `base_invoice_args($order)` devolver) e aplica `apply_filters($this->invoice_args_filter(), $args, $order, $this->gateway)` **antes** de chamar `$this->service->create_invoice($args)` — ou seja, qualquer chave que `base_invoice_args()` já tenha preenchido (ex: `amount`, `currency` no caso do BTCPay) **já é filtrável** por quem hookar `paycryptome_lightning_btcpay_invoice_args` / `paycryptome_lightning_lnd_invoice_args`. Não precisa de filtro dedicado por campo — é só usar esse.
3. Se `create_invoice()` volta com `payment_request` vazio (BTCPay pode gerar o invoice Lightning de forma assíncrona), `resolve_payment_request()` tenta resolver com um retry mínimo fixo (2 tentativas, 750ms) antes de desistir com `PayCryptoMePaymentException`.
4. Constantes de protocolo que **não** passam pelo array `$args` (porque vivem só dentro do `BtcpayInvoiceService`, não em `base_invoice_args()`) precisam de filtro próprio dentro do service — ex: `paymentMethodId` (`paycryptome_lightning_btcpay_payment_method_id`, default configurável também via setting `btcpay_payment_method_id`) e `speedPolicy` (`paycryptome_lightning_btcpay_speed_policy`).

**Regra geral:** antes de adicionar um filtro novo, veja se o valor já passa pelo `$args` de `base_invoice_args()`/`invoice_args_filter()` — só crie um filtro dedicado para valores que nunca chegam a esse array (constantes hardcoded dentro do service).

### Serviços principais

| Classe | Arquivo | Responsabilidade |
|--------|---------|-----------------|
| `BitcoinAddressService` | `services/class-bitcoin-address-service.php` | Gerar/validar endereços Bitcoin (p2pkh, p2sh-p2wpkh, p2wpkh) a partir de xpub/ypub/zpub |
| `PayCryptoMeDBStatementsService` | `services/pay-crypto-me-db-statements-service.php` | CRUD nas 3 tabelas customizadas; usa `GET_LOCK` para reserva atômica de índice de derivação |
| `PayCryptoMeLightningDBStatementsService` | `services/class-paycrypto-me-lightning-db-statements-service.php` | CRUD em `paycrypto_me_lightning_invoices`: `insert_invoice`/`get_by_order_id` (cacheado)/`get_by_invoice_id` (sem cache, lookup pontual)/`update_status` (dispara `paycryptome_lightning_status_changed` em mudança real) |
| `BtcpayInvoiceService` / `LndRestInvoiceService` | `services/class-btcpay-invoice-service.php` / `services/class-lnd-rest-invoice-service.php` | Criam/resolvem/checam invoices via REST (BTCPay ou lnd), implementam `LightningInvoiceServiceContract` |
| `LightningConnectionTester` | `services/class-lightning-connection-tester.php` | Testa conectividade BTCPay/lnd para os botões "Test connection" do admin (via `HttpClientContract`, nunca `wp_remote_get` direto) |
| `QrCodeService` | `services/class-qr-code-service.php` | Gerar QR code como data URI (usa `endroid/qr-code`) |
| `AssetManager` | `utils/class-asset-manager.php` | Registrar scripts/styles dos blocos WooCommerce |

### Tabelas no banco de dados

Prefixo `{$wpdb->prefix}`:
- `paycrypto_me_bitcoin_wallet_xpubkeys` — xpubs cadastrados (id, xpub, network)
- `paycrypto_me_bitcoin_derivation_indexes` — índices reservados por carteira (derivation_index, wallet_xpubkeys_id)
- `paycrypto_me_bitcoin_transactions_data` — endereço gerado por pedido (order_id, payment_address, derivation_index_id, wallet_xpubkeys_id)

### Blocos WooCommerce (Gutenberg)

- Classe PHP base: `Abstract_WC_PayCryptoMe_Blocks` → `WC_Gateway_PayCryptoMe_Blocks` / `WC_Gateway_PayCryptoMe_Lightning_Blocks`
- JS fonte: `includes/blocks/js/paycrypto_me-blocks.js` e `paycrypto_me_lightning-blocks.js`
- JS compilado: `assets/blocks/paycrypto_me-blocks.js` (nunca editar diretamente)
- `AssetManager::register_block_assets($slug)` registra JS e CSS; `get_block_handles($slug)` retorna só handles de script (WooCommerce Blocks exige isso)

### Hooks públicos

- `paycryptome_before_payment` / `paycryptome_after_payment` — actions disparadas no fluxo
- `paycryptome_payment_amount` — filter para modificar o valor antes do pagamento
- `paycryptome_payment_data` — filter para modificar os dados antes de processar
- `paycryptome_for_woocommerce_gateway_loaded` — action quando gateway é inicializado
- `paycryptome_lightning_invoice_memo` / `paycryptome_lightning_invoice_expiry` — filters para memo/expiry do invoice Lightning
- `paycryptome_lightning_btcpay_invoice_args` / `paycryptome_lightning_lnd_invoice_args` — filter do array completo de args (inclui `amount`/`currency` já mesclados) antes de chamar `create_invoice()`
- `paycryptome_lightning_payment_data` — filter final do `$payment_data` retornado pelo processor Lightning
- `paycryptome_lightning_btcpay_payment_method_id` / `paycryptome_lightning_btcpay_speed_policy` — filters de constantes de protocolo do BTCPay que não passam pelo `$args` (ver seção Lightning acima)
- `paycryptome_lightning_status_changed($order_id, $old_status, $new_status)` — action disparada dentro de `PayCryptoMeLightningDBStatementsService::update_status()`, só quando a linha existia e o status realmente mudou. Costura para o add-on premium (webhook/polling) reagir sem monkey-patch — ver `docs/architecture-audit-plan.md` § "Costuras para o add-on premium".

Cada gateway Lightning (BTCPay, lnd) tem sua própria classe de service em `services/class-btcpay-invoice-service.php` / `services/class-lnd-rest-invoice-service.php`, implementando `LightningInvoiceServiceContract` (`create_invoice`, `resolve_payment_request`, `get_invoice_status`).
