# [VALIDATION] RFC — Contrato público de dados de apresentação do pagamento

**Status:** implementada no branch `feat/public-payment-presentation-data`; aguardando merge para
arquivamento como `[DONE]`.

**Commit da proposta:** `043187e`

**Origem:** revisão independente P7f do PayCrypto.Me Pro

**Data:** 2026-09-07

**Execução do Base:** 2026-09-12 — `docker compose run --rm release ./vendor/bin/phpunit
--configuration phpunit.xml.dist` (424 testes, 1002 assertions, 4 skipped),
`./scripts/check-docs-drift.sh`, `./scripts/check-i18n-conventions.sh` e
`./scripts/smoke-minimal-host.sh` passaram. Schema tests não foram executados porque esta entrega
não toca schema.

## Resumo

Separar a obtenção dos dados normalizados de apresentação do pagamento da renderização do
template web. Add-ons precisam reutilizar URI, identificador, valor, validade e QR em canais como
e-mail sem capturar HTML por output buffer, enfileirar JavaScript ou depender do template de página
do pedido.

Esta RFC não transfere ao Base templates, notificações ou regras de produto do Pro. O Base continua
dono do dado canônico que já calcula; cada consumidor continua dono da apresentação adequada ao seu
canal.

## Problema observado

O Base expõe hoje:

- `build_order_display_args(WC_Order $order)`, que retorna apenas os argumentos específicos do
  gateway;
- `paycryptome_order_display_args`, antes do builder;
- `paycryptome_order_display_data`, depois do builder;
- `render_checkout_order_details_section()`, que calcula os dados, enfileira JavaScript e imprime
  o template web.

O array final com `payment_uri`, `payment_identifier`, `payment_qr_code`, valor, rede e validade só
fica acessível durante `render_checkout_order_details_section()`. O
`PaymentDisplayDataBuilder` mantido pela instância do gateway é protegido e não existe operação
pública que obtenha os dados finais sem renderização.

Na prova P7f, um consumidor de e-mail precisou abrir um output buffer e chamar o renderer de página
dentro de `woocommerce_email_order_details`. Esse caminho mistura três responsabilidades:

1. resolver e normalizar os dados canônicos;
2. gerar o QR;
3. imprimir HTML de web/admin e enfileirar um script de copiar endereço.

O acoplamento dificulta testar o corpo final do e-mail, leva para o canal de e-mail markup interativo
e classes dependentes do CSS frontend e pode disparar efeitos de assets em contexto sem página.
Duplicar no Pro a montagem do array ou a geração do QR evitaria esses efeitos, mas criaria duas
fontes para URI/QR e contrariaria o objetivo de integração entre os plugins.

## Objetivos

- Expor uma operação pública e sem output que retorne o mesmo array final usado pelo renderer.
- Manter filtros, validação, logging e geração de QR em uma única implementação do Base.
- Permitir que web, admin, e-mail e futuros canais escolham seu próprio template sem recalcular URI
  ou payload do QR.
- Evitar enqueue de CSS/JavaScript quando o consumidor solicita apenas dados.
- Definir lifecycle e compatibilidade do array destinado a extensões.

## Não objetivos

- Implementar e-mail do cliente, e-mail administrativo, webhook ou notificação no Base.
- Tornar o template web atual compatível com todos os clientes de e-mail.
- Garantir que `data:` URI seja exibida por todo cliente de e-mail.
- Escolher se o Pro usará `data:` URI, CID attachment ou imagem hospedada no corpo final.
- Mover M9/M10 ou suas regras de gating para o Base.

## Decisão aprovada

### 1. Extração pública sem renderização

Adicionar ao contrato dos gateways esta operação:

```php
final public function get_order_display_data(\WC_Order $order): ?array;
```

A operação pertence a `Abstract_WC_Gateway_PayCryptoMe`; gateways concretos continuam responsáveis
somente por `build_order_display_args()`. Ela deve:

1. chamar `build_order_display_args()`;
2. retornar `null` quando o gateway não corresponde ao pagamento do pedido;
3. aplicar `paycryptome_order_display_args` com três argumentos;
4. chamar o mesmo `PaymentDisplayDataBuilder` e logger usados hoje;
5. aplicar `paycryptome_order_display_data` com três argumentos;
6. retornar o array final sem imprimir HTML, carregar template ou enfileirar assets.

`null` significa exclusivamente que o pedido não pertence àquela instância de gateway ou não tem o
identificador mínimo do pagamento. Falhas de programação, de filtro ou de dependência não podem ser
convertidas em `null`: a operação pública deixa o `Throwable` propagar para o consumidor decidir sua
política. O renderer conserva seu `try/catch (\Throwable)`, logging e mensagem segura atuais.

### 2. Renderer como consumidor da operação

`render_checkout_order_details_section()` passa a chamar `get_order_display_data()`. Somente depois
de receber dados não nulos ele enfileira os assets do contexto web/admin e carrega o template atual.
Assim, renderer e add-ons não divergem na normalização.

### 3. Contrato de dados e extensibilidade

Documentar como estáveis para consumo os campos já observados pelo renderer:

- `payment_identifier` e `payment_uri`;
- `payment_qr_code` como `data:` URI ou string vazia na degradação suportada;
- valores fiat/cripto e moedas;
- labels e rede;
- validade formatada/expirada;
- confirmações requeridas.

Adicionar `expires_at_timestamp` (`?int`) ao builder e ao array final. Esse é o instante Unix
absoluto que o builder já resolve internamente: primeiro `_paycrypto_me_payment_expires_ts`, depois o
fallback legado de horas ancorado na criação do pedido, e `null` quando `show_expiry` é falso ou não
há validade calculável. O campo existente `expires_at` permanece por compatibilidade, mas deve ser
documentado como a duração legada em horas recebida de
`_paycrypto_me_payment_expires_at` — ele não é timestamp e não deve alimentar countdown.

Campos adicionais podem ser acrescentados. Remoção ou mudança de unidade exige deprecação ou
versão incompatível. Os dois filtros existentes permanecem a extensão oficial e devem executar
uma vez por chamada da nova operação.

O contrato estável é a forma produzida pelo Base antes do filtro pós-build. Filtros podem acrescentar
campos, mas seus callbacks devem devolver `array`, preservar as chaves obrigatórias e manter seus
tipos e significados. Se um filtro mudar `payment_uri` depois do builder, também deve produzir um QR
para essa mesma URI; um consumidor nunca deve receber URI e QR divergentes.

### 4. Uso por e-mail

O Pro pode solicitar os dados finais e construir markup próprio, compatível com HTML ou plain text,
no hook nativo do WooCommerce. Se precisar transformar a representação do QR para CID ou outro
transporte, deve preservar exatamente o payload de `payment_uri`; não deve recalcular o pagamento.

O Base não promete que seu template web seja adequado a e-mail e não precisa registrar hooks de
e-mail.

### 5. Detecção e compatibilidade

O Pro deve detectar a nova API com `method_exists()` enquanto coexistir com uma versão Base anterior.
M9, quando se tornar funcionalidade de produto, deve elevar `BASE_MIN_VERSION` para a primeira
versão publicada do Base que contenha a API. Não haverá adapter de produção por output buffer,
reimplementação do builder, acesso à propriedade protegida ou reflection. O harness sintético P7f
pode manter o caminho antigo somente como evidência histórica/teste da limitação anterior.

## Alternativas consideradas

### Chamar o renderer atual com output buffer

Reutiliza o HTML sem duplicar cálculo, mas enfileira JavaScript, acopla e-mail ao template web e
torna difícil tratar plain text e compatibilidade de clientes. É aceitável como harness transitório,
não como contrato ideal para M9.

### Instanciar `PaymentDisplayDataBuilder` no Pro

Evita output, mas duplica a composição do serviço, o logger e parte da resolução por gateway. O
consumidor pode divergir do renderer do Base.

### Usar apenas metadados do pedido

Evita dependência de classes, mas replica labels, validade, degradação do QR e regras de gateway.
Também transforma detalhes internos de storage em API de apresentação.

### Adicionar diretamente um template de e-mail ao Base

Elimina trabalho no Pro, mas move uma feature gated para o plugin gratuito e reduz a liberdade do
consumidor de escolher canais e copy. Não é recomendado.

## Nota do revisor para o executor — 2026-09-12

**Parecer: VIÁVEL E RECOMENDADA.** A sugestão resolve uma necessidade comprovada sem deslocar regra
de produto entre os plugins. A implementação é uma extração dentro da classe abstrata: os dois
gateways já entregam os mesmos argumentos ao mesmo builder, e o renderer já possui a fronteira exata
entre cálculo, enqueue e template. Não há mudança de banco, processamento do pagamento, estado do
pedido, comunicação com node ou contrato de status.

A aprovação está condicionada às regras abaixo. Elas resolvem as ambiguidades encontradas na
proposta original e são normativas para a execução.

### Domínio do Base

O executor do Base deve alterar apenas a projeção de apresentação compartilhada e seu renderer:

1. Extrair de `render_checkout_order_details_section()` para o método público final toda a sequência
   `build_order_display_args` → filtro pré-build → `PaymentDisplayDataBuilder::build()` com o logger
   atual → filtro pós-build.
2. Fazer o renderer chamar o novo método dentro do mesmo `try/catch`. O enqueue do script e
   `wc_get_template()` continuam no renderer e só ocorrem depois de um retorno não nulo.
   `render_admin_order_details_section()` continua delegando ao renderer compartilhado.
3. Expor `expires_at_timestamp` a partir do valor já calculado no builder, sem duplicar o algoritmo
   e sem mudar a semântica de `expires_at`, `expires_at_formatted` ou `is_expired`.
4. Atualizar o PHPDoc do método e do builder com a forma e os tipos do contrato, e atualizar
   `docs/GUIDE-ADD-NEW-GATEWAY.md`: um terceiro gateway fornece apenas os args específicos e herda a
   projeção pública final.
5. Registrar a API no changelog da versão que efetivamente a introduzir. Número de versão e arquivos
   de release continuam sob `release.sh`; esta RFC não autoriza bump manual nem publicação.

### Forma v1 do retorno

Antes do filtro `paycryptome_order_display_data`, um retorno não nulo deve conter todas estas chaves:

| Chave | Tipo/semântica |
|---|---|
| `payment_identifier` | `string` não vazia; endereço on-chain ou BOLT11 |
| `payment_uri` | `string`; URI canônica usada também como payload do QR; vazia apenas em registro legado/incompleto, que o consumidor deve tratar como não pagável |
| `payment_qr_code` | `string`; `data:image/...` ou `''` na degradação suportada |
| `fiat_amount` | `string`; decimal vindo do pedido, sem formatação HTML, ou `''` quando ausente |
| `fiat_currency` | `string`; código da moeda fiat |
| `crypto_amount` | `string|null`; decimal quando conhecido, `null` quando ausente; nunca converter para `float` no contrato |
| `crypto_currency` | `string`; atualmente `BTC` |
| `crypto_label` | `string` de apresentação, traduzida no locale ativo |
| `network_label` | `string` de apresentação, traduzida no locale ativo |
| `crypto_network` | `string`; identificador técnico da rede |
| `expires_at` | `string`; duração legada em horas ou `''`; não é timestamp |
| `expires_at_timestamp` | `int|null`; instante Unix absoluto ou `null` quando não aplicável |
| `expires_at_formatted` | `string|null`; data localizada no locale/timezone ativos |
| `is_expired` | `bool`; comparação calculada no momento da chamada |
| `confirmations_required` | `int` não negativo |

O builder deve normalizar os campos acima para esses tipos, inclusive `''` de meta ausente para
`crypto_amount=null`, sem converter decimais para `float`. Essa estabilização precisa de testes de
carregamento do pedido e não pode alterar o HTML observado. Labels e data formatada são apresentação
localizada, não identificadores para lógica. Consumidores que precisem tomar decisões usam
`crypto_network`, `expires_at_timestamp` e os códigos de moeda.

Esta forma constitui a v1. Descoberta é feita pela presença do método; não será criado um segundo
registry de capabilities nesta entrega. Mudança incompatível futura exige outra RFC e uma nova
superfície versionada, em vez de alterar silenciosamente a v1. Chaves aditivas são permitidas e os
consumidores devem ignorar chaves desconhecidas.

### Efeitos, erros e confiança

“Sem renderização” significa que o código do Base não imprime bytes, não chama template, não
enfileira CSS/JS, não grava meta/status e não consulta BTCPay, lnd ou rede Bitcoin. A operação não é
“pura”: ela lê o pedido, consulta locale/hora, executa os dois filtros, gera o QR localmente e pode
registrar a falha degradável do QR pelo logger já existente. Callbacks de terceiros permanecem fora
do controle do Base e são responsáveis por seus próprios efeitos.

O método não faz autorização nem escaping de saída. Ele é API PHP interna ao processo WordPress,
não endpoint público. O consumidor deve já estar autorizado a ler o `WC_Order` e deve escapar cada
valor para HTML, texto ou transporte no ponto de saída. Também deve capturar `Throwable` quando seu
canal exige degradação; somente o renderer do Base mantém a mensagem de erro ao cliente.

Não adicionar cache entre chamadas. `is_expired`, data formatada, locale e filtros podem variar.
Cada chamada executa a projeção completa uma vez. O consumidor chama uma vez por mensagem/render e
reutiliza o array durante aquela operação, evitando gerar o mesmo QR repetidamente.

### Domínio do Pro e limites

O Pro deve obter a instância já registrada pelo WooCommerce e chamar a API nela. Não deve construir
uma nova instância de gateway em runtime, porque o construtor registra hooks e mantém settings/logger
da instância carregada. Quando não tiver a instância exata, pode percorrer somente gateways
`Abstract_WC_Gateway_PayCryptoMe` registrados e parar no primeiro retorno não nulo; isso preserva as
variantes express reconhecidas por `OrderGatewayMatcher`.

O Pro é dono do hook de e-mail, gating/licença, escolha HTML versus plain text, copy, escaping,
layout e transporte da imagem. Para CID, deve reutilizar os bytes do `payment_qr_code`; não deve
recalcular URI, valor, expiração ou QR. Em plain text, imprime apenas campos textuais apropriados e
nunca inclui HTML ou a `data:` URI. Se `payment_qr_code` vier vazio, degrada para identificador/URI
textual e não inventa outra implementação de QR.

Esta entrega do Base não inclui template de e-mail, hook de e-mail, attachment CID, webhook,
notificação, cotação fiat→sats, countdown, tela, CSS/JS do Pro nem alteração de licença. Também não
encerra P7f, M9 ou M10 e não autoriza edição no repositório Pro durante a execução do Base.

### Critérios de aceite do Base

1. `get_order_display_data()` retorna `null` para pedido/gateway incompatível sem disparar filtros.
2. Para Bitcoin e Lightning, retorna o mesmo array final que o renderer fornece ao template,
   incluindo `expires_at_timestamp`.
3. Os filtros pré e pós recebem três argumentos e executam exatamente uma vez por chamada.
4. Solicitar somente os dados não imprime bytes, não carrega template e não enfileira
   scripts/estilos.
5. QR indisponível degrada para string vazia mantendo URI e identificador.
6. O renderer continua produzindo o HTML existente e enfileira seus assets somente ao renderizar.
7. Uma falha lançada pelo build/filtro propaga pela API pública, mas o renderer a captura, registra e
   mantém sua mensagem segura sem fatal.
8. Expiração cobre precedência do timestamp absoluto, fallback legado, expirada, sem validade e
   `show_expiry=false`; este último deve produzir `expires_at_timestamp=null` também quando existir
   meta absoluto antigo.
9. A suíte unitária completa, auditoria de traduções/documentação e smoke do host mínimo passam. Não
   há motivo para schema tests, pois esta proposta não toca schema; execute-os apenas se o diff real
   ultrapassar este limite.

### Aceite posterior no Pro

Os critérios seguintes pertencem ao consumidor e não bloqueiam o merge isolado do Base:

1. Um teste de integração usa a instância registrada e injeta os dados em um e-mail HTML real do
   WooCommerce sem acessar propriedades protegidas, capturar o renderer ou gerar outro QR.
2. O caminho `plain_text=true` não contém HTML nem `data:` URI.
3. URI, identificador, valor e expiração coincidem entre página do pedido e e-mail; se houver CID,
   seus bytes vêm do QR fornecido pelo Base.
4. O Pro eleva e testa `BASE_MIN_VERSION` contra a versão publicada que introduzir o método, incluindo
   a mensagem do dependency guard para uma versão imediatamente anterior.

## Impacto nos consumidores

P7f já pôde concluir sua prova usando o renderer existente e não precisa ser reaberta por esta RFC.
Antes da implementação real de M9, o Pro deve exigir a versão publicada que contenha a nova API.
M10 pode usar a mesma operação para garantir que ajustes de display permaneçam coerentes com o Base.

Executar esta RFC não reabre P7f nem implementa M9 ou M10. Cada entrega conserva seus próprios
testes e revisão.
