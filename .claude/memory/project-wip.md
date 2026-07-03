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
- Suíte PHPUnit atual: **52 testes, 2 erros pré-existentes** em `BitcoinPaymentProcessorTest` (colisão de shim `WC_Payment_Gateway`, anteriores à auditoria)

### Botão express — implementado

Botão "Buy with…" para On-Chain e Lightning: settings + display data na classe abstrata, ramificação `{gateway_id}_express` aceita em `PaymentProcessor::validate_order()`, UI no JS compartilhado `includes/blocks/js/paycrypto-blocks-shared.js`.

**Why:** Saber que o fluxo core está completo evita reimplementar o que já existe e ajuda a identificar o que ainda falta.

**How to apply:** Ao trabalhar no Lightning, assumir que `process_payment()` → `AbstractLightningProcessor::process()` → `BtcpayInvoiceService`/`LndRestInvoiceService` → template já funciona. Focar no que ainda está abaixo.

---

### Reservado para a versão premium/freemium (INTENCIONAL — não é gap)

Estes dois itens **não** são trabalho incompleto: são decisões de escopo de produto, deliberadamente fora da versão gratuita e reservados para um tier premium futuro. Não "corrigir" para dentro da versão free.

**Entrega:** plugin **add-on separado** que depende deste base e pluga via hooks/filtros (não gating no mesmo repo). Costuras que o base precisa expor para o add-on ser zero-core-edit estão em `docs/architecture-audit-plan.md` § "Costuras para o add-on premium (zero-core-edit)".

#### Webhook REST + status assíncrono
O settings já referencia `rest_url('paycrypto-me/v1/webhook')` (a costura existe), mas o `register_rest_route()` é feature premium. Confirmação automática de status — webhook push do BTCPay, ou polling lnd via WP-Cron (`wp_schedule_event`) — pertence ao tier pago.

#### Conversão fiat → sats
Invoices são zero-amount de propósito na versão free. A conversão do total fiat em `amount_sats` é feature premium, projetada para plugar via `paycryptome_lightning_lnd_invoice_args` / `paycryptome_lightning_btcpay_invoice_args` sem alterar o core.

---

### Blocos Gutenberg do Lightning — reclassificado como concluído (2026-07-02)
Antes listado aqui como "em desenvolvimento ativo"; verificado contra o código em 2026-07-02 e não achada evidência de trabalho pendente — `paycrypto_me_lightning-blocks.js` espelha estruturalmente o `paycrypto_me-blocks.js` do On-Chain (já maduro), registra pagamento normal + express via `createPaymentComponents()` compartilhado, sem `TODO`/`FIXME`, sem commit desde `8c1f528` (mesmo commit do botão express). Tratar como pronto a menos que retome desenvolvimento.

---

### Débito arquitetural / auditoria

`docs/architecture-audit-plan.md` (revisado 2026-07-02) — auditoria SOLID/DRY + cobertura, faseada.
- Fase 0 ✅ (dead code + persistência).
- Fase 1 ✅ (2026-07-02) — rede de segurança: testes críticos + spy mínimo nos shims. 147 testes, 0 erros.
- Fase 2 ✅ (2026-07-02) — extrações de alto valor: `LightningConnectionTester`, `PaymentOrderValidator`, DI de `QrCodeService`. 168 testes, 0 erros (confirmado rodando em 2026-07-02). Lightning gateway caiu de 647→523 linhas, `PaymentProcessor` de 280→235.
- **Costuras do add-on premium implementadas em 2026-07-02** — `get_by_invoice_id()` (sem cache, lookup pontual) e a action `paycryptome_lightning_status_changed($order_id, $old_status, $new_status)` (só dispara em mudança real de status, dentro de `update_status()`) foram adicionadas a `PayCryptoMeLightningDBStatementsService`. Hook documentado no `CLAUDE.md`. Suíte: 173 testes, 0 erros (era 168).
- **Complexidade da Fase 3+ analisada em 2026-07-02**, depois **revisada em 2026-07-03** (parecer de arquitetura, ver plano § "Parecer de arquitetura 2026-07-03"): o item `LightningConfigValidator` foi rebaixado de 🔴 Alta para 🟡 Média — só 3 dos 37 testes de `WCGatewayLightningValidationTest` usavam `ReflectionMethod` (e só no método privado `_is_lnd_rest_selected`), os outros 34 sobreviveriam a uma extração com stubs públicos de encaminhamento. Ordem final de execução: **2 (PaymentProcessor) → 3 (DRY gateways) → 4 (DRY invoice services) → 6 (DI processors, adiantado por causa da janela do construtor público) → 1 (LightningConfigValidator) → 5 (long methods, por último/sob demanda)**.
- **Fase 3+ EXECUTADA em 2026-07-03** — 5 dos 6 itens concluídos + 1 parcial (long methods, deliberadamente adiado — ver abaixo). Suíte: **218 testes, 495 asserções, 0 erros** (era 173 antes da execução). Landed:
  - `PaymentProcessor` — `instance()` (falso singleton) inlinado nos 2 call sites de produção; `init_url_params()` era dead code duplo (nunca chamado, session vars nunca lidas) → removido.
  - `PaymentDisplayDataBuilder` (novo, `services/class-payment-display-data-builder.php`) — DRY entre `render_admin_order_details_section()`/`render_checkout_order_details_section()` dos 2 gateways, movidos para a classe abstrata, parametrizados pelo hook `build_order_display_args()`. Tabela `crypto_names` (ETH/LTC mortos) removida do template.
  - `AbstractLightningInvoiceService` (novo, `services/abstract-class-lightning-invoice-service.php`) — DRY entre `BtcpayInvoiceService`/`LndRestInvoiceService` (construtor + `parse_response()` compartilhados); lnd fatorou o cert temp-file em `request_with_cert()`.
  - DI via construtor nos 3 processors (`BitcoinPaymentProcessor`, `BtcpayLightningProcessor`, `LndRestLightningProcessor`) — parâmetros nullable com fallback interno (`?Dep $dep = null` → `$dep ?? new Dep()`); as 2 factories em `includes/strategies/` viraram composition root.
  - `LightningConfigValidator` (novo, `validators/class-lightning-config-validator.php`) — lógica pura dos 9 validators + `is_lnd_rest_selected()`; gateway manteve stubs públicos de 1 linha (necessários para o dispatch `method_exists` do `WC_Settings_API`). **Nenhum dos 37 testes de `WCGatewayLightningValidationTest` precisou mudar** — melhor que o previsto.
  - **Parcial/adiado:** `BitcoinPaymentProcessor::process()` (124→dividido em 3 métodos, feito — tinha duplicação real). `init_form_fields()` (Lightning) e `enqueue_checkout_styles()` (abstrata) — extract-method cosmético sem redução de duplicação, zero cobertura de teste (view/config) — adiado como follow-up de baixa prioridade, junto dos 3 geradores de HTML do Lightning (não movidos, ligados a callback de filtro do WooCommerce).
  - Line counts: `WC_Gateway_PayCryptoMe_Lightning` 647→438, `PaymentProcessor` 280→206.
  - **Pendente:** confirmação do smoke test manual pelo usuário (critério de saída formal da Fase 3+ no plano) — ver `docs/architecture-audit-plan.md` § "Verificação".

**Pendências reais para fechar o plano por completo:**
1. Confirmação do smoke test manual da Fase 3+ (On-Chain estático + xPub, Lightning BTCPay, express em ambos).
2. Follow-ups de baixa prioridade adiados: `init_form_fields()`/`enqueue_checkout_styles()` (long methods cosméticos) + mover os 3 geradores de HTML do Lightning — sem urgência, registrados no plano.
