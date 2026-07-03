# Prompt de execução — Fase 3+ (breakup das god classes + DRY)

> Uso: colar este prompt inteiro no início de uma sessão dedicada a executar a Fase 3+ de `docs/architecture-audit-plan.md`. Ele assume que o agente vai ler os dois documentos referenciados antes de tocar em código.

---

Você vai executar a **Fase 3+** do audit de arquitetura do plugin PayCrypto.Me for WooCommerce, documentada em `docs/architecture-audit-plan.md` (seção "Fase 3+ — Breakup integral e DRY amplo"). Antes de qualquer edição, leia:

1. `docs/architecture-audit-plan.md` — seção "Fase 3+" completa, incluindo a nota de "Parecer de arquitetura 2026-07-03" logo no topo dessa seção e a tabela de complexidade revisada.
2. `.claude/plans/voc-um-arquiteto-parallel-mochi.md` — parecer completo que justifica por que fazer, em que ordem, e os dois riscos técnicos que já foram mapeados.

Não repita a análise de complexidade nem a decisão de "fazer vs. abandonar" — isso já está fechado. Sua tarefa é **executar com disciplina de testes**, um item por vez, na ordem abaixo.

## Ordem de execução (não alterar sem justificativa nova)

```
2 → 3 → 4 → 6 → 1 → 5
```
(numeração do backlog em `docs/architecture-audit-plan.md`, não da tabela de complexidade)

1. `PaymentProcessor` — mover `init_url_params()`/`instance()` para o bootstrap; consolidar log.
2. DRY entre gateways — `PaymentDisplayDataBuilder` + unificar `render_*_order_details_section()` + remover `crypto_names` morto do template.
3. DRY entre invoice services — helper único para o arquivo de certificado temporário do lnd; fatorar auth header + `parse_response()`.
4. DI nos processors (`BitcoinAddressService`/`PayCryptoMeDBStatementsService`/invoice services via construtor) — **priorizado antes do item 1 (Lightning) por causa da janela de tempo do construtor público**, ver cautela específica abaixo.
5. `WC_Gateway_PayCryptoMe_Lightning` → `LightningConfigValidator` — **desenho obrigatório de stubs de encaminhamento**, ver cautela específica abaixo.
6. Long methods (`init_form_fields()`, `enqueue_checkout_styles()`, `BitcoinPaymentProcessor::process()`) — só depois de tudo acima, ou sob demanda.

## Regras gerais (aplicam a todos os itens)

- **Um item por vez, suíte verde entre cada um.** Nunca acumule mudanças de dois itens antes de rodar `./vendor/bin/phpunit` (de `src/trunk/`, via `docker exec` no container `paycrypto-me-for-woocommerce-wordpress-1` se for o ambiente configurado — confirmar com o usuário se o container está de pé).
- **Zero mudança de asserção em teste pré-existente**, salvo mudança puramente mecânica (ex.: trocar uma chamada por reflection por uma chamada direta ao método público equivalente, com a mesma assertiva). Se uma asserção precisar mudar de *valor esperado*, pare e avise — isso é sinal de que o refactor alterou comportamento, não só estrutura.
- **Sem mudança de comportamento observável.** Isso é extract-method/extract-class/DRY, não uma reescrita. Se você perceber uma oportunidade de "melhorar" algo além do escopo do item (ex. mudar uma mensagem de erro, ajustar uma validação), não faça — documente como achado separado e siga em frente.
- **Não mexer em nada relacionado a webhook REST ou conversão fiat→sats.** Isso é escopo do add-on premium (plugin separado) — ver `CLAUDE.md`, seção "Scope boundaries". Se algum refactor tocar acidentalmente nesses seams (`get_by_invoice_id()`, action `paycryptome_lightning_status_changed`, filtros de invoice args), a interface pública desses seams não pode mudar de assinatura nem de comportamento — o add-on já depende deles.
- **Smoke test manual obrigatório ao final do lote inteiro** (não a cada item): criar pedido de teste via On-Chain (endereço estático e via xPub), via Lightning (BTCPay sandbox se disponível) e via botão express de ambos — mesma checklist já usada nas Fases 1/2. Não marcar a Fase 3+ como concluída sem essa confirmação do usuário.
- **Atualizar `docs/architecture-audit-plan.md`** ao final de cada item concluído (marcar ✅, anotar contagem de testes/asserções nova), seguindo o mesmo padrão de registro já usado nas Fases 0-2 — isso é o histórico de auditoria do projeto, não documentação solta.

## Cautelas específicas por item

### Item 4 — DI nos processors (fazer cedo, não por último)
- É mudança de **assinatura de construtor público**. Hoje (v0.1.0, sem consumidores externos conhecidos fora do add-on premium em construção) é uma mudança livre; depois que o add-on solidificar instanciações diretas dessas classes, vira breaking change de verdade. Por isso está adiantado na ordem de execução.
- `BitcoinPaymentProcessorTest` já contorna o `new` hardcoded via `disableOriginalConstructor()` + reflection — ao adicionar parâmetros de construtor, ajuste esses testes para injetar os fakes/mocks em vez de usar reflection para pular o construtor. Isso é uma melhoria colateral esperada, não um desvio de escopo.
- Ajustar as 2 factories (`includes/strategies/`) que hoje fazem `new BitcoinPaymentProcessor()` / `new BtcpayLightningProcessor()` / `new LndRestLightningProcessor()` sem argumentos — elas precisam passar as dependências agora explícitas.
- Se usar valores default nos parâmetros do construtor (`= null` com fallback para `new Service()` dentro do construtor), isso preserva compatibilidade retroativa com qualquer código externo que já faça `new BitcoinPaymentProcessor()` sem argumentos — considere essa opção se quiser reduzir ainda mais o risco de breaking change, mesmo sendo cedo.

### Item 5 — `LightningConfigValidator` (o item antes temido como "Alta")
- **Não remover os 9 métodos `validate_*_field` do gateway.** O WooCommerce (`WC_Settings_API::validate_settings_fields()`) descobre esses métodos via `method_exists($this, 'validate_<key>_field')` **na própria instância do gateway** — isso não está vendorizado nem emulado nos shims de teste deste projeto (`tests/_support/wp-helpers.php`), então nenhum teste vai pegar a quebra se você remover os métodos; ela só aparece em produção real, na tela de settings do WooCommerce.
- **Desenho correto:** criar `LightningConfigValidator` (namespace `PayCryptoMe\WooCommerce`, provavelmente em `includes/services/` ou `includes/validators/`) com a lógica real dos 9 validators + `_is_lnd_rest_selected()` + `_sanitize_text_val()`. No gateway, manter **stubs públicos de uma linha** para cada `validate_*_field`, delegando ao validator (`$this->config_validator`, injetado no construtor). `_is_lnd_rest_selected()` pode virar público no validator (recebendo o `$_POST`/field key necessário) — os 3 testes que hoje usam `ReflectionMethod(WC_Gateway_PayCryptoMe_Lightning::class, '_is_lnd_rest_selected')` (linhas ~40-70 de `WCGatewayLightningValidationTest.php`) precisam ser redirecionados para o novo local.
- Os outros 34 testes (que chamam `$gateway->validate_btcpay_url_field(...)` etc. como método público) **não devem precisar de nenhuma mudança** — se você perceber que precisou mudar mais de 3-4 desses testes, o desenho da extração desviou do esperado; pare e reavalie antes de continuar.
- Escreva um teste novo e independente para `LightningConfigValidator` (sem precisar montar mock de `WC_Payment_Gateway`) — esse é o ganho real de testabilidade que justifica o item.
- Os 3 geradores de HTML (`generate_node_type_html`/`generate_btcpay_test_button_html`/`generate_lnd_test_button_html`) podem ir para um helper de renderização separado, mas confirme se algum deles é referenciado por `name` de callback do WooCommerce (`custom_attributes`/`type => 'custom_field_type'` nos form fields) antes de mover — se for, o mesmo cuidado de stub/encaminhamento se aplica.

### Item 2 — DRY entre gateways
- Nenhum teste hoje toca `render_admin_order_details_section()`/`render_checkout_order_details_section()` (confirmado no audit) — ou seja, você não tem rede de segurança automatizada para esse item. Compense escrevendo characterization tests **antes** de extrair (capturar o HTML/array atual gerado para os dois gateways com um payload de exemplo), não só confiar no smoke test manual do final do lote.
- Ao remover a tabela `crypto_names` do template (com `ETH`/`LTC` mortos), confirme que não há nenhum filtro público (`paycryptome_*`) documentado no `CLAUDE.md` que dependa desses valores antes de apagar.

### Item 3 — DRY entre invoice services
- Mesma observação de rede de segurança: `BtcpayInvoiceServiceTest`/`LndRestInvoiceServiceTest` testam via comportamento HTTP (mock de `HttpClientContract`), não via reflection no método interno — então a extração é segura *desde que* o comportamento observável (retorno de `create_invoice()`/`get_invoice_status()`, código de erro/exceção lançada) não mude. Rode a matriz de erro completa (400/403/404/429/503, JSON malformado, timeout) depois da extração, não só o happy path.
- O manuseio de arquivo de certificado temporário (`LndRestInvoiceService`) é código sensível (cria/apaga arquivo em disco com material potencialmente sensível — cert TLS). Ao unificar, garanta que o cleanup (`unlink`/`finally`) continua acontecendo em todos os caminhos, incluindo exceção.

### Item 1 — `PaymentProcessor::init_url_params()`/`instance()`
- Baixo risco confirmado (nenhum teste referencia por nome; só 2 call sites de produção usam `PaymentProcessor::instance()`) — mas confirme os 2 call sites (`Abstract_WC_Gateway_PayCryptoMe::process_payment()`/`process_pre_order_payment()`) continuam funcionando após mover o singleton/bootstrap.

### Item 6 — Long methods
- Menor prioridade do lote — só extract-method puro, sem redução de duplicação ou risco de API pública. Fazer por último ou pular se o tempo apertar; não é bloqueio para considerar a Fase 3+ "concluída" em espírito, mas registre no plano se for deliberadamente adiado.

## Critério de saída da Fase 3+

- `./vendor/bin/phpunit` verde, zero erro, zero asserção pré-existente alterada (fora das mudanças mecânicas documentadas acima).
- Smoke test manual (On-Chain estático + xPub, Lightning BTCPay, express em ambos) confirmado pelo usuário.
- `docs/architecture-audit-plan.md` atualizado com o status de cada item e a contagem final de testes/asserções, no mesmo padrão das Fases 0-2.
- Nenhuma mudança de assinatura/comportamento nos seams do add-on premium (`get_by_invoice_id()`, `paycryptome_lightning_status_changed`, filtros de invoice args).
