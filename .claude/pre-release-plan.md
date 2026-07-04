# Plano de resolução de pendências pré-release — PayCrypto.Me for WooCommerce

> Arquivo de plano temporário — não referenciado no `CLAUDE.md`. Mantido no repositório apenas nessa fasé de pré-release
> para que outros agentes/sessões tenham acesso ao contexto e à lista de passos abaixo.
>
> **Acompanhamento de progresso:** ver [`pre-release-progress.md`](./pre-release-progress.md) —
> checklist marcável item a item, com o estado real de cada passo verificado no código/docs.

## Escopo deste plano vs. `docs/RELEASE.md`

Este plano resolve **inconsistências de conteúdo e comportamento** encontradas no plugin antes
do primeiro release público (metadados divergentes, traduções desatualizadas, defaults de
configuração, documentação de dados). Ele é um **pré-requisito** para o processo de release em
si, que já está documentado separadamente em [`docs/RELEASE.md`](./RELEASE.md) (script
`scripts/release.sh`, bump de versão, build, zip, submissão via SVN/upload manual ao WP.org).

**Ordem de execução para quem for rodar isto do zero:**
1. Executar todos os passos deste arquivo (`docs/pre-release-plan.md`).
2. Só depois seguir o "Checklist de Release" no final de `docs/RELEASE.md` para efetivamente
   cortar a versão `0.1.0`.

## Referências (para quem for executar este plano sem contexto prévio)

- [`CLAUDE.md`](../CLAUDE.md) (raiz do repositório) — visão geral do plugin, hierarquia de
  classes, fluxo de pagamento On-Chain/Lightning, tabelas de banco, hooks públicos e a seção
  "Premium add-on: scope boundaries and extension points" (relevante para os passos 2 e 4 deste
  plano, que mencionam o add-on premium e os dados que ficam de fora do plugin gratuito).
- [`docs/RELEASE.md`](./RELEASE.md) — processo completo de build/versionamento/zip/SVN. Contém
  a "Identidade Canônica do Projeto" (slug `paycrypto-me-for-woocommerce`, arquivo principal
  `src/trunk/paycrypto-me-for-woocommerce.php`, serviço Docker `wordpress`) e a lista exata dos
  4 arquivos que o script de release atualiza no bump de versão — nenhum dos itens deste plano
  (License, Donate link, Tags, traduções, `debug_log`) é tocado automaticamente pelo script, por
  isso precisam ser resolvidos manualmente antes.
- [`docs/TRANSLATION.md`](./TRANSLATION.md) — comandos exatos de tradução (`npm run
  translate:pot`, `npm run translate`, `npm run translate:po <locale>`, `npm run translate:mo
  <locale>`) usados no passo 3 abaixo. Nota: a seção "📊 Status Atual" desse arquivo está ela
  mesma desatualizada (lista só `pt_BR, en_US, es_ES` como "idiomas planejados", mas o projeto
  já tem 7 locales reais em `src/trunk/languages/` e nenhum `en_US`, que é o idioma-fonte e não
  precisa de arquivo) — vale corrigir isso também no passo 3.
- [`.claude/memory/dev-workflow.md`](../.claude/memory/dev-workflow.md) — cheatsheet rápido dos
  mesmos comandos de build/teste/tradução/release, útil como referência secundária mais curta.
- `artifacts/` (raiz do repositório) — contém a arte fonte em alta resolução do banner
  (`paycrypto-me-banner-1544x500`) e do favicon (`PayCrypto.Me-favicon.svg`), caso seja preciso
  regenerar os banners/ícone de `src/assets/` mencionados no passo 6.

## Contexto

Durante uma sessão de trabalho, ao investigar um bug visual no badge "Crypto Network" (causado
por `abstract-class-lightning-processor.php` concatenando `"lightning:{node_type}"` dentro da
mesma chave de meta (`crypto_network`) que o gateway On-Chain usa para seu próprio badge — já
corrigido), levantamos uma lista mais ampla de inconsistências e pendências no plugin que
precisam ser resolvidas antes do primeiro release público (`0.1.0`) na WordPress.org.

Este plano consolida todos os pontos levantados (readme.txt, cabeçalho do plugin, traduções,
screenshots, ausência de rotina de desinstalação, defaults de log, scripts de release) em uma
sequência executável. Decisões que exigiam julgamento de produto já foram tomadas com o
usuário:

- **Desinstalação**: apenas documentar no readme.txt que os dados persistem — sem código novo.
- **`debug_log`**: mudar o default de `yes` para `no` nos dois gateways.
- **Screenshots**: fornecer um guia de captura (páginas, dimensões, orientação); o usuário
  captura manualmente (as 5 imagens atuais em `src/assets/` são de 14/jan e a UI evoluiu desde
  então — Fase 3 do refactor, novos botões de teste de conexão, etc.).
- **License / Donate link**: **decisão ainda em aberto** — não há certeza de qual dos dois
  valores divergentes (readme.txt vs. cabeçalho do plugin) é o correto/pretendido. Ver passo 2
  para a discussão e as opções.

## Achados que sustentam o plano

- `src/trunk/readme.txt` já foi reescrito para refletir as features reais (On-Chain +
  Lightning, Blocks + checkout clássico, HPOS, Express Payment, QR code, etc.) — esse trabalho
  não precisa ser refeito, só ajustado nos pontos abaixo.
- Bug do `crypto_network` já corrigido em `abstract-class-lightning-processor.php`,
  `class-payment-processor.php` e no teste `AbstractLightningProcessorTest.php` — falta apenas
  rodar a suíte para confirmar (indisponível no ambiente onde este plano foi escrito, por falta
  de PHP local).
- As 7 localidades em `src/trunk/languages/` (de_DE, es_ES, fr_FR, it_IT, pt_BR, ru_RU, zh_CN)
  têm 52/52 strings traduzidas **do catálogo atual** — mas o catálogo em si está desatualizado:
  o código tem hoje ~118 strings únicas com o text domain `paycrypto-me-for-woocommerce`
  (`__`, `_e`, `esc_html__`, `esc_html_e`, `esc_attr__`, `esc_attr_e`), ou seja, mais de 60
  strings novas (prováveis fontes: `templates/checkout/`, `templates/order-details/`, e
  includes adicionados nas últimas fases do refactor) nunca foram extraídas para o `.pot`/`.po`.
  Não existe `.pot` versionado no repo — é gerado sob demanda via `npm run translate:pot`.
- Screenshots e banners **já existem** em `src/assets/` (sibling de `src/trunk/`, layout estilo
  SVN do WP.org): `screenshot-1.jpg` a `screenshot-5.jpg`, `banner-772x250.png`,
  `banner-1544x500.png`, `icon.svg`. Datados de 12–14/jan — anteriores a boa parte do refactor
  recente. `docs/RELEASE.md` já documenta o envio desses arquivos ao WP.org como manual (não é
  responsabilidade do `scripts/release.sh`, que só cuida de `trunk/`) — o que falta é o passo a
  passo desse envio manual, que hoje não está escrito em lugar nenhum (ver passo 7).
- Não existe `uninstall.php` nem `register_uninstall_hook` — as 4 tabelas customizadas
  (`paycrypto_me_bitcoin_wallet_xpubkeys`, `paycrypto_me_bitcoin_derivation_indexes`,
  `paycrypto_me_bitcoin_transactions_data`, `paycrypto_me_lightning_invoices`) e as duas linhas
  de opção do WooCommerce (`woocommerce_paycrypto_me_settings`,
  `woocommerce_paycrypto_me_lightning_settings`) persistem para sempre após desinstalar —
  decisão tomada: só documentar.
- Inconsistências de cabeçalho confirmadas: `License` (`GPL-3.0-or-later` no readme vs
  `GNU General Public License v3.0` no cabeçalho do plugin) e `Donate link`
  (`https://paycrypto.me/` no readme vs `https://gravatar.com/lucasrosa95` no cabeçalho do
  plugin). Também faltam os headers `Requires Plugins: woocommerce` e `WC requires at least` /
  `WC tested up to` (convenção comum em extensões WooCommerce, ausente hoje).
- `Tags:` do readme (`woocommerce, payments, crypto, bitcoin, cryptocurrencies`) inclui
  `cryptocurrencies`, que hoje é impreciso (só Bitcoin é suportado).
- Existe um segundo `scripts/release.sh` (dentro de `src/trunk/scripts/`, 19 linhas) que faz
  apenas `composer install --no-dev` + `npm run build` via Docker — sobreposto e mais simples
  que o `scripts/release.sh` da raiz (395 linhas, full-featured). Não é referenciado por nenhum
  script `npm`/`composer` — parece resquício de uma versão anterior do fluxo de release.
- Não existe `SECURITY.md`, `PRIVACY.md` nem seção de privacidade no `readme.txt`, apesar do
  plugin armazenar xPub, endereços derivados e (no caso Lightning) macaroons/URLs de nó —
  dados sensíveis do ponto de vista operacional da loja.
- **[Crítico, achado em teste manual em 2026-07-04]** Nenhum dos dois gateways confere se ainda
  é o gateway atualmente selecionado no pedido (`$order->get_payment_method() === $this->id`)
  antes de renderizar sua seção de pagamento — o guard em `build_order_display_args()` verifica
  só a presença do próprio meta. Combinado com o fato de o botão nativo "Pay" do WooCommerce
  permitir reabrir o checkout de um pedido `pending payment` e escolher QUALQUER gateway
  disponível (não há filtro `woocommerce_available_payment_gateways` nosso), e de
  `PaymentProcessor::update_order_after_payment()` só adicionar meta do gateway atual sem nunca
  limpar o do outro, um mesmo pedido pode acumular metadados dos dois gateways
  (`_paycrypto_me_payment_address` e `_paycrypto_me_payment_request`) e exibir as duas seções de
  pagamento (endereço on-chain E invoice Lightning) simultaneamente — não é só um bug de UI, é
  risco de pagamento duplo por dois trilhos diferentes no mesmo pedido. Ver passo 9.

## Passo a passo

### 1. Corrigir e validar o bug já resolvido
- Rodar a suíte PHPUnit completa (via Docker, como o projeto já faz —
  `docker compose exec wordpress ./vendor/bin/phpunit` ou equivalente) para confirmar que a
  correção em `abstract-class-lightning-processor.php` / `class-payment-processor.php` /
  `AbstractLightningProcessorTest.php` não quebrou nada e que o teste renomeado
  (`test_process_exposes_node_type_under_its_own_key`) passa.
- Commitar essa correção junto com as demais mudanças pendentes do working tree (ver passo 8).

### 2. Cabeçalho do plugin e `readme.txt` — consistência de metadados
Arquivo principal: `src/trunk/paycrypto-me-for-woocommerce.php` (cabeçalho, linhas 1–18).

- **`License:` e `Donate link:` — decidir com o mantenedor antes de editar qualquer arquivo.**
  Não há confirmação de qual versão de cada campo é a pretendida; não assumir nenhum dos dois
  lados como "correto" por já estar em um dos dois arquivos. Levar para discussão:
  - `License:` — hoje é `GPL-3.0-or-later` no `readme.txt` vs. `GNU General Public License v3.0`
    (sem "or later") no cabeçalho do plugin. São licenças diferentes na prática (a segunda não
    permite migrar para uma versão futura da GPL); confirmar qual reflete a intenção real de
    licenciamento antes de padronizar os dois arquivos.
  - `Donate link:` — hoje é `https://paycrypto.me/` no `readme.txt` vs.
    `https://gravatar.com/lucasrosa95` no cabeçalho do plugin. Confirmar qual URL é a que
    deveria receber doações (site institucional do produto vs. perfil pessoal do mantenedor)
    antes de padronizar.
  - Só depois de decidido, aplicar o mesmo valor nos dois arquivos (`readme.txt` e o cabeçalho
    de `paycrypto-me-for-woocommerce.php`) — o ponto crítico é que os dois batam entre si, não
    qual dos dois valores atuais "vence".
- `Description:` do cabeçalho ainda diz "many cryptocurrencies" — corrigir para refletir que
  hoje é só Bitcoin (On-Chain + Lightning), no mesmo espírito da correção já feita no
  `readme.txt`.
- Adicionar (aditivo, não quebra nada existente): `Requires Plugins: woocommerce`,
  `WC requires at least: <versão mínima testada>`, `WC tested up to: <versão WC testada>` —
  confirmar essas duas versões com o ambiente de testes/Docker do projeto antes de preencher
  (não assumir um número).
- Revisar `Tested up to:` (hoje `6.9`) contra a versão do WordPress realmente testada no
  ambiente de dev atual antes do release.
- `readme.txt`: trocar a tag `cryptocurrencies` por algo mais preciso (ex.: `lightning-network`
  ou `btcpay-server`), mantendo o limite usual de 5 tags do WP.org.
- `readme.txt`: adicionar uma seção curta `== Privacy ==` (ou um parágrafo dentro de
  "Installation" / FAQ, seguindo o padrão já usado) explicando: (a) quais dados sensíveis o
  plugin armazena (xPub, endereços derivados, credenciais de nó Lightning) e onde
  (`wp_options` da loja + tabelas customizadas), e (b) que esses dados **não são removidos**
  na desinstalação do plugin (documentando a decisão tomada no passo 4).

### 3. Traduções — regenerar catálogo e completar as 7 localidades
Diretório: `src/trunk/languages/`. Scripts existentes: `npm run translate:pot`,
`npm run translate`, `scripts/build-translations.sh`.

1. Rodar `npm run translate:pot` (ou o script equivalente) a partir de `src/trunk/` para gerar
   um `.pot` atualizado a partir do código atual — isso vai capturar as ~60+ strings que hoje
   não estão no catálogo (prováveis fontes: `templates/checkout/`, `templates/order-details/`,
   settings do gateway Lightning adicionados nas últimas fases do refactor).
2. Sincronizar (`msgmerge` ou equivalente, via `npm run translate`) os 7 arquivos `.po`
   (`de_DE`, `es_ES`, `fr_FR`, `it_IT`, `pt_BR`, `ru_RU`, `zh_CN`) contra o novo `.pot` — isso
   vai marcar as novas strings como não traduzidas (`msgstr ""`) sem perder as 52 já existentes.
3. Traduzir as novas strings em cada uma das 7 localidades (tradução assistida, com atenção a:
   termos técnicos que não devem ser traduzidos literalmente — "xPub", "Lightning Network",
   "BTCPay Server", "lnd", "mainnet"/"testnet" — e consistência com os termos já usados nas
   strings existentes de cada idioma). Sinalizar ao final que uma revisão por falante nativo é
   recomendada antes do release, especialmente para pt_BR (idioma principal do mantenedor, mas
   ainda assim vale conferência) e para os demais 6 idiomas.
4. Revisar as 52 strings já traduzidas quanto a strings **obsoletas** — texto de UI que mudou
   de redação ou foi removido no refactor recente, mas cujo msgid antigo ainda está no catálogo
   (`msgmerge` normalmente já move essas para comentários `#~`, mas vale checar).
5. Rodar `npm run translate:mo` para regerar os `.mo` binários a partir dos `.po` atualizados.
6. Corrigir a seção "📊 Status Atual" de `docs/TRANSLATION.md` (linha ~181), que hoje lista
   "Idiomas planejados: pt_BR, en_US, es_ES" — desatualizado frente aos 7 locales reais
   (`de_DE, es_ES, fr_FR, it_IT, pt_BR, ru_RU, zh_CN`) já existentes em `src/trunk/languages/`
   (e `en_US` não se aplica, por ser o idioma-fonte do próprio código).

### 4. Documentar a persistência de dados na desinstalação (sem código novo)
- No `readme.txt`, dentro da nova seção `== Privacy ==` (ver passo 2) ou em "Installation",
  declarar explicitamente: ao desinstalar o plugin, as 4 tabelas customizadas
  (`{prefix}paycrypto_me_bitcoin_wallet_xpubkeys`, `{prefix}paycrypto_me_bitcoin_derivation_indexes`,
  `{prefix}paycrypto_me_bitcoin_transactions_data`, `{prefix}paycrypto_me_lightning_invoices`) e
  as configurações salvas dos dois gateways **permanecem no banco de dados** e precisam ser
  removidas manualmente pelo administrador, se desejado.
- Nenhuma mudança de código nesta etapa — decisão consciente de não implementar
  `uninstall.php`/`register_uninstall_hook` agora.

### 5. Mudar o default de `debug_log` para `no`
Arquivo: `src/trunk/includes/abstract-class-wc-gateway-paycrypto-me.php` (campo `debug_log`
dentro de `init_form_fields()`, hoje `'default' => 'yes'`).

- Trocar o default do campo de `yes` para `no` — afeta ambos os gateways, já que o campo é
  definido na classe abstrata compartilhada.
- Conferir se algum teste (`OrderDisplayArgsTest.php` ou outros) assume o default antigo e
  ajustar se necessário.
- Atualizar a descrição do campo/changelog do `readme.txt` se fizer sentido mencionar essa
  mudança de comportamento (é um comportamento novo em relação ao que estava documentado).

### 6. Guia de captura de screenshots (para o usuário executar manualmente)
Preparar um guia separado e objetivo com, para cada uma das 6 imagens referenciadas hoje no
`readme.txt` (`== Screenshots ==`):
- Página/URL exata a abrir (ex.: checkout com os dois gateways habilitados, tela de "Thank you"
  de um pedido Lightning, tela de "Thank you" de um pedido On-Chain, edição de pedido no admin,
  `WooCommerce → Settings → Payments`, tela de configurações do gateway Lightning com
  `node_type` e botão de teste de conexão).
- Estado necessário antes de capturar (ex.: pedido já processado para existir QR code; gateway
  Lightning com `node_type = lnd_rest` selecionado para aparecer o badge roxo correto).
- Orientação (paisagem, largura de viewport recomendada) e proporção/dimensão recomendada pela
  WordPress.org (screenshots não têm dimensão fixa obrigatória, mas a convenção é ~1280×720,
  formato `.png` ou `.jpg`, mostrando a UI em zoom legível).
- Confirmar também se `screenshot-6.jpg` precisa ser criado (o `readme.txt` reescrito referencia
  6 capturas, mas só existem 5 arquivos em `src/assets/`) — ajustar o guia e, se necessário, o
  número de screenshots no `readme.txt` para bater com o que for efetivamente produzido.

Esse guia será entregue como lista objetiva (não necessariamente como arquivo novo no repo) —
o usuário mesmo fará a captura.

### 7. Documentar o envio de `src/assets/` ao SVN e limpar redundância de scripts
Arquivos: `docs/RELEASE.md` e `scripts/release.sh` (raiz).

- `docs/RELEASE.md` já trata "Atualizar screenshots no WP.org" como responsabilidade manual
  (tabela "O Que o Script NÃO Faz") — isso está correto e **não é um bug a corrigir**: banner,
  ícone e screenshots (pasta `assets/` do SVN) são atualizados independentemente do ciclo de
  release de código, então não precisam entrar no `scripts/release.sh`. O que falta é o
  **passo a passo** desse envio, que hoje não existe em lugar nenhum: adicionar uma subseção em
  `docs/RELEASE.md` (próxima à seção "Submissão ao WordPress.org") explicando como copiar
  `src/assets/*` para `assets/` dentro do checkout SVN (`svn-checkout/assets/`, ao lado do
  `svn-checkout/trunk/` que o script já popula), com `svn add --force` + `svn commit` — o mesmo
  padrão manual já documentado para `trunk/`, só que apontando para a pasta `src/assets/`.
- Automatizar isso dentro de `scripts/release.sh --svn` é opcional (nice-to-have), não
  obrigatório para o release — priorizar a documentação acima primeiro.
- Avaliar remover `src/trunk/scripts/release.sh` (19 linhas, redundante e não referenciado por
  nenhum script `npm`/`composer`) ou deixar claro em comentário que é um helper local diferente
  do `scripts/release.sh` da raiz — para não confundir quem for rodar o release.

### 8. Fechamento deste plano
- Revisar e commitar todas as mudanças pendentes no working tree (`.claude/memory/*`,
  `CLAUDE.md`, `docs/*` removidos, `wp-helpers.php`, a correção do bug do `crypto_network`, o
  `readme.txt` reescrito, este plano, e todas as mudanças dos passos 2–7 acima).
- Rodar `npm run build` (assets JS/CSS) e a suíte PHPUnit completa mais uma vez após todas as
  mudanças acima, para garantir que nada quebrou.
- **A partir daqui, este plano está concluído.** O corte da versão `0.1.0` em si (bump de
  versão, build de produção, zip, tag git, submissão ao WP.org) segue o processo já documentado
  em [`docs/RELEASE.md`](./RELEASE.md) — usar o "Checklist de Release" na seção final daquele
  arquivo, começando pelo `--dry-run`:
  ```bash
  ./scripts/release.sh -v 0.1.0 -s paycrypto-me-for-woocommerce --dry-run
  ```

### 9. [Crítico] Impedir seções de pagamento duplicadas ao trocar de gateway no "Pay for order"
> Achado via teste manual em 2026-07-04, depois do plano original ter sido escrito — não é uma
> das inconsistências de conteúdo/metadados dos passos 1-8, é um bug funcional com risco de
> pagamento duplo. Recomenda-se priorizar este passo antes ou junto do passo 1, não depois dele.

**Cenário observado:** pedido criado com um gateway do plugin (ex.: Lightning) fica
`pending payment`, como esperado — o cliente ainda precisa pagar fora do fluxo do checkout, via
o link/QR gerado. O problema é que a página de detalhes do pedido do WooCommerce (admin e "Minha
Conta") mostra o botão nativo "Pay", que leva o cliente de volta ao checkout **podendo escolher
um gateway diferente** (ex.: trocar de Lightning para On-Chain). Se ele completa esse segundo
fluxo, `process_payment()` roda de novo **sobre o mesmo pedido**, e o pedido passa a exibir as
**duas** seções de pagamento (endereço Bitcoin on-chain E invoice Lightning) ao mesmo tempo —
inconsistência que não deveria existir.

**Causa raiz confirmada (investigação de código):**
- `Abstract_WC_Gateway_PayCryptoMe::__construct()`
  (`abstract-class-wc-gateway-paycrypto-me.php:49-50`) registra
  `render_admin_order_details_section()` / `render_checkout_order_details_section()` nos hooks
  genéricos `woocommerce_admin_order_data_after_order_details` /
  `woocommerce_order_details_before_order_table` — disparam para qualquer pedido, e cada
  instância de gateway decide sozinha se renderiza.
- O guard de `build_order_display_args()` em cada gateway confere só a presença do próprio meta —
  `_paycrypto_me_payment_address` no On-Chain (`class-wc-gateway-paycrypto-me.php:212-216`) e
  `_paycrypto_me_payment_request` no Lightning
  (`class-wc-gateway-paycrypto-me-lightning.php:333-337`) — nenhum dos dois confere
  `$order->get_payment_method() === $this->id`.
- `PaymentProcessor::update_order_after_payment()` (`processors/class-payment-processor.php:111-122`)
  só adiciona (`add_meta_data`) o meta do gateway que está processando agora; nunca lê nem limpa
  meta do outro gateway.
- `PaymentOrderValidator::validate_order()` só confere consistência no momento do processamento
  atual (gateway processando == `payment_method` do pedido nesse instante) — não detecta que um
  gateway *diferente* já rodou antes nesse mesmo pedido.
- Não existe filtro `woocommerce_available_payment_gateways` nem guard próprio na página
  `order-pay` — o WooCommerce oferece normalmente os dois gateways do plugin ao reabrir o
  pagamento de um pedido pendente.

**Ações propostas (decidir escopo antes de implementar):**
1. **Fix mínimo (obrigatório):** em `build_order_display_args()` de cada gateway, além do guard
   de meta, exigir também `$order->get_payment_method() === $this->id` — garante que só a seção
   do gateway *atualmente selecionado* no pedido apareça, mesmo que sobre meta órfão do outro
   gateway de uma tentativa anterior.
2. **Fix estrutural (avaliar, previne o pagamento duplo em si, não só a duplicidade visual):**
   usar `woocommerce_available_payment_gateways` para esconder o(s) gateway(s) PayCryptoMe do
   checkout de "Pay for order" quando o pedido já tiver meta de pagamento de qualquer um dos dois
   gateways do plugin — impede a troca de trilho de pagamento no meio do fluxo.
3. **Avaliar (opcional):** limpar o meta do gateway anterior quando um reprocessamento genuíno
   do mesmo gateway acontece (não necessariamente ao trocar de gateway) — decidir se está dentro
   do escopo deste plano ou se é cenário raro demais para justificar código extra agora.
4. Adicionar teste(s) cobrindo: pedido com meta dos dois gateways não deve renderizar as duas
   seções (cobre a decisão 1); e, se a decisão 2 for adotada, pedido com meta de um gateway não
   deve mais oferecer o outro no "Pay for order".
5. Rodar a suíte PHPUnit completa após a correção.

## Verificação final (escopo deste plano — antes de seguir para `docs/RELEASE.md`)
- Suíte PHPUnit 100% verde (rodar via Docker), confirmando inclusive a correção do
  `crypto_network` do passo 1.
- Pedido com meta de pagamento de um gateway PayCryptoMe não exibe mais a seção do outro gateway
  (passo 9) — testado manualmente reabrindo "Pay for order" e completando com o gateway
  alternativo.
- `npm run build` sem erros.
- `License:` e `Donate link:` discutidos e decididos com o mantenedor (ver passo 2) — e, após a
  decisão, `readme.txt` e o cabeçalho do plugin (`paycrypto-me-for-woocommerce.php`) sem
  nenhuma divergência entre si nesses dois campos nem em `Description:`.
- `readme.txt` com `== Privacy ==` (ou seção equivalente) documentando a persistência de dados
  na desinstalação, e com o número de screenshots listadas batendo com os arquivos reais em
  `src/assets/`.
- `debug_log` com default `no` em `abstract-class-wc-gateway-paycrypto-me.php`, sem quebrar os
  testes existentes que dependem desse campo.
- Os 7 `.po`/`.mo` regenerados a partir de um `.pot` atualizado, sem `msgstr ""` nas strings
  novas identificadas no passo 3, e `docs/TRANSLATION.md` com a seção "Status Atual" corrigida.
- `docs/RELEASE.md` com a subseção nova de envio de `src/assets/` ao SVN.
- Working tree limpo (`git status`) após o commit do passo 8, pronto para o
  `scripts/release.sh --dry-run` descrito em `docs/RELEASE.md`.
