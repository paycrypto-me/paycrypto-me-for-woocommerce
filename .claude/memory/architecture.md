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

### Serviços principais

| Classe | Arquivo | Responsabilidade |
|--------|---------|-----------------|
| `BitcoinAddressService` | `services/class-bitcoin-address-service.php` | Gerar/validar endereços Bitcoin (p2pkh, p2sh-p2wpkh, p2wpkh) a partir de xpub/ypub/zpub |
| `PayCryptoMeDBStatementsService` | `services/pay-crypto-me-db-statements-service.php` | CRUD nas 3 tabelas customizadas; usa `GET_LOCK` para reserva atômica de índice de derivação |
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

**Why:** A factory `ProcessorStrategiesFactory` atualmente só mapeia `paycrypto_me` → Bitcoin. O gateway Lightning (`paycrypto_me_lightning`) ainda não está conectado à factory — ver [[project-wip]].
