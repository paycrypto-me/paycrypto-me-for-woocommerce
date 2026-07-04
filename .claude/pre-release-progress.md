# Progresso — Plano de pré-release

> Checklist de execução do [`pre-release-plan.md`](./pre-release-plan.md). Marcar `[x]` conforme
> cada item for concluído. Estado inicial abaixo foi verificado direto no código/docs em
> 2026-07-04 (não apenas assumido a partir do texto do plano — alguns itens que o plano descrevia
> como "já corrigido" foram conferidos e um deles precisou de ajuste de status, ver nota no Passo 1).

## Visão geral

| Passo | Descrição | Progresso |
|---|---|---|
| 1 | Validar bug do `crypto_network` já corrigido | 2/2 ✅ |
| 2 | Cabeçalho do plugin / `readme.txt` — metadados | 0/7 |
| 3 | Traduções — regenerar catálogo e completar 7 locales | 0/6 |
| 4 | Documentar persistência de dados na desinstalação | 0/1 |
| 5 | `debug_log` default `yes` → `no` | 0/3 |
| 6 | Guia de captura de screenshots | 0/2 |
| 7 | Documentar envio de `src/assets/` ao SVN + limpar redundância | 0/2 |
| 8 | Fechamento do plano | 0/2 |
| 9 | 🔴 **Crítico** — Impedir seções de pagamento duplicadas ao trocar de gateway | 0/6 |
| **Total** | | **2/31** |

> ⚠️ O passo 9 foi achado via teste manual em 2026-07-04, depois do plano original — é bug
> funcional com risco de pagamento duplo (não só metadados/conteúdo). Recomenda-se priorizá-lo
> antes ou junto do passo 1, não na ordem numérica.

---

### 1. Bug já resolvido — validação ✅ concluído
- [x] Rodar suíte PHPUnit completa — confirmado nesta sessão via
      `docker compose exec wordpress ./vendor/bin/phpunit` dentro de
      `/var/www/html/wp-content/plugins/paycrypto-me-for-woocommerce`: **218 tests, 495 assertions,
      OK**. O teste relevante hoje se chama `test_process_encodes_node_type_into_crypto_network`
      (não `test_process_exposes_node_type_under_its_own_key` como o plano menciona — nome
      diferente do descrito, mas o comportamento está correto e coberto: o badge de
      "Crypto Network" no order-details usa `'lightning'` puro via
      `build_order_display_args()`, enquanto `payment_data['crypto_network']` mantém
      `"lightning:{node_type}"` de propósito, conforme comentário no próprio teste).
- [x] Commit da correção — `git status` limpo, sem pendências relacionadas a este arquivo.

### 2. Cabeçalho do plugin e `readme.txt` — consistência de metadados
- [ ] **License** — decidir com o mantenedor. Ainda divergente: `readme.txt` usa
      `GPL-3.0-or-later`, cabeçalho do plugin usa `GNU General Public License v3.0` (sem "or later").
- [ ] **Donate link** — decidir com o mantenedor. Ainda divergente: `readme.txt` aponta para
      `https://paycrypto.me/`, cabeçalho do plugin para `https://gravatar.com/lucasrosa95`.
- [ ] `Description:` do cabeçalho ainda diz "many cryptocurrencies" — não corrigido.
- [ ] Adicionar `Requires Plugins: woocommerce`, `WC requires at least`, `WC tested up to` —
      ausentes nos dois arquivos.
- [ ] Revisar `Tested up to:` (hoje `6.9` nos dois arquivos) contra o WordPress real testado.
- [ ] `readme.txt`: trocar a tag `cryptocurrencies` por algo mais preciso — ainda presente em
      `Tags: woocommerce, payments, crypto, bitcoin, cryptocurrencies`.
- [ ] `readme.txt`: adicionar seção `== Privacy ==` — não existe hoje (nenhuma menção a
      "privacy"/"desinstal"/"uninstall" no arquivo).

### 3. Traduções — regenerar catálogo e completar as 7 localidades
- [ ] Rodar `npm run translate:pot` — nenhum `.pot` existe hoje em `src/trunk/languages/`.
- [ ] Sincronizar os 7 `.po` (`de_DE, es_ES, fr_FR, it_IT, pt_BR, ru_RU, zh_CN`) via `npm run translate` —
      depende do `.pot` acima.
- [ ] Traduzir as novas strings nas 7 localidades.
- [ ] Revisar strings obsoletas (`#~`) nos `.po` existentes.
- [ ] Rodar `npm run translate:mo` para regerar os binários.
- [ ] Corrigir "📊 Status Atual" em `docs/TRANSLATION.md` (linha ~176) — ainda lista
      "Idiomas planejados: pt_BR, en_US, es_ES", desatualizado frente aos 7 locales reais já
      existentes.

### 4. Documentar a persistência de dados na desinstalação
- [ ] Declarar no `readme.txt` (seção `== Privacy ==` do passo 2, ou "Installation") que as 4
      tabelas customizadas e as configurações dos gateways **permanecem** após desinstalar —
      nenhum texto sobre isso existe hoje no `readme.txt`. (Sem código novo — decisão já tomada.)

### 5. Mudar o default de `debug_log` para `no`
- [ ] Trocar `'default' => 'yes'` para `'no'` em
      `src/trunk/includes/abstract-class-wc-gateway-paycrypto-me.php:244` — **ainda `'yes'`**.
- [ ] Conferir se `OrderDisplayArgsTest.php` ou outros testes assumem o default antigo.
- [ ] Atualizar changelog do `readme.txt` se fizer sentido mencionar a mudança de comportamento.

### 6. Guia de captura de screenshots (execução manual do usuário)
- [ ] Preparar o guia (página/URL, estado prévio, orientação/dimensão) para as 6 imagens listadas
      no `readme.txt` — ainda não produzido.
- [ ] Resolver a discrepância confirmada: `readme.txt` lista **6** screenshots, mas
      `src/assets/` só tem **5** arquivos (`screenshot-1.jpg` a `screenshot-5.jpg`) —
      falta `screenshot-6.jpg` ou ajustar a lista no `readme.txt`.

### 7. Documentar envio de `src/assets/` ao SVN e limpar redundância de scripts
- [ ] Adicionar subseção em `docs/RELEASE.md` (próxima à "5. Submissão ao WordPress.org") sobre
      copiar `src/assets/*` para `svn-checkout/assets/` — hoje o fluxo SVN documentado
      (linhas 298–333) só cobre `svn-checkout/trunk/`, nada sobre a pasta `assets/`.
- [ ] Avaliar remover ou anotar `src/trunk/scripts/release.sh` (19 linhas, redundante, não
      referenciado por nenhum script `npm`/`composer`) — ainda existe sem anotação.

### 8. Fechamento deste plano
- [ ] Commitar todas as mudanças dos passos 2–7.
- [ ] Rodar `npm run build` + suíte PHPUnit completa mais uma vez após tudo acima.

### 9. 🔴 Crítico — Impedir seções de pagamento duplicadas ao trocar de gateway
> Ver detalhes completos (causa raiz com arquivo:linha) no
> [`pre-release-plan.md`](./pre-release-plan.md#9-crítico-impedir-seções-de-pagamento-duplicadas-ao-trocar-de-gateway-no-pay-for-order).
> Resumo: pedido pago via um gateway continua oferecendo o botão "Pay" do WooCommerce, que
> permite trocar para o outro gateway do plugin no mesmo pedido; nenhum dos dois gateways confere
> `get_payment_method() === $this->id` antes de renderizar sua seção, então o pedido pode acabar
> mostrando endereço on-chain E invoice Lightning ao mesmo tempo — risco de pagamento duplo, não
> só bug visual.

- [ ] Decidir escopo do fix: só corrigir a renderização (mínimo) ou também impedir a troca de
      gateway via `woocommerce_available_payment_gateways` (estrutural, previne o pagamento
      duplo em si).
- [ ] Corrigir o guard de `build_order_display_args()` nos dois gateways para exigir também
      `$order->get_payment_method() === $this->id`.
- [ ] (se decidido) Implementar filtro `woocommerce_available_payment_gateways` escondendo o
      gateway alternativo quando o pedido já tiver meta de pagamento de qualquer um dos dois.
- [ ] (se decidido) Avaliar limpeza do meta do gateway anterior em reprocessamento.
- [ ] Adicionar teste(s) cobrindo o cenário de dupla seção / troca de gateway.
- [ ] Rodar suíte PHPUnit completa após a correção.

---

## Verificação final (antes de seguir para `docs/RELEASE.md`)
- [x] Suíte PHPUnit 100% verde (218/218, confirmado nesta sessão).
- [ ] `npm run build` sem erros (não rodado nesta sessão).
- [ ] `License:` e `Donate link:` decididos e sem divergência entre `readme.txt` e o cabeçalho.
- [ ] `readme.txt` com `== Privacy ==` e contagem de screenshots batendo com os arquivos reais.
- [ ] `debug_log` com default `no`, sem quebrar testes existentes.
- [ ] 7 `.po`/`.mo` regenerados a partir de `.pot` atualizado, sem `msgstr ""` pendente, e
      `docs/TRANSLATION.md` corrigido.
- [ ] `docs/RELEASE.md` com a subseção nova de envio de `src/assets/` ao SVN.
- [ ] Working tree limpo, pronto para `scripts/release.sh --dry-run`.
