---
name: project-wip
description: Trabalho em progresso — o que está incompleto ou sendo desenvolvido ativamente
metadata: 
  node_type: memory
  type: project
  originSessionId: bb8519f4-c6c4-4565-868e-f947b0f2ee52
---

## Trabalho em Progresso (WIP)

**Contexto:** O plugin está em versão 0.1.0, o gateway On-Chain (Bitcoin) está funcional, mas o Lightning Network está incompleto.

### Lightning Network — fluxo de invoice não implementado

`LightningPaymentProcessor::process()` (arquivo: `includes/processors/class-lightning-payment-processor.php`) retorna `payment_data` sem gerar uma invoice real. O arquivo contém o TODO explícito:

```
//TODO: ver como fazer o fluxo abaixo
// Gere uma invoice por pedido no seu node ou provedor Lightning (LND, Core Lightning, LNbits, BTCPay, etc.)
```

O código comentado no final do arquivo mostra a implementação planejada para BTCPay e lnd REST (criar invoice, consultar status, decodificar bolt11). Ainda não foi descomentado/integrado.

**Além disso:** `ProcessorStrategiesFactory` mapeia apenas `paycrypto_me` (On-Chain). O caso `paycrypto_me_lightning` lança `InvalidArgumentException` — a factory precisa ser estendida quando o Lightning estiver pronto.

### Blocos Gutenberg do Lightning em desenvolvimento

Arquivos `includes/blocks/js/paycrypto_me_lightning-blocks.js` e os assets em `assets/blocks/paycrypto_me_lightning-blocks.*` estão sendo trabalhados ativamente (git status mostra como modificados).

### Webhook REST para Lightning

O settings do gateway Lightning já referencia `rest_url('paycrypto-me/v1/webhook')` como endpoint para receber notificações do BTCPay/lnd. Esse endpoint REST ainda precisa ser registrado/implementado.

### Ícones novos sendo adicionados

Arquivos não rastreados em `assets/`:
- `paycrypto-me-icon-express-1.png`
- `paycrypto-me-icon-full-black.png`
- `paycrypto-me-icon-full-white.png`
- `paycrypto-me-icon-white.png`

Provavelmente para o botão de Express Payment e variações do logo.

**Why:** Saber que o Lightning está em WIP evita confusão ao depurar por que `paycrypto_me_lightning` não processa pagamentos reais — é intencional, não um bug.

**How to apply:** Antes de trabalhar no Lightning, verificar se `LightningPaymentProcessor` e `ProcessorStrategiesFactory` já foram atualizados, pois esta memória pode ter ficado desatualizada.
