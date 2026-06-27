---
name: project-wip
description: Trabalho em progresso — o que está incompleto ou sendo desenvolvido ativamente
metadata: 
  node_type: memory
  type: project
  originSessionId: bb8519f4-c6c4-4565-868e-f947b0f2ee52
---

## Trabalho em Progresso (WIP)

**Contexto:** O plugin está em versão 0.1.0. O gateway On-Chain está funcional. O fluxo core do Lightning Network foi implementado (M1–M5).

### Lightning Network — implementado (M1–M5)

O fluxo completo de invoice foi implementado:

- `BtcpayInvoiceService` — POST `/api/v1/stores/{id}/invoices`, segunda chamada GET `payment-methods` para extrair BOLT11, `get_invoice_status()` retorna `paid=true` para `Settled`
- `LndRestInvoiceService` — POST `/v1/invoices` com header macaroon, SSL via PEM (tempfile) ou flag `lnd_verify_ssl`, `invoice_id` derivado de `r_hash` base64-url→hex
- `LightningProcessorStrategiesFactory` — roteia `btcpay` ou `lnd_rest` corretamente
- `is_available()` — valida credenciais obrigatórias conforme `node_type`
- Template unificado `paycrypto-me-order-details.php` — recebe `$payment_display_data` normalizado; funciona para On-Chain e Lightning
- `WC_Gateway_PayCryptoMe_Lightning::render_checkout_order_details_section()` — exibe QR + BOLT11 copiável + botão wallet
- 40 testes PHPUnit passando (linha de base era 29)

**Why:** Saber que o fluxo core está completo evita reimplementar o que já existe e ajuda a identificar o que ainda falta.

**How to apply:** Ao trabalhar no Lightning, assumir que `process_payment()` → `AbstractLightningProcessor::process()` → `BtcpayInvoiceService`/`LndRestInvoiceService` → template já funciona. Focar no que ainda está abaixo.

---

### Ainda faltando no Lightning

#### Webhook REST (confirmação assíncrona)
O settings do gateway já referencia `rest_url('paycrypto-me/v1/webhook')` para receber notificações do BTCPay. O endpoint REST ainda não foi registrado/implementado.

- BTCPay: webhook push quando invoice muda de status
- lnd: alternativa é polling via WP-Cron (`wp_schedule_event`)

#### Conversão fiat → sats
Invoices são zero-amount por ora. A camada premium pode injetar `amount_sats` via filtro `paycryptome_lightning_lnd_invoice_args` / `paycryptome_lightning_btcpay_invoice_args`.

---

### Blocos Gutenberg do Lightning
`includes/blocks/js/paycrypto_me_lightning-blocks.js` e seus assets compilados em `assets/blocks/paycrypto_me_lightning-blocks.*` estão em desenvolvimento ativo.

### Ícones novos
Arquivos não rastreados em `assets/`:
- `paycrypto-me-icon-express-1.png`
- `paycrypto-me-icon-full-black.png`
- `paycrypto-me-icon-full-white.png`
- `paycrypto-me-icon-white.png`
