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
- Fase 3+ (breakup integral das god classes + DRY amplo) **adiada de propósito**. Gate revisado em 2026-07-02: express e blocos Gutenberg do Lightning já estão assentados (ver acima); o gate real era só as 2 costuras do add-on premium pendentes (`get_by_invoice_id()` + action `paycryptome_lightning_status_changed`) — esperar pelo webhook em si não fazia sentido, já que ele nunca será implementado neste repo por design.
- **Costuras do add-on premium implementadas em 2026-07-02** — `get_by_invoice_id()` (sem cache, lookup pontual) e a action `paycryptome_lightning_status_changed($order_id, $old_status, $new_status)` (só dispara em mudança real de status, dentro de `update_status()`) foram adicionadas a `PayCryptoMeLightningDBStatementsService`. Hook documentado no `CLAUDE.md`. Suíte: 173 testes, 0 erros (era 168). **Gate da Fase 3+ liberado** — sem pré-condição pendente.
- **Complexidade da Fase 3+ analisada em 2026-07-02** (leitura direta do código + grep em `tests/`, ver plano § "Análise de complexidade"): 4 dos 6 itens são 🟢 baixa complexidade (mover `init_url_params()`/`instance()`, `PaymentDisplayDataBuilder`, DRY dos invoice services, long methods) — a suíte já testa essas áreas via comportamento público, não estrutura interna. DI nos processors é 🟡 média (risco só de API pública para o futuro add-on, não de teste). Extrair `LightningConfigValidator` do gateway Lightning é 🔴 alta — os 37 testes de `WCGatewayLightningValidationTest` estão acoplados à localização/visibilidade dos métodos no gateway (incl. `ReflectionMethod` com o nome da classe hardcoded), exigindo reescrita de teste, não só mover código. **Ordem recomendada de execução:** mover `init_url_params()`/`instance()` → `PaymentDisplayDataBuilder` → DRY invoice services → long methods → DI nos processors → `LightningConfigValidator` (por último).

**Pendências reais para fechar o plano por completo** (ver seção "O que falta" no plano):
1. ~~2 costuras aditivas do add-on premium~~ ✅ Concluído (2026-07-02) — ver acima.
2. Fase 3+ em si (breakup das god classes, DRY entre gateways/invoice services, DI nos processors) — sem gate pendente, começa quando priorizada.

### Ícones novos
Arquivos não rastreados em `assets/`:
- `paycrypto-me-icon-express-1.png`
- `paycrypto-me-icon-full-black.png`
- `paycrypto-me-icon-full-white.png`
- `paycrypto-me-icon-white.png`
