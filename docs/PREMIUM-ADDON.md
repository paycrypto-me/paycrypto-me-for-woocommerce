# Plano — Add-on Premium para PayCrypto.Me for WooCommerce

> Plano de implementação aprovado para o add-on premium separado. Referência para agentes e
> humanos que forem construir o plugin pago. A fonte de verdade da arquitetura do plugin **base**
> continua sendo o `CLAUDE.md` (raiz do repo).

## Contexto

O plugin base (`paycrypto-me-for-woocommerce`, v0.1.0, GPL-3.0, no WordPress.org) foi
desenhado desde o início para receber um **add-on premium separado**. O `CLAUDE.md` reserva
explicitamente duas capacidades para esse add-on (confirmação async via webhook/polling e
fiat→sats), e o próprio código marca campos de settings como `paycrypto-premium-field`
desabilitados ("ships in the upcoming PayCrypto.Me Premium add-on") em **ambos** os gateways.

**Objetivo:** construir um plugin WordPress separado que ativa essas features conectando-se
aos hooks/serviços do base — sem `if (is_premium())` no repo base. O add-on é a primeira coisa
a efetivamente *chamar* seams que hoje existem mas não têm consumidor (ex.: `update_status()`
do Lightning não tem nenhum caller em produção).

**Decisões do usuário (já tomadas):**
- **Repositório:** novo repo separado (ex. `paycrypto-me-premium`), independente do monorepo base.
- **Escopo v1:** tudo que o código marca como premium — (A) confirmação async Lightning,
  (B) fiat→sats, (C) rastreio de confirmações on-chain, (D) auto-expiração de pedidos.
- **Licenciamento:** **adiado**. v1 é um plugin instalável manualmente; a camada de
  licença/updates entra depois, atrás de um ponto de extensão único (ver §8).
- **Resiliência de APIs externas (requisito transversal):** **toda** consulta a API pública
  (câmbio fiat→sats, block explorers, feeds de preço) passa por **interface com múltiplos
  providers**, com **retry por provider** e **failover automático** para o próximo provider
  disponível quando um estiver fora no momento da consulta. Ver §3 — é a espinha dorsal dos
  módulos B e C.

---

## 1. Arquitetura do add-on

- **Plugin WordPress independente**, distribuído **fora do WP.org** (WP.org só aceita GPL grátis).
- **Namespace próprio:** `PayCryptoMe\WooCommerce\Premium` (o base usa `PayCryptoMe\WooCommerce`
  sem sub-namespaces + classmap; escolher raiz própria evita colisão de classe).
- **Autoload:** Composer **classmap** (mesmo padrão do base — `composer.json` do add-on com
  `"autoload": { "classmap": ["includes/"] }`). Reusa classes do base por FQN, já carregadas
  pelo `vendor/autoload.php` do base.
- **Header do plugin:** `Requires Plugins: paycrypto-me-for-woocommerce` (WP 6.5+; o base está
  no WP.org, então o header força a instalação da dependência) + `Requires: woocommerce`.
- **Dependency guard** (bootstrap em `plugins_loaded` **prioridade 20**, depois do base que roda em 10):
  ```php
  if (!class_exists('\PayCryptoMe\WooCommerce\WC_PayCryptoMe')
      || version_compare(\PayCryptoMe\WooCommerce\WC_PayCryptoMe::VERSION, '0.1.0', '<')) {
      // admin_notice + bail
  }
  ```
  `WC_PayCryptoMe::VERSION` é a fonte de verdade da versão do base
  (`src/trunk/paycrypto-me-for-woocommerce.php:38`). Os seams de enablement (§2) fazem parte da
  própria **0.1.0** (ainda em pré-release), então o guard só exige o base presente em ≥ 0.1.0.
- **Reuso do `HttpClientContract` do base:** todo HTTP (providers, nós, webhook re-verify) passa
  pelo `WpHttpClient` do base (`includes/http/class-wp-http-client.php`) — uniformiza logging e
  torna tudo mockável com o `FakeHttpClient` já existente nos testes.
- **Composer do add-on é leve:** não precisa das libs Bitcoin forked (`lucas-rosa95/bitcoin`) —
  o add-on **consome** endereços/invoices já derivados e persistidos pelo base, e faz HTTP a
  block explorers / nós / feeds. Só `phpunit` como dev-dep.

### Estrutura de diretórios do novo repo
```
paycrypto-me-premium/
├── src/trunk/
│   ├── paycrypto-me-premium.php          ← entrypoint + guard + bootstrap
│   ├── composer.json                     ← classmap autoload, namespace Premium
│   ├── includes/
│   │   ├── class-premium-bootstrap.php    ← singleton, registra módulos (espelha WC_PayCryptoMe)
│   │   ├── providers/                     ← §3 CAMADA RESILIENTE (multi-provider + retry + failover)
│   │   │   ├── contracts/
│   │   │   │   ├── ExchangeRateProviderContract.php
│   │   │   │   ├── BlockchainExplorerProviderContract.php
│   │   │   │   └── ResilientProviderContract.php   ← name()/is_available() comum
│   │   │   ├── class-provider-chain.php            ← executor genérico: ordena, retry, failover
│   │   │   ├── class-provider-health-tracker.php   ← cooldown de provider indisponível (transient)
│   │   │   ├── exchange/                            ← impls de câmbio
│   │   │   │   ├── class-coingecko-rate-provider.php
│   │   │   │   ├── class-kraken-rate-provider.php
│   │   │   │   ├── class-binance-rate-provider.php
│   │   │   │   └── class-mempool-space-rate-provider.php
│   │   │   └── explorer/                            ← impls de block explorer
│   │   │       ├── class-mempool-space-explorer-provider.php
│   │   │       ├── class-blockstream-esplora-provider.php
│   │   │       └── class-blockcypher-explorer-provider.php
│   │   ├── lightning/
│   │   │   ├── class-webhook-controller.php     ← REST paycrypto-me/v1/webhook (BTCPay push)
│   │   │   ├── class-lnd-status-poller.php       ← cron: get_invoice_status() por invoice pendente
│   │   │   └── class-lightning-status-listener.php ← on paycryptome_lightning_status_changed → payment_complete
│   │   ├── conversion/
│   │   │   ├── class-fiat-to-sats-converter.php   ← usa ProviderChain<ExchangeRate> + cache
│   │   │   └── class-lnd-amount-filter.php        ← filtro paycryptome_lightning_lnd_invoice_args
│   │   ├── onchain/
│   │   │   ├── class-onchain-confirmation-poller.php ← usa ProviderChain<Explorer>
│   │   │   └── class-onchain-status-listener.php  ← on paycryptome_bitcoin_status_changed → payment_complete
│   │   ├── expiry/
│   │   │   └── class-order-expiry-cron.php        ← cron: cancela pedidos vencidos (ambos gateways)
│   │   ├── settings/
│   │   │   ├── class-lightning-settings-injector.php ← filtro woocommerce_settings_api_form_fields_paycrypto_me_lightning
│   │   │   └── class-onchain-settings-injector.php   ← filtro ..._paycrypto_me (+ ordem/toggle de providers)
│   │   ├── cron/class-cron-scheduler.php          ← registra schedules na ativação, limpa no deactivate
│   │   └── license/class-license-manager.php      ← stub (sempre válido) — ver §8
│   └── tests/                             ← espelha tests/_support do base (shims WP/WC + FakeHttpClient)
├── scripts/release.sh                     ← adaptado do base (build/zip; sem submissão WP.org)
└── docs/
```

---

## 2. Enablement do plugin base (parte da 0.1.0 — zero mudança de comportamento no free)

Seams pequenos precisam existir no base para o add-on plugar **sem editar core depois**.
Todos são **no-op para usuários free** (o valor só é setado pelo add-on). Como a 0.1.0 ainda
está em **pré-release**, entram na própria 0.1.0 — não há bump de versão.

**Status:** seams #1 e #2 já **implementados e testados** (suíte 239 testes verde); seam #3
**dispensado** (o add-on enumera pedidos on-chain pendentes via `wc_get_orders()`, sem precisar
de helper no base).

| # | Arquivo base | Mudança | Por quê |
|---|---|---|---|
| 1 | `includes/services/class-lnd-rest-invoice-service.php` (`create_invoice`, ~linha 24) | Incluir `'value' => (string)(int)$args['value']` no `$body` **quando `isset($args['value'])`** | Hoje o invoice lnd manda só `memo`+`expiry` (zero-amount). Sem isso, o filtro fiat→sats **persiste** `amount_sats` mas **não força** o valor no invoice lnd. |
| 2 | `includes/services/pay-crypto-me-db-statements-service.php` | Novo método público `update_transaction_confirmations(int $order_id, int $num_confirmations, string $amount_received, string $tx_hash): bool` que faz `UPDATE` na `paycrypto_me_bitcoin_transactions_data` e dispara `do_action('paycryptome_bitcoin_status_changed', $order_id, $old, $new)` **só em transição real** | On-chain não tem método de update nem action de status. Espelha o precedente `PayCryptoMeLightningDBStatementsService::update_status()` (linha 121-146) / `paycryptome_lightning_status_changed`. |
| 3 | (opcional) mesmo arquivo | `get_pending_onchain_orders()` / lista de endereços pendentes | Conveniência p/ o poller. **Alternativa sem base:** o add-on faz sua própria query na tabela — decidir na implementação. |

**Não precisam de mudança no base:**
- **Injeção/habilitação de campos de settings** — o WooCommerce core já aplica
  `apply_filters('woocommerce_settings_api_form_fields_' . $id, ...)`. O add-on remove o
  `disabled` e injeta a URL real do webhook via esse filtro (§7).
- **Seams Lightning** (`get_by_invoice_id`, `get_by_order_id`, `update_status`,
  `paycryptome_lightning_status_changed`, `get_invoice_status`) — **já existem e estão prontos.**

> Registrar as duas novas capacidades (action `paycryptome_bitcoin_status_changed`, arg `value`
> no lnd) na tabela de hooks do `CLAUDE.md` e em `docs/`.

---

## 3. Camada de providers resilientes (multi-provider + retry + failover) — REQUISITO TRANSVERSAL

Toda consulta a API pública externa é encapsulada atrás de um **contract** e executada por um
**`ProviderChain`** genérico que ordena providers, tenta com retry, e **faz failover** para o
próximo provider quando o atual falha ou está indisponível. Espelha o padrão contract+strategy
do base (`LightningInvoiceServiceContract`, factories em `includes/strategies/`).

### Contracts (uma interface por tipo de dado, N implementações cada)
- `ResilientProviderContract` — base comum: `name(): string`, `is_available(): bool`.
- `ExchangeRateProviderContract extends Resilient` — `get_btc_rate(string $fiat_currency): float`
  (lança exceção em falha). Impls: **CoinGecko, Kraken, Binance, mempool.space** (ordem
  configurável).
- `BlockchainExplorerProviderContract extends Resilient` —
  `get_address_status(string $address, string $network): AddressStatus`
  (`{confirmations, amount_received_sats, tx_hash}`). Impls: **mempool.space, Blockstream Esplora,
  BlockCypher** (mainnet + testnet).

### `ProviderChain` (executor de failover, genérico e reusável)
```php
$chain = new ProviderChain($orderedProviders, $healthTracker, $maxRetriesPerProvider);
$rate  = $chain->run(fn($p) => $p->get_btc_rate('USD'));  // 1º sucesso vence
```
Comportamento:
- Itera providers **na ordem configurada**; **pula** os marcados indisponíveis pelo
  `ProviderHealthTracker` (a menos que o cooldown tenha expirado).
- Por provider: **retry** com backoff (ex. 2-3 tentativas). Falhou todas → registra no
  health-tracker e **avança para o próximo provider** (failover).
- Todos falharam → lança `AllProvidersUnavailableException` (o caller decide o fallback: usar
  último valor em cache / adiar via cron / registrar log).
- Cada tentativa passa pelo `HttpClientContract` do base (mockável em teste).

### `ProviderHealthTracker` (circuit-breaker leve)
- Ao falhar, marca o provider indisponível por um cooldown (`set_transient`, ex. 5 min) → a chain
  o **pula** nas próximas consultas até o cooldown expirar, evitando martelar um endpoint morto.
- `is_available()` do provider consulta o tracker.

### Configuração (via settings injetados, §7)
- Ordem/enable dos providers de câmbio e de explorer, chaves de API opcionais (BlockCypher etc.),
  e `network` (mainnet/testnet) — lidos por `$gateway->get_option(...)`.

### Testes (§9)
- `ProviderChain`: 1º provider falha → 2º responde (failover); todos falham → exceção; provider em
  cooldown é pulado. Com `FakeHttpClient` devolvendo erro/ok por provider.

---

## 4. Módulo A — Confirmação async Lightning

**BTCPay (push):**
- Registrar rota REST `paycrypto-me/v1/webhook` em `rest_api_init` (**não existe no base** — greenfield).
- Handler: valida HMAC do header BTCPay com o secret `btcpay_webhook_secret`
  (`$gateway->get_option('btcpay_webhook_secret')`); extrai `invoiceId`; mapeia p/ pedido via
  `PayCryptoMeLightningDBStatementsService::get_by_invoice_id($invoice_id)`
  (`class-paycrypto-me-lightning-db-statements-service.php:65`).
- **Re-verificação server-side obrigatória** (nunca confiar só no payload): instanciar
  `new BtcpayInvoiceService(new WpHttpClient(), $gateway)` e chamar `get_invoice_status($invoice_id)`
  → `LightningInvoiceStatusResponse{paid,status}`. Se `paid`, chamar
  `$db->update_status($order_id, 'Settled')`.

**lnd (polling — lnd não tem webhook simples):**
- `class-lnd-status-poller.php` roda em cron (schedule custom, ex. cada 60-120s). Para cada
  invoice lnd em status não-final: `LndRestInvoiceService::get_invoice_status($invoice_id)`
  (`class-lnd-rest-invoice-service.php:50`) → se `paid` (`state === 'SETTLED'`),
  `$db->update_status($order_id, 'SETTLED')`.
- Reusa o `HttpClientContract` do base (`WpHttpClient`) e o `request_with_cert()` do serviço lnd
  (trata TLS/macaroon). Capturar `PayCryptoMePaymentException` (erro transitório do nó não pode
  matar o tick do cron). *(O nó lnd é infra do lojista, não "API pública" — não entra na chain
  de providers do §3; a resiliência aqui é o próprio loop de polling.)*

**Fechamento do pedido (ponto único, idempotente):**
- `class-lightning-status-listener.php`: `add_action('paycryptome_lightning_status_changed', fn, 10, 3)`.
  Mapeia status do nó → transição WC: se pago e `!$order->is_paid()` → `$order->payment_complete()`.
- A action **só dispara em transição real** (`update_status` linha 141-143), então webhook e
  polling convergem no mesmo listener sem dupla-baixa. **Nenhum consumidor no core hoje** — sem
  risco de double-handling.

---

## 5. Módulo B — fiat→sats (usa a chain de câmbio do §3)

- `class-fiat-to-sats-converter.php`: converte `$order->get_total()` + `$order->get_currency()`
  em sats usando `ProviderChain<ExchangeRateProviderContract>` (§3) — CoinGecko → Kraken →
  Binance → mempool.space com retry/failover. Cache `set_transient` (ex. 60s) da taxa; em
  `AllProvidersUnavailableException`, fallback para o último valor em cache (ou adia).
- `class-lnd-amount-filter.php`: `add_filter('paycryptome_lightning_lnd_invoice_args', fn, 10, 3)`
  (recebe `$args, $order, $gateway` — `abstract-class-lightning-processor.php:30`). Seta
  `$args['amount_sats']` (persistido na coluna via `insert_invoice`, `abstract-class-lightning-processor.php:63`)
  **e** `$args['value']` (força o valor no invoice lnd — depende do enablement §2-#1).
- **BTCPay:** já manda `amount`+`currency` fiat e converte internamente
  (`class-btcpay-invoice-service.php:22-23`) — **não precisa de enforcement fiat→sats**. Para
  BTCPay o add-on pode, opcionalmente, só *exibir* o equivalente em sats (reusa a mesma chain).

---

## 6. Módulo C — Rastreio de confirmações on-chain (usa a chain de explorer do §3)

- `class-onchain-confirmation-poller.php` (cron): para cada pedido on-chain pendente, lê o
  `payment_address` da tabela `paycrypto_me_bitcoin_transactions_data`
  (via `PayCryptoMeDBStatementsService::get_by_order_id()`), consulta o endereço via
  `ProviderChain<BlockchainExplorerProviderContract>` (§3) — mempool.space → Esplora →
  BlockCypher com retry/failover — obtendo `{confirmations, amount_received_sats, tx_hash}`.
- Chama o novo `update_transaction_confirmations()` (enablement §2-#2). O `confirmations_required`
  por pedido vem da meta `_paycrypto_me_payment_number_confirmations`
  (`class-wc-gateway-paycrypto-me.php:234`).
- `class-onchain-status-listener.php`: `add_action('paycryptome_bitcoin_status_changed', fn)` —
  quando `confirmations >= required` **e** `amount_received >= esperado` → `$order->payment_complete()`.
  Mesmo padrão idempotente do Lightning.

> **Limitação documentada (F5):** o rastreio automático on-chain cobre **apenas endereços
> derivados** (xpub/ypub/zpub), que geram linha em `paycrypto_me_bitcoin_transactions_data`.
> Pagamento a **endereço estático** não gera linha (o processador retorna antes de `insert_address()`,
> [class-bitcoin-payment-processor.php:49-54](../src/trunk/includes/processors/class-bitcoin-payment-processor.php#L49-L54)),
> então `get_by_order_id()` retorna `null` e o poller **ignora naturalmente** esses pedidos —
> endereço estático permanece **confirmação manual**, por design. Sem código extra no add-on nem
> mudança de schema no base.

---

## 7. Módulo D — Auto-expiração + injeção de settings

**D. Auto-expiração** (`class-order-expiry-cron.php`, cron): pedidos `pending` além do
`_paycrypto_me_payment_expires_at` → `$order->update_status('cancelled'/'failed', ...)`. Vale para
os dois gateways. Toggle habilitado via injeção de settings.

**Exibição da expiração/valor (via filtros F1 — já no base 0.1.0):** para mostrar a contagem de
expiração on-chain (que o base fixa `show_expiry => false`) e o valor cripto no Lightning (fixo
`null`), o add-on usa `add_filter('paycryptome_order_display_args', fn($args,$order,$gateway))` para
virar `show_expiry => true` / setar `crypto_amount` antes do `PaymentDisplayDataBuilder`, e/ou
`paycryptome_order_display_data` para ajustar campos já computados. Sem esses filtros a camada de
exibição era 100% fechada.

**Injeção de settings (habilitar os campos hoje `disabled` + config de providers):**
- **A/D Lightning** — `class-lightning-settings-injector.php`:
  `add_filter('woocommerce_settings_api_form_fields_paycrypto_me_lightning', fn)`. Remove
  `custom_attributes['disabled']` de `btcpay_webhook_secret`; injeta a URL real
  `rest_url('paycrypto-me/v1/webhook')` na descrição do campo `webhook_info`
  (`class-wc-gateway-paycrypto-me-lightning.php:159-174`); adiciona campo de intervalo de polling
  lnd e **ordem/enable dos providers de câmbio** (§3).
- **B/C On-chain** — `class-onchain-settings-injector.php`:
  `add_filter('woocommerce_settings_api_form_fields_paycrypto_me', fn)`. Habilita
  `payment_number_confirmations` e o timeout de expiração (`class-wc-gateway-paycrypto-me.php:154-165`);
  adiciona **ordem/enable dos block explorers + chaves de API opcionais** (§3).

**Infra de cron** (`class-cron-scheduler.php`): registrar `wp_schedule_event` na **ativação** do
add-on e limpar (`wp_clear_scheduled_hook`) na **desativação**. Um schedule custom único dispara os
três pollers (lnd status, on-chain confirmations, expiração). BTCPay é push (sem cron).

---

## 8. Licenciamento (adiado) — deixar o gancho pronto

Para não refatorar depois, centralizar **um** ponto de decisão agora:
- `class-license-manager.php` com `is_active(): bool` retornando `true` (stub). Todo registro de
  módulo passa por ele no bootstrap (`if (LicenseManager::is_active()) { registra módulos }`).
- Deixar comentado o gancho de update para plugin fora do WP.org
  (`pre_set_site_transient_update_plugins` + `plugins_api`), a ser preenchido quando a distribuição
  paga (Freemius / EDD Software Licensing / Lemon Squeezy) for escolhida. Trocar o stub por SDK
  real será uma mudança localizada nesse arquivo + no bootstrap.

---

## 9. Testes

Espelhar a infra do base (`src/trunk/tests/_support/`): shims WP/WC centralizados, `FakeHttpClient`
/ `http_ok()` / `http_error()`, spies de hook. Copiar `phpunit.xml.dist` + `tests/bootstrap.php`.
Alvos prioritários:
- **`ProviderChain` (§3):** failover (1º falha → 2º responde), retry por provider, exceção quando
  todos falham, provider em cooldown é pulado. Cada provider de câmbio/explorer: parse correto da
  resposta e detecção de falha, com `FakeHttpClient`.
- **Webhook controller:** validação de assinatura HMAC (aceita/rejeita), mapeamento invoice→order,
  re-verificação server-side, idempotência (segundo push não re-completa).
- **lnd poller / on-chain poller:** com `FakeHttpClient` devolvendo status pago/pendente.
- **Listeners:** transição → `payment_complete()` só uma vez; ignora pedido já pago.
- **FiatToSatsConverter:** cache, e fallback para último valor quando a chain lança
  `AllProvidersUnavailableException`.
- **Enablement do base (no repo base):** teste para `value` no lnd body e para
  `update_transaction_confirmations()` disparar `paycryptome_bitcoin_status_changed` só em transição
  (espelhar `PayCryptoMeLightningDBStatementsServiceTest`).

---

## 10. Release / distribuição

- **Base:** os seams (§2) já entram na **0.1.0** (pré-release) — sem bump de versão. Release inicial
  via `scripts/release.sh` (fluxo existente, `docs/RELEASE.md`), submeter ao WP.org.
- **Add-on:** `scripts/release.sh` adaptado (build/zip; **sem** SVN/WP.org). Distribuição por
  download do site/plataforma paga. Versionar independente do base.
- Traduções do add-on com text domain próprio (ex. `paycrypto-me-premium`), espelhando o fluxo de
  `docs/TRANSLATION.md` (script `scripts/build-translations.sh`).

---

## 11. Verificação end-to-end

1. **Base primeiro:** rodar `./vendor/bin/phpunit` no base após os seams §2 (esperado: suíte
   verde, incluindo os novos testes).
2. **Ambiente:** subir o `docker-compose.yml` do base, instalar o base 0.1.0 + o add-on; confirmar
   que o guard não bloqueia (base presente e versão OK) e que os campos premium ficam **editáveis**.
3. **Providers (§3):** derrubar/mistrar o 1º provider (ex. URL inválida em teste) e confirmar
   failover para o próximo + cooldown do provider derrubado; conferir logs.
4. **Lightning BTCPay:** disparar um POST assinado no endpoint
   `rest_url('paycrypto-me/v1/webhook')` (curl com HMAC válido) para um pedido de teste → conferir
   que o pedido vai a `processing/completed` e que um segundo POST não re-completa (idempotência).
5. **Lightning lnd:** com nó/regtest (ou `FakeHttpClient` em teste), rodar o poller manualmente
   (`wp cron event run <hook>`) e confirmar transição ao pagar.
6. **fiat→sats:** criar invoice lnd via checkout e verificar `amount_sats` persistido **e** o
   `value` no corpo enviado ao lnd (log/inspeção); a taxa veio da chain de câmbio.
7. **On-chain:** pedido testnet, pagar o endereço, rodar o poller, conferir
   `paycrypto_me_bitcoin_transactions_data` atualizada (via chain de explorer) e `payment_complete()`
   ao atingir as confirmações.
8. **Auto-expiração:** pedido pending com expiry curto → rodar o cron → status `cancelled`.

---

## Arquivos-chave (referência)

**Base — consumidos sem edição:**
- `includes/services/class-paycrypto-me-lightning-db-statements-service.php` — `get_by_invoice_id():65`,
  `update_status():121`, action `paycryptome_lightning_status_changed:142`
- `includes/services/class-btcpay-invoice-service.php:49` / `class-lnd-rest-invoice-service.php:50` —
  `get_invoice_status()`
- `includes/processors/abstract-class-lightning-processor.php:30` — filtros de invoice args
- `includes/contracts/HttpClientContract.php` + `includes/http/class-wp-http-client.php`
- `includes/services/pay-crypto-me-db-statements-service.php` — `get_by_order_id()` on-chain
- `paycrypto-me-for-woocommerce.php:38` — `WC_PayCryptoMe::VERSION` (guard)

**Base — enablement (editar, §2):**
- `includes/services/class-lnd-rest-invoice-service.php` (`value` no body)
- `includes/services/pay-crypto-me-db-statements-service.php` (`update_transaction_confirmations()` + action)

**Add-on — criar:** todos sob `paycrypto-me-premium/src/trunk/includes/` (ver §1); o coração da
resiliência é `includes/providers/` (§3), consumido pelos módulos B (câmbio) e C (explorer).
