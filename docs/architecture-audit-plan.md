# Auditoria de Arquitetura — PayCrypto.Me for WooCommerce

## Contexto

O plugin cresceu de um único gateway On-Chain (maduro, bem coberto por testes) para dois gateways — On-Chain e Lightning (BTCPay Server + lnd REST), este último implementado recentemente (M1–M5, ver `.claude/memory/project-wip.md`) — e, mais recentemente ainda, ganhou um **botão express** de checkout que se espalhou por ambos os gateways, pela classe abstrata e pelo `PaymentProcessor`. Esse ritmo de features caindo sobre as classes centrais, sem refatoração/cobertura correspondente, é o problema estrutural que esta auditoria endereça.

Esta auditoria foi pedida para, antes de continuar evoluindo o Lightning (webhook REST, blocos Gutenberg, conversão fiat→sats) e o express, **estabilizar a base**: fechar as lacunas de teste que colocam em risco fluxos de dinheiro (geração de endereço, reserva de índice de derivação, criação/persistência de invoice) e, só depois, atacar as violações de SRP/DRY mais críticas — de forma cirúrgica, priorizando alto valor, dado que o plugin está em 0.1.0 com features ainda em voo.

Auditoria feita com 3 agentes de exploração em paralelo, cobrindo: (1) gateways/processors/services/strategies, (2) mapeamento de testes vs. classes fonte, (3) bootstrap/blocks/templates/hooks. Achados consolidados abaixo, **revisados em 2026-07-02 contra o estado real da codebase** (várias lacunas da auditoria original já haviam sido fechadas — ver "Revisão 2026-07-02").

**Decisões de escopo confirmadas com o usuário:**
- Refatoração **faseada**, começando pelos itens mais críticos (não um refactor monolítico).
- **Testes antes do refactor** — as lacunas críticas de cobertura viram rede de segurança (characterization tests) antes de qualquer classe ser quebrada.
- **Botão express**: documentar a superfície + adicionar rede de segurança na Fase 1; **não** reestruturar seu código agora — apenas garantir que a Fase 2 não o quebre.
- **Infra de teste**: evoluir os shims *o mínimo* para registrar disparo de hooks/actions (spy) — o suficiente para asserir os hooks `before/after_payment`. Uma reescrita ampla dos shims para executar hooks/filtros de verdade continua fora de escopo.
- **Agressividade do refactor**: **alto valor primeiro**. As Fases 2 fazem só as extrações de maior retorno e mais contidas; o breakup integral das god classes fica num backlog adiado (Fase 3+), destravado quando webhook/blocos/express assentarem.

### Revisão 2026-07-02 — correções sobre a auditoria original

A auditoria original (2026-07-01) continha premissas que já não valem. Corrigido aqui:

- ❌ **"O `CLAUDE.md` ainda descreve o Lightning como *not yet implemented*"** — falso hoje; o `CLAUDE.md` já foi atualizado para "fully implemented". O argumento de "doc não acompanhou o código" foi removido.
- ❌ **Retry do `AbstractLightningProcessor` "não testado"** — já coberto: `AbstractLightningProcessorTest` testa resolução tardia, skip quando já presente e **esgotamento** lançando `PayCryptoMePaymentException`.
- ❌ **Falha de lock "não testada"** — parcialmente stale: `ReserveDerivationConcurrencyTest::test_lock_failure_throws_exception` já existe (falta cobrir separadamente `RELEASE_LOCK` retornando 0).
- ⚠️ **`PaymentProcessor::process_payment()` fluxo completo** — existe `PaymentProcessorTest::test_process_payment_success_returns_success_and_redirect()`, **mas é oco**: os próprios comentários dizem que só asserta o pre-check/`payment_data`, não hooks/meta/status. Some-se a isso que os shims fazem `apply_filters`/`do_action` no-op — daí a decisão do spy mínimo.
- ⚠️ **Erros HTTP dos invoice services** — já parcialmente cobertos (`BtcpayInvoiceServiceTest`/`LndRestInvoiceServiceTest` já têm casos de exceção); falta só completar a matriz de códigos.
- ➕ **Botão express não existia na auditoria original** e agora vive dentro das god classes-alvo, sem testes — incorporado abaixo.

### Revisão 2026-07-02 (parte 2) — gate da Fase 3+ reavaliado

O gate original da Fase 3+ ("adiada até webhook, blocos Gutenberg do Lightning e o botão express assentarem") foi verificado contra o código e corrigido:

- ❌ **Botão express "não assentado"** — falso hoje; implementado em `8c1f528` e estável desde então (só ajustes de layout/erro já fechados em `b31e9b4`/`b00f474`), com cobertura de teste desde a Fase 1 (`test_process_payment_accepts_express_payment_method_variant`). **Condição satisfeita.**
- ❌ **Blocos Gutenberg do Lightning "em desenvolvimento ativo"** — sem evidência no código: `paycrypto_me_lightning-blocks.js` (40 linhas) tem estrutura idêntica ao `paycrypto_me-blocks.js` do On-Chain (43 linhas, já maduro), registra `registerPaymentMethod`+`registerExpressPaymentMethod` completos via o mesmo `createPaymentComponents()` compartilhado, sem `TODO`/`FIXME`, sem arquivo untracked pendente, e sem commit desde `8c1f528`. **Condição satisfeita** — o `CLAUDE.md`/`project-wip.md` que descreviam isso como WIP ativo foram corrigidos.
- ⚠️ **Webhook como condição de gate — mal formulada.** O webhook é *intencionalmente* reservado para o add-on premium separado (ver "Fora de escopo" abaixo) — por design, nunca será implementado *neste* repo, então "esperar o webhook assentar" travaria a Fase 3+ indefinidamente. Reformulado: a condição real é as **costuras do add-on estarem implementadas** (`get_by_invoice_id()` + action `paycryptome_lightning_status_changed`, ver "Costuras para o add-on premium" abaixo) — pequeno, não gated em terceiros.

**Gate revisado:** Fase 3+ pode começar assim que as 2 costuras pendentes do add-on forem implementadas. Não é um bloqueio amplo — é uma pré-condição pequena e sob nosso controle.

**Atualização 2026-07-02 (execução):** as 2 costuras foram implementadas — ver "Costuras para o add-on premium" abaixo. **Gate liberado**: Fase 3+ está pronta para começar quando priorizada, sem nenhuma pré-condição pendente.

### Status por fase

| Fase | Status |
|---|---|
| Fase 0 — persistir auditoria + limpeza trivial | ✅ **Concluída** (2026-07-01) |
| Fase 1 — testes críticos faltantes (rede de segurança) + spy mínimo nos shims | ✅ **Concluída** (2026-07-02) — 147 testes, 0 erros |
| Fase 2 — extrações de alto valor (dedup dos AJAX testers, `PaymentOrderValidator`, DI de `QrCodeService`) | ✅ **Concluída** (2026-07-02) — 168 testes, 0 erros; smoke test manual OK |
| Fase 3+ (adiada) — breakup integral das god classes + DRY amplo | ⬜ Backlog, **gate liberado (2026-07-02)** — as 2 costuras do add-on premium foram implementadas; sem pré-condição pendente, começa quando priorizada |

---

## Achados principais (resumo)

### SRP/DRY — violações mais relevantes

| # | Classe/Arquivo | Problema | Severidade |
|---|---|---|---|
| 1 | `includes/class-wc-gateway-paycrypto-me-lightning.php` (647 linhas) | **God class**: 9 validadores de campo, 2 handlers AJAX de teste de conexão (`ajax_test_btcpay_connection` 58 linhas, `ajax_test_lnd_connection` 80 linhas), 3 geradores de HTML customizado, renderização de order details | Alta |
| 2 | `includes/processors/class-payment-processor.php` (280 linhas) | **God class**: orquestração + validação (`validate_order()` com 5 checks independentes, incl. a ramificação do express em `:207-209`) + log + hooks + filtros + singleton (`instance()`) + bootstrap (`init_url_params()`) | Alta |
| 3 | `includes/abstract-class-wc-gateway-paycrypto-me.php` (362 linhas) | Mistura forms (incl. seção express), asset enqueueing, rendering, logging, payment flow delegation | Média |
| 4 | `includes/class-wc-gateway-paycrypto-me.php` (349 linhas) | Lógica de negócio (mapeamento de rede via `match()`) dentro do método de renderização; mesma duplicação de shell de renderização que o Lightning | Média |
| 5 | `BitcoinPaymentProcessor`, `BtcpayLightningProcessor`, `LndRestLightningProcessor` | `[HARDCODED_DEPENDENCY]` — `new Service()` no construtor em vez de injeção | Média |
| 6 | `BtcpayInvoiceService` + `LndRestInvoiceService` | `[DUPLICATED_LOGIC]` — setup de header de auth e `parse_response()` repetidos; `LndRestInvoiceService` duplica manuseio de arquivo de certificado temporário em `create_invoice()` e `get_invoice_status()` | Média |
| 7 | `class-wc-gateway-paycrypto-me.php` + `class-wc-gateway-paycrypto-me-lightning.php` | `[DUPLICATED_LOGIC]` — `render_admin_order_details_section()`/`render_checkout_order_details_section()` quase idênticos entre os dois gateways | Média |
| 8 | `templates/order-details/paycrypto-me-order-details.php:6-7` | `[LOGIC_IN_TEMPLATE]` — tabela de lookup de nomes de cripto no template (com `ETH`/`LTC` mortos, plugin é Bitcoin-only) | Baixa |
| 9 | `paycrypto-me-for-woocommerce.php` | `[DEAD_CODE]` — função `paycrypto_me_before_payment()` nunca chamada — **removida na Fase 0** ✅ | Baixa |
| 10 | `BitcoinPaymentProcessor::process()` (124 linhas), `init_form_fields()` (114), `init_form_fields_items()` Lightning (119), `ajax_test_lnd_connection()` (80) | `[LONG_METHOD]` — vários métodos fazendo 4+ coisas | Média |

**Pontos fortes a preservar:** `BitcoinAddressService` (DI limpa, bem decomposta), `AbstractLightningProcessor` (template method sem duplicação entre BTCPay/lnd), contracts (`GatewayProcessorContract`, `HttpClientContract`, `LightningInvoiceServiceContract`), factories (`ProcessorStrategiesFactory` e derivadas), DTOs (`LightningInvoiceResponse`/`LightningInvoiceStatusResponse`), `AssetManager`, classes de blocos Gutenberg.

### Cobertura de testes — estado atual (pós-Fase 1, 2026-07-02)

Suíte atual: **147 testes, 335 asserções, 0 erros**. ~31 classes fonte, 23 classes de teste (4 novas na Fase 1). Cobertura subiu bastante nas áreas críticas de dinheiro/config; ainda majoritariamente unit-level (sem WP real).

| Risco | Classe | Situação real hoje |
|---|---|---|
| ~~CRÍTICO~~ | `PayCryptoMeLightningDBStatementsService` | ✅ **Fechado na Fase 1** — `PayCryptoMeLightningDBStatementsServiceTest` (9 testes): insert/update/get/exists + invalidação de cache (incl. characterization de que um cache miss nunca fica em cache). |
| ~~CRÍTICO~~ | `WC_Gateway_PayCryptoMe_Lightning` | ✅ **Fechado na Fase 1** (parcial) — `WCGatewayLightningValidationTest` (37 testes): os 9 `validate_*_field()`, `_is_lnd_rest_selected()`, `is_available()`. Handlers AJAX (`ajax_test_btcpay_connection`/`ajax_test_lnd_connection`) seguem sem teste — mais fáceis depois da extração da Fase 2. |
| ~~CRÍTICO~~ | `PayCryptoMeDBStatementsService::reserve_derivation_index_for_wallet()` | ✅ **Fechado na Fase 1** — `GET_LOCK`=0 (já existia) e `RELEASE_LOCK`=0 (novo) cobertos. |
| ~~Alto~~ | `PaymentProcessor::process_payment()` | ✅ **Fechado na Fase 1** — teste fim a fim: hooks before/after (via spy), meta `_paycrypto_me_*`, status `pending`, `get_return_url()`, ramificação do express. Achado colateral corrigido: o stub de teste do `ProcessorStrategiesFactory` nunca era efetivamente usado (perdia a corrida de autoload contra a classe real). |
| ~~Alto~~ | `ProcessorStrategiesFactory` (topo) | ✅ **Fechado na Fase 1** — `ProcessorStrategiesFactoryTest`: gateway inválido lança `InvalidArgumentException`; dispatch feliz Bitcoin/Lightning também coberto. |
| ~~Médio~~ | `AbstractLightningProcessor` | ✅ **Fechado na Fase 1** — retry já estava coberto; adicionado número exato de tentativas (`exactly(2)`) + constantes via reflection. |
| ~~Médio~~ | `BitcoinAddressService` | ✅ **Fechado na Fase 1** — `BitcoinAddressValidationTest` (18 testes): `validate_extended_pubkey()`/`validate_bitcoin_address()` (mainnet/testnet, prefixo inválido, checksum corrompido, mismatch de rede), `build_bitcoin_payment_uri()`. |
| ~~Médio~~ | `BtcpayInvoiceService`/`LndRestInvoiceService` | ✅ **Fechado na Fase 1** — matriz completa HTTP 400/403/404/429/503 (data provider), JSON malformado, timeout. |
| ~~Médio~~ | **Botão express (server-side)** | ✅ **Fechado na Fase 1** — coberto dentro do teste fim a fim do `PaymentProcessor`. |
| Baixo | Blocos Gutenberg (3 classes) | Zero testes (fora de escopo — trilha JS). |

**Problemas estruturais na infraestrutura de teste — estado pós-Fase 1:**
- ~~`apply_filters()`/`do_action()` são no-op~~ — ✅ agora registram invocações (`hook_spy_calls()`/`hook_spy_reset()` em `tests/_support/wp-helpers.php`); dispatch real de hooks/filtros continua fora de escopo (decisão confirmada).
- ~~`WC_Payment_Gateway`/`WC_Order`/shims de função WP duplicados e divergentes entre arquivos~~ — ✅ consolidados numa fonte única (`tests/_support/wp-helpers.php`) na Fase 1, item 0. Foi essa divergência de aridade que causava os 2 erros pré-existentes da Fase 0.
- ~~`BtcpayInvoiceServiceTest`/`LndRestInvoiceServiceTest` reimplementavam o mock de `HttpClientContract` cada um do zero~~ — ✅ extraído para `tests/_support/fake-http-client.php` (`FakeHttpClient`/`http_ok()`/`http_error()`) na Fase 1, item 10.
- Testes de banco usam `FakeWPDB` single-threaded — simulação de lock não captura concorrência real (aceito, sem mudança).
- Endpoint REST do webhook (`paycrypto-me/v1/webhook`) referenciado na UI de settings (`class-wc-gateway-paycrypto-me-lightning.php:158`) mas **não implementado** — fora do escopo (feature premium); registrado aqui só para explicar a ausência de testes de webhook.

---

## Plano de execução (faseado, testes antes de refactor)

### Fase 0 — Persistir auditoria + limpeza trivial ✅
- Documento salvo em `docs/architecture-audit-plan.md` e referenciado no `CLAUDE.md`.
- Removida a função morta `paycrypto_me_before_payment()`.
- Suíte rodada via `docker exec` no container `paycrypto-me-for-woocommerce-wordpress-1` (PHP 8.3.30 / PHPUnit 9.6.34): **52 testes, 2 erros pré-existentes** (confirmados via `git stash` como anteriores à auditoria).
  - `BitcoinPaymentProcessorTest::test_process_uses_existing_address` e `::test_process_generates_and_persists_when_missing` — ambos falham com `PayCryptoMeException: Bitcoin xPub is not configured`, disparado em `class-bitcoin-payment-processor.php:42`. Suspeita: colisão de definição do shim `WC_Payment_Gateway` entre arquivos de teste (guardas `if (!class_exists(...))` fazem o primeiro arquivo carregado "vencer", e o mock resultante não repassa `get_option('network_identifier')`). **Segue vermelho em 2026-07-02** — vira o item 0 da Fase 1.

### Fase 1 — Rede de segurança: testes críticos faltantes + spy mínimo (ANTES de qualquer refactor)
Objetivo: characterization tests que capturem o comportamento **atual**, para as Fases 2+ refatorarem com confiança.

0. ✅ **Concluído (2026-07-02)** — Corrigir os 2 erros pré-existentes em `BitcoinPaymentProcessorTest`. Causa raiz: cada arquivo de teste declarava seu próprio fallback guardado `class WC_Payment_Gateway`, com aridades diferentes de `get_option()`; qual arquivo "vencia" a corrida de `class_exists()` (ordem alfabética de carregamento) decidia silenciosamente a aridade que os mocks de todos os outros arquivos tinham que respeitar — e o mock gerado pelo PHPUnit captura os parâmetros pela assinatura declarada da classe vencedora, não pela quantidade de argumentos realmente passada na chamada. `BitcoinPaymentProcessorTest` assumia 1 argumento em `get_option()` mas herdava a versão de 2 argumentos de `AbstractLightningProcessorTest`, então `willReturnMap()` nunca casava e devolvia `null`. Fix: trocado por `willReturnCallback()` (agnóstico à aridade) e **consolidados todos os shims `WC_Payment_Gateway`/`WC_Order`/funções WP duplicados em 8 arquivos de teste** para `tests/_support/wp-helpers.php` (fonte única), removendo cópias mortas/inconsistentes (uma delas devolvia um valor errado que nunca era realmente usado). Descoberta lateral: `tests/` inteiro estava no `.gitignore` — a suíte nunca tinha ido para o controle de versão; corrigido junto. Suíte: 52 testes, 188 asserções, verde, sem nenhuma asserção alterada.
1. ✅ **`PayCryptoMeLightningDBStatementsServiceTest`** (novo, CRÍTICO) — `insert_invoice()`, `update_status()`, `get_by_order_id()`, `exists_for_order()`, invalidação de cache (incl. um teste que documenta que um cache miss nunca fica em cache — comportamento atual, não corrigido). Adicionado shim `wp_cache_get/set/delete` em memória (não existia).
2. ✅ **Testes para `WC_Gateway_PayCryptoMe_Lightning`** (novo, CRÍTICO) — os 9 `validate_*_field()`, `_is_lnd_rest_selected()`, ramos de `is_available()` (37 testes). Handlers AJAX seguem para depois da extração da Fase 2. Shims novos: `WC_Admin_Settings::add_error()`, `esc_url_raw()`, `wp_parse_url()`, `get_post_data()`/`get_field_key()` no `WC_Payment_Gateway` central.
3. ✅ **Spy mínimo nos shims** — `apply_filters()`/`do_action()` em `tests/_support/wp-helpers.php` agora registram `{tag, args}` em `$GLOBALS['__hook_spy']`, consultável via `hook_spy_calls()`/`hook_spy_reset()`. Continuam no-op (sem dispatch real).
4. ✅ **Aprofundado `PaymentProcessorTest`** fim a fim: hooks before/after (via spy do item 3), meta `_paycrypto_me_*`, status `pending`, `get_return_url()` (ambos os ramos). **Achado real corrigido no processo:** o stub `ProcessorStrategiesFactory` do arquivo nunca era efetivamente usado — `class_alias()` perdia a corrida de `class_exists()` contra a classe real (autoloadável via Composer), então qualquer teste que chamasse `process_payment()` de ponta a ponta caía nos processors reais sem WP de verdade. O teste antigo nunca pegou isso porque só testava os passos privados via reflection, nunca o método público. Corrigido fazendo o teste passar pelo `BitcoinPaymentProcessor` real com um endereço estático (sem precisar de derivação/DB).
5. ✅ **Rede de segurança do express (server-side)** — coberto dentro do item 4 (`test_process_payment_accepts_express_payment_method_variant`).
6. ✅ **`ProcessorStrategiesFactoryTest`** (novo) — gateway inválido lança `InvalidArgumentException`; dispatch feliz para Bitcoin/Lightning também coberto. Só passou a funcionar de verdade depois do fix do item 4 (antes, o `class_alias` do item 4 corrompia esse teste quando a suíte inteira rodava).
7. ✅ **`BitcoinAddressValidationTest`** (novo, 18 testes) — `validate_extended_pubkey()`/`validate_bitcoin_address()` (mainnet/testnet, prefixo inválido, checksum corrompido, mismatch de rede) e `build_bitcoin_payment_uri()` com amount/label/message.
8. ✅ Estendido `PayCryptoMeDBStatementsServiceTest` — `RELEASE_LOCK` retornando 0: reserva ainda é bem-sucedida (o valor de retorno é ignorado dentro do `finally`, sem exceção nem log).
9. ✅ Matriz de erro completa em `BtcpayInvoiceServiceTest`/`LndRestInvoiceServiceTest` — HTTP 400/403/404/429/503 (data provider), JSON malformado, timeout (simulado como array vazio, igual ao que `WpHttpClient` devolve num `WP_Error`).
10. ✅ **Fixture compartilhada `FakeHttpClient`/`http_ok()`/`http_error()`** em `tests/_support/fake-http-client.php` — feita **antes** do item 9 (ordem invertida de propósito) para não duplicar mock que seria refeito na sequência.
11. ✅ *(Opcional)* `AbstractLightningProcessorTest` — número exato de tentativas (`expects($this->exactly(2))`) + constantes `RESOLVE_MAX_ATTEMPTS`/`RESOLVE_DELAY_MS` via reflection (sem live-sleep no teste).

Critério de saída da Fase 1: `./vendor/bin/phpunit` (de `src/trunk/`) verde — **147 testes, 335 asserções, 0 erros** (2026-07-02).

### Fase 2 — Extrações de alto valor (contidas, baixo churn) ✅
Pré-requisito: Fase 1 concluída. Escopo deliberadamente enxuto ("alto valor primeiro") — só o que dá retorno claro, é contido e não conflita com as features em voo.

1. ✅ **`LightningConnectionTester`** (`includes/services/class-lightning-connection-tester.php`) — `ajax_test_btcpay_connection()`/`ajax_test_lnd_connection()` do gateway Lightning viraram one-liners que delegam a `test_btcpay_connection()`/`test_lnd_connection()`; skeleton comum (permission check → nonce → fetch config → request HTTP → `respond_from_http_result()` compartilhado para parse/log/resposta JSON) fatorado num único helper privado. **Decisão tomada com o usuário durante a execução:** os dois métodos chamavam `wp_remote_get()` direto, violando a convenção já documentada no próprio `HttpClientContract` ("never call wp_remote_post/wp_remote_get directly") — trocado por injeção de `HttpClientContract` (`new WpHttpClient()`, mesmo padrão de `BtcpayLightningProcessor`/`LndRestLightningProcessor`), reaproveitando o `FakeHttpClient` já existente para os novos testes em vez de criar shims novos de `wp_remote_get`/`is_wp_error`. **Efeito colateral aceito:** no caminho de falha de rede (WP_Error), a mensagem específica do erro se perde — `WpHttpClient` devolve `[]` e loga só internamente — então o teste de conexão agora mostra "Request failed (HTTP 0)." em vez do erro de rede original. Mensagem de sucesso do lnd também ganhou o "." final que só o btcpay tinha (string compartilhada). Novo shim `wp_trim_words()` adicionado a `tests/_support/wp-helpers.php` (não existia). Testes novos: `LightningConnectionTesterTest` (12 testes).
2. ✅ **`PaymentOrderValidator`** (novo, `includes/processors/class-payment-order-validator.php`) — `validate_order()` (5 checks, incl. a ramificação do express) + `validate_gateway_config()` extraídos do `PaymentProcessor`, que agora só orquestra via `$this->validator` (instanciado no construtor, mesmo estilo de dependência hardcoded já usado no resto do código — DI de verdade fica para a Fase 3+). `PaymentProcessorTest::test_process_payment_success_returns_success_and_redirect()` trocou as duas chamadas via reflection a métodos privados por chamadas diretas aos métodos públicos de `PaymentOrderValidator` (mesmas asserções, só mudou o mecanismo de chamada). Novo shim `wc_price()` adicionado (não existia; necessário para o novo teste do branch `fiat_amount <= 0`). Testes novos: `PaymentOrderValidatorTest` (8 testes).
3. ✅ **Injeção de `QrCodeService` via construtor** no gateway Lightning — `$this->qr_code_service = new QrCodeService()` no construtor, `render_checkout_order_details_section()` usa `$this->qr_code_service` em vez de `new QrCodeService()` inline, alinhado com o On-Chain.

Critério de saída da Fase 2: suíte completa (Fase 1 + pré-existentes) continua verde **sem alteração de asserção** nos testes pré-existentes — confirmado, **168 testes, 388 asserções, 0 erros** (2026-07-02; eram 147 antes da Fase 2, +21 novos testes). A única mudança em teste pré-existente foi mecânica (reflection → chamada direta no item 2, mesmas asserções).

**Follow-up feito ainda dentro da Fase 2** (mesmo arquivo, `abstract-class-lightning-processor.php`, fora do escopo original dos 3 itens): o log principal por pedido (`PaymentProcessor::register_payment_log()`) nunca incluía `crypto_network` — lacuna pré-existente, não introduzida pela Fase 2, só notada durante o smoke test manual. Corrigido adicionando `crypto_network` ao whitelist do log; o `node_type` do Lightning (btcpay/lnd_rest) foi embutido no próprio valor (`"lightning:{$this->node_type()}"`) em vez de virar uma chave `node_type` separada, evitando um `node_type: "N-A"` sem sentido nos logs de pedidos On-Chain. Sem consumidores afetados: o render do Lightning (`class-wc-gateway-paycrypto-me-lightning.php:363`) hardcoda `'lightning'` direto no `payment_display_data` em vez de ler a meta de volta, e o render do On-Chain nunca roda para pedidos Lightning (guard de meta ausente). Teste atualizado: `AbstractLightningProcessorTest::test_process_encodes_node_type_into_crypto_network`.

### Fase 3+ — Breakup integral e DRY amplo (ADIADA, gate liberado)
Backlog **adiado até as 2 costuras do add-on premium serem implementadas** (`get_by_invoice_id()` + action `paycryptome_lightning_status_changed` — ver "Costuras para o add-on premium" abaixo). Gate revisado em 2026-07-02: express e blocos Gutenberg do Lightning já estão assentados (ver "Revisão 2026-07-02 (parte 2)"); esperar pelo webhook em si não fazia sentido como gate, já que ele é intencionalmente uma feature do add-on separado. **As 2 costuras foram implementadas em 2026-07-02** — o backlog abaixo está liberado para começar quando priorizado.

#### Análise de complexidade (2026-07-02)

Cada item foi lido direto no código atual (LOC, métodos, acoplamento de teste via grep em `tests/`) para estimar esforço/risco real, não só a descrição do achado original:

| # | Item | LOC/métodos tocados | Testes afetados | Complexidade |
|---|---|---|---|---|
| 1 | `WC_Gateway_PayCryptoMe_Lightning` → `LightningConfigValidator` + render helper | ~120 LOC (9 validators + `_is_lnd_rest_selected()` + `is_available()`) + ~95 LOC (3 geradores de HTML) | `WCGatewayLightningValidationTest` — **37 testes**, chamando os validators como métodos públicos direto na instância do gateway, e 3 usando `ReflectionMethod` com `WC_Gateway_PayCryptoMe_Lightning::class` **hardcoded** | 🔴 **Alta** |
| 2 | `PaymentProcessor` — mover `init_url_params()`/`instance()`; consolidar log | ~30 LOC | Nenhum teste referencia por nome; só 2 call sites de produção (`Abstract_WC_Gateway_PayCryptoMe::process_payment()`/`process_pre_order_payment()`) usam `PaymentProcessor::instance()` | 🟢 **Baixa** |
| 3 | DRY entre gateways — `PaymentDisplayDataBuilder` + unificar `render_*_order_details_section()` + remover `crypto_names` morto do template | ~45 LOC duplicadas | **Zero** testes tocam `render_admin_order_details_section()`/`render_checkout_order_details_section()` | 🟢 **Baixa** |
| 4 | DRY invoice services — cert temp-file + `parse_response()` | ~30 LOC duplicadas | `BtcpayInvoiceServiceTest`/`LndRestInvoiceServiceTest`, mas testam via HTTP mock/comportamento (`create_invoice`/`get_invoice_status`), nunca por reflection no método interno | 🟢 **Baixa** |
| 5 | Long methods — `init_form_fields()` (114), `enqueue_checkout_styles()` (49), `BitcoinPaymentProcessor::process()` (124) | ~290 LOC ao todo, extract-method puro | Testados só via interface pública (black-box) | 🟢 **Baixa** |
| 6 | DI via construtor nos processors (`BitcoinAddressService`/`PayCryptoMeDBStatementsService`/invoice services) + ajuste das 2 factories | Construtores pequenos (3 classes) + 2 factories | `BitcoinPaymentProcessorTest` **já** contorna o `new` hardcoded via `disableOriginalConstructor()` + reflection — muda pouco | 🟡 **Média** (risco é de API pública quebrar consumidores externos/add-on, não os testes internos) |

**Leitura geral:** itens 2, 3, 4 e 5 são baixo risco — a suíte já exercita essas áreas via interface pública/comportamento, não por acoplamento estrutural. O item 1 é o outlier: os 37 testes de `WCGatewayLightningValidationTest` estão acoplados à *localização e visibilidade* dos métodos na classe do gateway (não só ao comportamento), incluindo `ReflectionMethod` com o nome da classe hardcoded — extrair o `LightningConfigValidator` exige reescrever esses 37 testes, não só mover código. O item 6 é tecnicamente barato mas muda assinatura pública de construtor — atenção ao add-on premium futuro, que pode instanciar essas classes diretamente.

**Recomendação de ordem: 2 → 3 → 4 → 5 → 6 → 1** (baixo risco e ganho rápido primeiro; item 6 médio mas contido; item 1 (alto risco/maior esforço de reescrita de teste) por último).

#### Backlog (detalhe por item, na ordem recomendada de execução)

1. **[🟢 Baixa] `PaymentProcessor`** — mover `init_url_params()` e `instance()` para o bootstrap/singleton (pertencem a `paycrypto-me-for-woocommerce.php` ou uma `PaymentProcessorFactory`, não à orquestração); consolidar o log em `Abstract_WC_Gateway_PayCryptoMe::register_paycrypto_me_log()` ou num `PaymentLogger`.
2. **[🟢 Baixa] DRY entre gateways** — `PaymentDisplayDataBuilder` (normaliza rótulo de rede — ex. o `match()` em `class-wc-gateway-paycrypto-me.php:234-238` —, valor, endereço/invoice, incl. campos de display do express); mover `render_*_order_details_section()` para a abstrata, parametrizadas pelo builder; pré-computar `crypto_label` e remover a tabela `crypto_names` (com `ETH`/`LTC` mortos) do template.
3. **[🟢 Baixa] DRY entre invoice services** — extrair helper único do arquivo de certificado temporário duplicado em `LndRestInvoiceService::create_invoice()`/`::get_invoice_status()`; fatorar auth header + `parse_response()` comum a `BtcpayInvoiceService`/`LndRestInvoiceService`.
4. **[🟢 Baixa] Long methods** — `init_form_fields()` (114) → builder de campos; `enqueue_checkout_styles()` (49, cascata de `file_exists`) → lista de assets orientada a config; `BitcoinPaymentProcessor::process()` (124) → passos (resolução de endereço → persistência → construção de URI).
5. **[🟡 Média] DI nos processors** — injetar `BitcoinAddressService`/`PayCryptoMeDBStatementsService`/invoice services via construtor em `BitcoinPaymentProcessor`, `BtcpayLightningProcessor`, `LndRestLightningProcessor` (ajustando as 2 factories em `includes/strategies/`). Risco é de superfície pública (add-on futuro instanciando direto), não de teste interno.
6. **[🔴 Alta] `WC_Gateway_PayCryptoMe_Lightning`** — `LightningConfigValidator` (os 9 validadores + `_is_lnd_rest_selected()` + helpers); mover os geradores de HTML (`generate_node_type_html`/`generate_btcpay_test_button_html`/`generate_lnd_test_button_html`) para um helper de renderização de campo; deixar o gateway como só o `WC_Payment_Gateway` (settings, `is_available`, `payment_fields`). Fazer por último: exige reescrever os 37 testes de `WCGatewayLightningValidationTest` (hoje acoplados à localização/visibilidade dos métodos no gateway, incl. `ReflectionMethod` hardcoded), não só mover código.

---

## Fora de escopo (registrado, não faz parte deste plano)

### Reservado para a versão premium/freemium (intencional, NÃO é gap de desenvolvimento)

Estes dois itens **não** são trabalho incompleto — são decisões de escopo de produto, deliberadamente ausentes da versão gratuita e reservados para um tier premium futuro. Não devem ser "corrigidos" para dentro da versão free nem tratados como dívida:

- **Endpoint REST do webhook (`paycrypto-me/v1/webhook`) + atualização assíncrona de status** — a UI de settings já referencia o endpoint (a costura existe), mas o `register_rest_route()` é feature premium. Confirmação automática de status (webhook push do BTCPay; polling lnd via `wp_schedule_event`) pertence ao tier pago.
- **Conversão fiat→sats** — invoices são zero-amount de propósito na versão free; a conversão do total fiat em `amount_sats` é feature premium, projetada para plugar via os filtros `paycryptome_lightning_btcpay_invoice_args` / `paycryptome_lightning_lnd_invoice_args` sem alterar o core.

### Trilhas separadas / decisões maiores

- Blocos Gutenberg do Lightning e a lógica JS do botão express — trilha de WIP de front-end separada. (A Fase 1 cobre só a lógica **de servidor** do express.)
- **Reescrita ampla** dos shims de WP/WC para executar hooks/filtros de verdade — decisão maior, à parte. A Fase 1 faz apenas o *spy mínimo* necessário para asserir disparo de hooks.

---

## Costuras para o add-on premium (zero-core-edit)

**Decisão de entrega:** as features premium (webhook + status assíncrono, fiat→sats — ver "Reservado para a versão premium/freemium" acima) serão distribuídas como um **plugin add-on separado**, não como gating dentro deste plugin. O add-on depende deste plugin base e pluga via hooks/filtros — o core free não deve ter nenhuma lógica premium nem `if (is_premium())`.

Para o add-on ser **100% zero-core-edit**, o plugin base precisa expor os pontos de extensão abaixo. São mudanças **aditivas e de baixo risco** (não quebram o free, não bloqueiam a Fase 1 — podem entrar junto da Fase 3+ ou como trilha própria quando o add-on começar):

| # | Ponto de extensão | Lado | Status |
|---|---|---|---|
| 1 | **`PayCryptoMeLightningDBStatementsService::get_by_invoice_id()`** — lookup por invoice id (hoje só há `get_by_order_id`), para casos em que o provider retorne só o id da invoice no webhook | base | ✅ **Adicionado (2026-07-02)** — sem cache (lookup pontual, diferente de `get_by_order_id`) |
| 2 | **Action de domínio `do_action('paycryptome_lightning_status_changed', $order_id, $old_status, $new_status)`** disparada dentro de `update_status()` — deixa o add-on (e terceiros) reagirem a mudança de status sem monkey-patch | base | ✅ **Adicionado (2026-07-02)** — só dispara quando a linha existia e o status realmente mudou (sem ruído em update para o mesmo status ou em pedido inexistente) |
| 3 | **Filtros de invoice args carregam contexto do pedido** (`$order` + `$gateway`) para o add-on computar sats a partir do total fiat | base | ✅ Já satisfeito — `apply_filters($this->invoice_args_filter(), $args, $order, $this->gateway)` em `abstract-class-lightning-processor.php:30-44` |
| 4 | **Extensão de settings** — o add-on pode apèndar campos à tela do gateway Lightning via o filtro que o WooCommerce já oferece (`woocommerce_settings_api_form_fields_paycrypto_me_lightning`), sem tocar em `init_form_fields()` | WC (nativo) | ✅ Já disponível — nenhuma mudança no base |
| 5 | **Guard de dependência** — checar `class_exists('PayCryptoMe\\WooCommerce\\...')` + versão mínima do base, com `admin_notice` se o base estiver ausente/desatualizado | add-on | ⬜ Responsabilidade do add-on (não é mudança no base) |

**Como cada feature premium pluga (referência):**
- **fiat→sats** — add-on engancha em `paycryptome_lightning_btcpay_invoice_args` / `..._lnd_invoice_args` (já recebem `$order`), computa `amount` em sats e devolve o array. Zero mudança no core.
- **webhook + status** — add-on registra a própria rota (`register_rest_route('paycrypto-me/v1', '/webhook', …)`), valida a assinatura (segredo já é option do gateway), acha o pedido (`orderId` do payload → `get_by_order_id()` ou o novo `get_by_invoice_id()`), chama `update_status()` e `$order->payment_complete()`. Para lnd, polling via `wp_schedule_event`.
- **licenciamento** — camada fina no add-on (chave validada contra servidor próprio ou Freemius/EDD SL); sem licença, o add-on não registra os hooks. O base nunca conhece licença.

**Execução das costuras 1 e 2 (2026-07-02):** ambas implementadas em `PayCryptoMeLightningDBStatementsService` —
- `get_by_invoice_id(string $invoice_id): ?array` — mesmo padrão de query do `get_by_order_id()`, mas **sem cache** (lookup pontual disparado por webhook/polling, não por request de checkout repetido — não valia a complexidade extra de uma segunda chave de cache).
- `update_status()` agora lê o `status` atual da linha **antes** do `UPDATE` e, só se a linha existia e o status realmente mudou, dispara `do_action('paycryptome_lightning_status_changed', $order_id, $old_status, $new_status)` — decisão deliberada de não disparar em update para o mesmo status nem em pedido inexistente, para o add-on não precisar filtrar ruído.
- Hook documentado na tabela "Public hooks" do `CLAUDE.md`.
- Testes novos em `PayCryptoMeLightningDBStatementsServiceTest`: `get_by_invoice_id` (encontrado/não encontrado) + `update_status` disparando a action (mudança real, mesma status, pedido inexistente) via o spy já existente (`hook_spy_calls()`). Suíte: **173 testes, 395 asserções, 0 erros** (eram 168 antes desta execução).

## Verificação

- Rodar `./vendor/bin/phpunit` (de `src/trunk/`, via `docker exec` no container `paycrypto-me-for-woocommerce-wordpress-1`) ao final de cada fase — deve permanecer verde.
- Fase 1: os novos testes devem primeiro passar contra o código **atual** (provando que capturam o comportamento existente) antes de qualquer refactor começar.
- Fase 2: mesma suíte (Fase 1 + pré-existentes) sem nenhuma mudança de asserção — só o código de produção muda.
- Smoke test manual: criar pedido de teste via On-Chain (endereço estático e via xPub), via Lightning (BTCPay sandbox, se disponível) e via **botão express** de ambos, confirmando que checkout e order-details continuam funcionando após a Fase 2. ✅ **Feito e confirmado pelo usuário** (2026-07-02).
- Costuras do add-on premium (2026-07-02): `./vendor/bin/phpunit` verde, **173 testes, 0 erros**, sem alteração de asserção em testes pré-existentes.
