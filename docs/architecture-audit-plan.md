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

### Status por fase

| Fase | Status |
|---|---|
| Fase 0 — persistir auditoria + limpeza trivial | ✅ **Concluída** (2026-07-01) |
| Fase 1 — testes críticos faltantes (rede de segurança) + spy mínimo nos shims | ⬜ Não iniciada (re-baselined em 2026-07-02) |
| Fase 2 — extrações de alto valor (dedup dos AJAX testers, `PaymentOrderValidator`, DI de `QrCodeService`) | ⬜ Não iniciada |
| Fase 3+ (adiada) — breakup integral das god classes + DRY amplo | ⬜ Backlog, gated em webhook/blocos/express assentarem |

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

### Cobertura de testes — estado atual (2026-07-02)

Suíte atual: **52 testes, 2 erros pré-existentes** (não relacionados à auditoria). ~31 classes fonte, 19 classes de teste. Cobertura estimada 40-50%, majoritariamente happy-path.

| Risco | Classe | Situação real hoje |
|---|---|---|
| **CRÍTICO** | `PayCryptoMeLightningDBStatementsService` | **Zero testes** — `insert_invoice()`, `update_status()`, `get_by_order_id()`, `exists_for_order()`, invalidação de cache nunca exercitados. **Gap aberto.** |
| **CRÍTICO** | `WC_Gateway_PayCryptoMe_Lightning` | **Zero testes** — 9 validadores, `ajax_test_btcpay_connection`, `ajax_test_lnd_connection`, `_is_lnd_rest_selected()`, `is_available()`. **Gap aberto.** |
| **CRÍTICO** (mitigado) | `PayCryptoMeDBStatementsService::reserve_derivation_index_for_wallet()` | `GET_LOCK` retornando 0 **já testado** (`ReserveDerivationConcurrencyTest`). Falta só o caso de `RELEASE_LOCK` retornando 0. |
| Alto | `PaymentProcessor::process_payment()` | Teste **existe mas é oco** — só asserta `payment_data`/pre-check. Faltam: disparo de `before/after_payment`, `update_order_after_payment` (meta/status), `get_return_url`, e a ramificação do express (`:207-209`). |
| Alto | `ProcessorStrategiesFactory` (topo) | Caso de gateway inválido (`InvalidArgumentException`) **não testado**. (A `LightningProcessorStrategiesFactory` já tem roteamento btcpay/lnd/default coberto.) |
| Médio (mitigado) | `AbstractLightningProcessor` | Retry **já coberto** (resolução tardia, skip, esgotamento). Opcional: asserir número de tentativas/intervalo exatos. |
| Médio | `BitcoinAddressService` | `validate_extended_pubkey()`, `validate_bitcoin_address()`, `build_bitcoin_payment_uri()` **sem testes** (só geração é coberta via vetores). |
| Médio | `BtcpayInvoiceService`/`LndRestInvoiceService` | Alguns erros já cobertos; falta completar a matriz: HTTP 400/403/404/429/503, JSON malformado, timeout. |
| Médio | **Botão express (server-side)** | A única lógica de servidor é o aceite de `{gateway_id}_express` em `validate_order()` (`:207-209`). **Sem teste.** Resto é settings/JS. |
| Baixo | Blocos Gutenberg (3 classes) | Zero testes (fora de escopo — trilha JS). |

**Problemas estruturais na infraestrutura de teste:**
- `tests/_support/wp-helpers.php` (e stubs locais em arquivos de teste): `apply_filters()`/`do_action()` são no-op — **mascaram disparo de hooks**. A Fase 1 evolui isso *o mínimo* (spy que registra invocações), sem reescrever a infra.
- Testes de banco usam `FakeWPDB` single-threaded — simulação de lock não captura concorrência real (aceito).
- `BtcpayInvoiceServiceTest` e `LndRestInvoiceServiceTest` reimplementam o mock de `HttpClientContract` cada um do zero — sem fixture compartilhada.
- Endpoint REST do webhook (`paycrypto-me/v1/webhook`) referenciado na UI de settings (`class-wc-gateway-paycrypto-me-lightning.php:158`) mas **não implementado** — fora do escopo (feature nova); registrado aqui só para explicar a ausência de testes de webhook.

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
1. **`PayCryptoMeLightningDBStatementsServiceTest`** (novo, CRÍTICO) — `insert_invoice()`, `update_status()`, `get_by_order_id()`, `exists_for_order()`, invalidação de cache. Seguir o padrão de `PayCryptoMeDBStatementsServiceTest` (FakeWPDB).
2. **Testes para `WC_Gateway_PayCryptoMe_Lightning`** (novo, CRÍTICO) — o que é testável isoladamente sem WP real: os 9 `validate_*_field()`, `_is_lnd_rest_selected()`, `_sanitize_text_val()`, ramos de `is_available()`. Os handlers AJAX (`ajax_test_btcpay_connection`/`ajax_test_lnd_connection`) ficam mais fáceis de testar depois de extraídos na Fase 2 — por ora cobrir a lógica interna via reflection, como já feito em `WCGatewayAjaxTest` para o On-Chain.
3. **Spy mínimo nos shims** — evoluir `apply_filters()`/`do_action()` (em `tests/_support/wp-helpers.php` e/ou nos stubs locais) para *registrar* as invocações, sem reescrever a infra. Habilita o item 4.
4. **Aprofundar `PaymentProcessorTest`** (hoje oco) para cobrir `process_payment()` fim a fim: disparo de `paycryptome_before_payment`/`paycryptome_after_payment` (via spy do item 3), `update_order_after_payment` (meta `_paycrypto_me_*` + status `pending`), `get_return_url`, e a **ramificação do express** (`validate_order()` aceitando `{gateway_id}_express`).
5. **Rede de segurança do express (server-side)** — teste dedicado (pode viver no item 4) provando que um pedido com método `{gateway_id}_express` passa a validação de método. É a única lógica de servidor do express.
6. **`ProcessorStrategiesFactoryTest`** (novo/extensão) — gateway ID inválido lança `InvalidArgumentException` na factory de topo.
7. **`BitcoinAddressValidationTest`** (novo) — `validate_extended_pubkey()` (xpub/ypub/zpub em mainnet/testnet), `validate_bitcoin_address()`, `build_bitcoin_payment_uri()` com amount/label/message.
8. Estender `PayCryptoMeDBStatementsServiceTest` com o caso faltante: `RELEASE_LOCK` retornando 0 (o de `GET_LOCK` já existe).
9. Completar a matriz de erro em `BtcpayInvoiceServiceTest`/`LndRestInvoiceServiceTest` — HTTP 400/403/404/429/503, JSON malformado, timeout.
10. **Fixture compartilhada de mock de `HttpClientContract`** — extrair o mock hoje duplicado nos dois arquivos de teste de invoice.
11. *(Opcional)* Estender `AbstractLightningProcessorTest` para asserir número de tentativas/intervalo exatos do retry (o esgotamento já é coberto).

Critério de saída da Fase 1: `./vendor/bin/phpunit` (de `src/trunk/`) verde, incluindo os 2 erros do item 0 resolvidos e as novas suítes passando contra o código **atual**.

### Fase 2 — Extrações de alto valor (contidas, baixo churn)
Pré-requisito: Fase 1 concluída. Escopo deliberadamente enxuto ("alto valor primeiro") — só o que dá retorno claro, é contido e não conflita com as features em voo.

1. **`LightningConnectionTester`** — extrair `ajax_test_btcpay_connection()` (58 linhas) + `ajax_test_lnd_connection()` (80 linhas) do gateway Lightning, com o esqueleto comum (permission check → nonce → fetch config → request HTTP → parse resposta → log → resposta JSON) fatorado para eliminar a duplicação entre os dois. Alto valor: remove duplicação real e torna os testers unit-testáveis.
2. **`PaymentOrderValidator`** (novo) — extrair `validate_order()` (5 checks, incl. a ramificação do express) + `validate_gateway_config()` do `PaymentProcessor`. Alto valor: isola a lógica de validação num colaborador testável e adelgaça o orquestrador.
3. **Injeção de `QrCodeService` via construtor** no gateway Lightning (`render_checkout_order_details_section()`, `:481` — hoje `new QrCodeService()`), alinhando com o On-Chain que já mantém `$this->qr_code_service` (`:50`). Trivial, melhora testabilidade e consistência.

Critério de saída da Fase 2: suíte completa (Fase 1 + pré-existentes) continua verde **sem alteração de asserção** — só o código de produção muda. Se um teste precisar mudar, é sinal de mudança de comportamento não intencional.

### Fase 3+ — Breakup integral e DRY amplo (ADIADA)
Backlog explicitamente **adiado até webhook, blocos Gutenberg do Lightning e o botão express assentarem** — quebrar essas classes com features em voo geraria churn e conflitos. Registrado aqui para não se perder:

- **`WC_Gateway_PayCryptoMe_Lightning`** — `LightningConfigValidator` (os 9 validadores + helpers); mover os geradores de HTML (`generate_node_type_html`/`generate_btcpay_test_button_html`/`generate_lnd_test_button_html`) para um helper de renderização de campo; deixar o gateway como só o `WC_Payment_Gateway` (settings, `is_available`, `payment_fields`).
- **`PaymentProcessor`** — mover `init_url_params()` e `instance()` para o bootstrap/singleton (pertencem a `paycrypto-me-for-woocommerce.php` ou uma `PaymentProcessorFactory`, não à orquestração); consolidar o log em `Abstract_WC_Gateway_PayCryptoMe::register_paycrypto_me_log()` ou num `PaymentLogger`.
- **DRY entre gateways** — `PaymentDisplayDataBuilder` (normaliza rótulo de rede — ex. o `match()` em `class-wc-gateway-paycrypto-me.php:234-238` —, valor, endereço/invoice, incl. campos de display do express); mover `render_*_order_details_section()` para a abstrata, parametrizadas pelo builder; pré-computar `crypto_label` e remover a tabela `crypto_names` (com `ETH`/`LTC` mortos) do template.
- **DRY entre invoice services** — extrair helper único do arquivo de certificado temporário duplicado em `LndRestInvoiceService::create_invoice()`/`::get_invoice_status()`; fatorar auth header + `parse_response()` comum a `BtcpayInvoiceService`/`LndRestInvoiceService`.
- **Long methods** — `init_form_fields()` (114) → builder de campos; `enqueue_checkout_styles()` (49, cascata de `file_exists`) → lista de assets orientada a config; `BitcoinPaymentProcessor::process()` (124) → passos (resolução de endereço → persistência → construção de URI).
- **DI nos processors** — injetar `BitcoinAddressService`/`PayCryptoMeDBStatementsService`/invoice services via construtor em `BitcoinPaymentProcessor`, `BtcpayLightningProcessor`, `LndRestLightningProcessor` (ajustando as factories).

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
| 1 | **`PayCryptoMeLightningDBStatementsService::get_by_invoice_id()`** — lookup por invoice id (hoje só há `get_by_order_id`), para casos em que o provider retorne só o id da invoice no webhook | base | ⬜ A adicionar |
| 2 | **Action de domínio `do_action('paycryptome_lightning_status_changed', $order_id, $old_status, $new_status)`** disparada dentro de `update_status()` — deixa o add-on (e terceiros) reagirem a mudança de status sem monkey-patch | base | ⬜ A adicionar |
| 3 | **Filtros de invoice args carregam contexto do pedido** (`$order` + `$gateway`) para o add-on computar sats a partir do total fiat | base | ✅ Já satisfeito — `apply_filters($this->invoice_args_filter(), $args, $order, $this->gateway)` em `abstract-class-lightning-processor.php:30-44` |
| 4 | **Extensão de settings** — o add-on pode apèndar campos à tela do gateway Lightning via o filtro que o WooCommerce já oferece (`woocommerce_settings_api_form_fields_paycrypto_me_lightning`), sem tocar em `init_form_fields()` | WC (nativo) | ✅ Já disponível — nenhuma mudança no base |
| 5 | **Guard de dependência** — checar `class_exists('PayCryptoMe\\WooCommerce\\...')` + versão mínima do base, com `admin_notice` se o base estiver ausente/desatualizado | add-on | ⬜ Responsabilidade do add-on (não é mudança no base) |

**Como cada feature premium pluga (referência):**
- **fiat→sats** — add-on engancha em `paycryptome_lightning_btcpay_invoice_args` / `..._lnd_invoice_args` (já recebem `$order`), computa `amount` em sats e devolve o array. Zero mudança no core.
- **webhook + status** — add-on registra a própria rota (`register_rest_route('paycrypto-me/v1', '/webhook', …)`), valida a assinatura (segredo já é option do gateway), acha o pedido (`orderId` do payload → `get_by_order_id()` ou o novo `get_by_invoice_id()`), chama `update_status()` e `$order->payment_complete()`. Para lnd, polling via `wp_schedule_event`.
- **licenciamento** — camada fina no add-on (chave validada contra servidor próprio ou Freemius/EDD SL); sem licença, o add-on não registra os hooks. O base nunca conhece licença.

## Verificação

- Rodar `./vendor/bin/phpunit` (de `src/trunk/`, via `docker exec` no container `paycrypto-me-for-woocommerce-wordpress-1`) ao final de cada fase — deve permanecer verde.
- Fase 1: os novos testes devem primeiro passar contra o código **atual** (provando que capturam o comportamento existente) antes de qualquer refactor começar.
- Fase 2: mesma suíte (Fase 1 + pré-existentes) sem nenhuma mudança de asserção — só o código de produção muda.
- Rodar `phpcs` (WooCommerce coding standards) antes de considerar cada fase concluída.
- Smoke test manual: criar pedido de teste via On-Chain (endereço estático e via xPub), via Lightning (BTCPay sandbox, se disponível) e via **botão express** de ambos, confirmando que checkout e order-details continuam funcionando após a Fase 2.
