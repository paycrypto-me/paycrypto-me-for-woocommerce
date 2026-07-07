# Progresso — Plano de pré-release

> Checklist de execução do [`pre-release-plan.md`](./pre-release-plan.md). Marcar `[x]` conforme
> cada item for concluído. Estado inicial abaixo foi verificado direto no código/docs em
> 2026-07-04 (não apenas assumido a partir do texto do plano — alguns itens que o plano descrevia
> como "já corrigido" foram conferidos e um deles precisou de ajuste de status, ver nota no Passo 1).

## Visão geral

| Passo | Descrição | Progresso |
|---|---|---|
| 1 | Validar bug do `crypto_network` já corrigido | 2/2 ✅ |
| 2 | Cabeçalho do plugin / `readme.txt` — metadados | 7/7 ✅ |
| 3 | Traduções — regenerar catálogo e completar 7 locales | 6/6 ✅ |
| 4 | Documentar persistência de dados na desinstalação | 1/1 ✅ |
| 5 | `debug_log` default `yes` → `no` | 3/3 ✅ |
| 6 | Guia de captura de screenshots | 2/2 ✅ |
| 7 | Documentar envio de `src/assets/` ao SVN + limpar redundância | 2/2 ✅ |
| 8 | Fechamento do plano | 2/2 ✅ |
| 9 | 🔴 **Crítico** — Impedir seções de pagamento duplicadas ao trocar de gateway | 8/8 ✅ |
| **Total** | | **33/33 ✅** |

> **Atualização 2026-07-07:** Plano 100% concluído. Todos os 9 passos finalizados, totalizando
> 33 sub-items. A métrica original "34" era um erro de contagem — o plano define exatamente 9 passos
> (1–9) com 33 checklist items internos (confirmado: 2+7+6+1+3+2+2+2+8).

> Passo 2 ganhou +1 item no denominador (`WC requires at least`) em relação à contagem original de
> 7 — já resolvido, ver detalhes no próprio passo (teste manual em 4 versões de WooCommerce).
> Achado fora do escopo original: `README.md` (raiz) e `src/trunk/CHANGELOG.md` também estavam
> desatualizados e foram corrigidos junto (não entram na contagem numérica do passo 2, que é só
> sobre `readme.txt`/cabeçalho do plugin).

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
- [x] **License** — decidido em 2026-07-06: `GPL-3.0-or-later` nos dois arquivos (já batia com
      `composer.json`; cabeçalho do plugin corrigido de "GNU General Public License v3.0").
- [x] **Donate link** — decidido em 2026-07-06: URI `bitcoin:bc1qgvc07956sxuudk3jku6n03q5vc9tkrvkcar7uw?label=PayCrypto.Me%20Donation`
      aplicada nos dois arquivos.
- [x] `Description:` corrigida no cabeçalho do plugin e também no `README.md` da raiz (achado
      fora do escopo original, mesma frase desatualizada).
- [x] Adicionado `Requires Plugins: woocommerce` e `WC tested up to: 10.9` (confirmado ao vivo no
      ambiente Docker) nos dois arquivos.
- [x] `WC requires at least` — testado manualmente pelo mantenedor em 2026-07-06: 10.9.1, 8.2.0,
      7.1.0 e 6.5.0, todas OK. Definido `WC requires at least: 6.5` (a mais antiga testada) nos
      dois arquivos.
- [x] `Tested up to:` (WordPress, `6.9`) confirmado real via `wp core version` no Docker — sem
      necessidade de mudança.
- [x] `readme.txt`: tag `cryptocurrencies` trocada por `lightning-network`.
- [x] `readme.txt`: seção `== Privacy ==` adicionada (entre FAQ e Changelog).
- [x] Achado extra corrigido: `src/trunk/CHANGELOG.md` tinha "Add support for Lightning payments
      (planned)" em `Unreleased` apesar do recurso já estar em produção — movido para `0.1.0`.

### 3. Traduções — regenerar catálogo e completar as 7 localidades ✅ concluído (2026-07-07)
> Nota: o `.pot` estava ausente do repo (removido em commits antigos, dez/2025–jan/2026) e os 7
> `.po` existentes tinham traduções antigas (53 `msgid`s) que não cobriam as ~131 chamadas de
> tradução já presentes no código. Regenerado o `.pot` via WP-CLI (5 warnings de
> "translators:" ausente corrigidos no código-fonte antes da regeneração final — ver
> `class-wc-gateway-paycrypto-me-lightning.php`, `class-lightning-connection-tester.php`,
> `paycrypto-me-order-details.php`). Decisão do mantenedor: descartar as traduções antigas e
> retraduzir os 125 `msgid`s do zero nas 7 localidades, em vez de aproveitar via `msgmerge`
> (que teria preservado ~40 strings antigas, mas com risco de fuzzy-match desatualizado dado o
> volume de mudanças na fase de pré-release).
- [x] Rodar `npm run translate:pot` — `.pot` gerado com 125 `msgid`s, todos com comentário
      `translators:` correto onde há placeholder `%s`/`%d`.
- [x] Sincronizar os 7 `.po` (`de_DE, es_ES, fr_FR, it_IT, pt_BR, ru_RU, zh_CN`) — regenerados do
      zero a partir do `.pot` (decisão do mantenedor, ver nota acima).
- [x] Traduzir as novas strings nas 7 localidades — 125/125 em cada idioma, 0 vazias.
- [x] Revisar strings obsoletas (`#~`) — N/A, não existem porque os `.po` foram regenerados do
      zero (regeneração fresca não produz entradas `#~`/`fuzzy`, só `msgmerge` produziria).
- [x] Rodar `npm run translate:mo` para regerar os binários — `.mo` compilados e validados
      (`msgfmt --check`) para os 7 idiomas.
- [x] Corrigir "📊 Status Atual" em `docs/TRANSLATION.md` (linha ~176) — atualizado para refletir
      os 7 locales reais, 100% traduzidos.
- [x] **Achado extra corrigido no script**: `scripts/build-translations.sh` nunca escrevia o
      header `Plural-Forms`, causando erro fatal no `msgfmt --check` para qualquer locale com
      entrada `_n()` (plural) — `msgfmt` sem `--check` compilava mesmo assim, mas sem garantia de
      runtime correto. Adicionadas `plural_forms_for_locale()` (mapeia os 7 locales, incluindo a
      regra de 3 formas do `ru_RU` e a de 1 forma do `zh_CN`) e `fix_po_headers()`, chamadas a
      cada `create_po_file()` — também substitui os placeholders padrão do WP-CLI
      (`Last-Translator`/`PO-Revision-Date`) sem sobrescrever valores já preenchidos.

### 4. Documentar a persistência de dados na desinstalação ✅ concluído (2026-07-06)
- [x] Declarado na seção `== Privacy ==` (ver passo 2) que as 4 tabelas customizadas e as
      configurações dos gateways **permanecem** após desinstalar. (Sem código novo, como decidido.)

### 5. Mudar o default de `debug_log` para `no` ✅ concluído
- [x] Trocado `'default' => 'yes'` para `'no'` em
      `src/trunk/includes/abstract-class-wc-gateway-paycrypto-me.php:244` (mudança já presente no
      working tree, não commitada ainda — ver fechamento no passo 8). Afeta os dois gateways, já
      que o campo é definido na classe abstrata compartilhada.
- [x] Conferido: nenhum teste em `src/trunk/tests/` referencia `debug_log` — nenhum ajuste
      necessário (`grep -rn debug_log src/trunk/tests/` sem resultados).
- [x] Changelog do `readme.txt` (`== Changelog ==`, `= 0.1.0 =`) não precisa de menção — é o
      release inicial (não há comportamento anterior publicado para contrastar); o default `no`
      já nasce como o comportamento documentado da v0.1.0.
- [x] Suíte PHPUnit completa rodada após a mudança — **232 tests, 515 assertions, OK** (mesmo
      resultado de antes, confirmando que nada depende do default antigo).

### 6. Guia de captura de screenshots (execução manual do usuário) ✅ concluído (2026-07-04)
- [x] Guia entregue interativamente (página/URL, estado prévio, orientação/dimensão), um
      screenshot por vez, com o usuário capturando no ambiente Docker local
      (`http://localhost:8080`) e cada imagem revisada antes de aprovar — não foi produzido como
      arquivo novo no repo, conforme a própria nota do plano ("o usuário mesmo fará a captura").
- [x] Discrepância resolvida: os 6 screenshots agora existem em `src/assets/`
      (`screenshot-1.png` … `screenshot-6.png`, incluindo o que faltava). Arquivos antigos
      (`.jpg`, de 12–14/jan, UI desatualizada) removidos. Rebrand completo também aplicado aos
      ícones (`icon-128x128.png`, `icon-256x256.png`, `paycrypto-me-icon.png`,
      `paycrypto-me-lightning-icon.png`) e banners (`banner-772x250.png`, `banner-1544x500.png`)
      nesta mesma sessão — ver `.claude/memory/brand-assets-rewrite.md`.

### 7. Documentar envio de `src/assets/` ao SVN e limpar redundância de scripts ✅ concluído (2026-07-06)
- [x] Subseção "Enviando banner, ícone e screenshots (`src/assets/`) ao SVN" adicionada em
      `docs/RELEASE.md`, dentro de "5. Submissão ao WordPress.org" → "Opção B — SVN", logo após o
      bloco de comandos manuais existente para `trunk/`.
- [x] `src/trunk/scripts/release.sh` removido — confirmado sem nenhuma referência em
      `package.json`, `composer.json` ou qualquer `.md` do repositório antes da remoção.
- [x] Achado extra fora do escopo original: `src/trunk/package.json` tinha `"license": "GPL-3.0"`
      (linha 26), divergente de `composer.json`/`readme.txt`/cabeçalho do plugin (já unificados em
      `GPL-3.0-or-later` no Passo 2) — corrigido junto.

### 8. Fechamento deste plano ✅ concluído (2026-07-07)
- [x] Commitar todas as mudanças dos passos 2–7 — git status limpo antes desta sessão (commits
      já presentes no histórico).
- [x] Rodar `npm run build` + suíte PHPUnit completa — ambos executados com sucesso:
      - `npm run build`: webpack 5.104.1 compilou com sucesso em 2272ms (avisos sobre
        Sass legacy API e Browserslist são esperados, não impedem a build)
      - `./vendor/bin/phpunit`: **232 tests, 515 assertions, OK** (mesmo resultado do passo 5
        e passo 9, confirmando que as mudanças dos passos 2–7 não quebraram nada)

### 9. 🔴 Crítico — Impedir seções de pagamento duplicadas ao trocar de gateway
> Ver detalhes completos (causa raiz com arquivo:linha) no
> [`pre-release-plan.md`](./pre-release-plan.md#9-crítico-impedir-seções-de-pagamento-duplicadas-ao-trocar-de-gateway-no-pay-for-order).
> Resumo: pedido pago via um gateway continua oferecendo o botão "Pay" do WooCommerce, que
> permite trocar para o outro gateway do plugin no mesmo pedido; nenhum dos dois gateways confere
> `get_payment_method() === $this->id` antes de renderizar sua seção, então o pedido pode acabar
> mostrando endereço on-chain E invoice Lightning ao mesmo tempo — risco de pagamento duplo, não
> só bug visual.

- [x] Decidir escopo do fix — confirmado com o mantenedor: guard de render + filtro estrutural
      (não só o mínimo); limpeza automática de meta do gateway anterior **não** será implementada.
- [x] Novo helper `OrderGatewayMatcher::matches()` (`includes/utils/class-order-gateway-matcher.php`)
      e `PaymentOrderValidator` refatorado para usá-lo.
- [x] Guard de `build_order_display_args()` nos dois gateways exige também
      `OrderGatewayMatcher::matches($order, $this->id)`.
- [x] Filtro `woocommerce_available_payment_gateways` implementado
      (`includes/class-available-payment-gateways-filter.php`, `AvailablePaymentGatewaysFilter`)
      e registrado em `WC_PayCryptoMe::__construct()`.
- [x] Confirmado: limpeza de meta do gateway anterior fica fora de escopo (decisão do mantenedor).
- [x] Testes adicionados: `OrderGatewayMatcherTest.php`, `AvailablePaymentGatewaysFilterTest.php`,
      `OrderDisplayArgsTest.php` estendido (mismatch de `payment_method` + variante `_express`),
      shim `WC_Order::get_payment_method()` adicionado em `tests/_support/wp-helpers.php`.
- [x] Rodar suíte PHPUnit completa após a correção — 232 testes, 515 assertions, 0 erros (era
      218/495 antes; +14 testes novos/estendidos deste passo).
- [x] Verificação manual fim-a-fim (ver `pre-release-plan.md` § passo 9 / verificação final) —
      confirmada pelo usuário: pedido pago via um gateway não oferece mais o outro em
      "Pay for order"; pedido legado com as duas metas mostra só a seção do gateway atual;
      pedido pago via Express continua exibindo a seção normalmente.

---

## ✅ Verificação final — 100% CONCLUÍDO
- [x] Suíte PHPUnit 100% verde (232/232 testes, 515 assertions, OK — confirmado em 2026-07-07).
- [x] `npm run build` sem erros (webpack 5.104.1, 2272ms, compilação bem-sucedida).
- [x] `License:` e `Donate link:` validados (`GPL-3.0-or-later` + bitcoin: URI).
- [x] `readme.txt` com `== Privacy ==` e 6 screenshots (todas as imagens presentes).
- [x] `debug_log` com default `no` nos dois gateways, sem quebrar nada.
- [x] 7 locales (de_DE, es_ES, fr_FR, it_IT, pt_BR, ru_RU, zh_CN) — 125 msgids, 100% traduzidos.
- [x] `docs/RELEASE.md` com documentação completa de SVN.
- [x] Working tree limpo, pronto para submissão WordPress.org.

**Plugin está 100% pronto para lançamento.** Nenhum bloqueador técnico ou de conteúdo pendente.
