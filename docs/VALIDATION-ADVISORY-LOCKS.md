# [VALIDATION] Isolamento dos locks de instalação e de carteira

Candidato da issue #1, em cima de `e122c59`. O código distribuível foi implementado em
`b46197e`. Os commits até `2b9e2de` ampliam a contraprova e o diagnóstico. Até `f099a0f`, a suíte também passou a identificar as tabelas temporárias
de processos independentes no mesmo banco. A distribuição
pública usada no ensaio é a 0.6.0, tag `v0.6.0` (`4c2f669`). O candidato de QA tem o
mesmo cabeçalho de versão e SHA-256
`f914bf04274970f1df9fd0858d67572df493329c7de984027341661224f3b3dc`.

## Critérios observados

| Caso | Operação e resultado observado |
|---|---|
| Controle histórico | Na investigação da 0.6.0, uma conexão no segundo banco expirou em 10,2 s para o instalador e a carteira 1; a carteira 2 foi adquirida em 0,2 s. As duas aquisições antes bloqueadas tiveram sucesso após a liberação. Essa medição foi feita diretamente no MySQL. A suíte da base passou com 33 testes e 243 asserções. |
| Identidade | Vetores literais cobrem nomes de 62 bytes, banco, recurso, carteira, prefixo, modos de caixa e identidade inválida. O MySQL da integração usa a configuração Linux padrão. Os modos de normalização 1 e 2 foram exercitados com doubles unitários. |
| Lojas independentes | Com o instalador de A retido, wp-admin ativou B e C. Cada loja registrou versão 2 e quatro tabelas sob seu próprio prefixo. Os instaladores de B e C também completaram em processos paralelos. Com a carteira 1 de A retida, B e C reservaram índice 0 e fizeram checkout no índice 1. |
| Mesmas tabelas ou carteira | A segunda conexão respeitou o timeout. O retry após liberação funcionou. Duas reservas em processos distintos na mesma carteira retornaram 0 e 1. Uma segunda carteira pôde reservar enquanto a primeira estava ocupada. |
| Falhas e recuperação | Retorno SQL nulo e identidade ausente interrompem o fluxo. O instalador em erro registra diagnóstico e retentativa. Um erro de escrita forçado por constraint MySQL deixou zero linhas de índice e a conexão independente readquiriu o lock. A suíte de schema cobre reparo e convergência dos snapshots. |
| Multisite | Ao trocar de blog, o serviço criado no blog de destino começou no índice 0. O serviço que já existia antes da troca reservou 2 na tabela original e, após restaurar o blog, reservou 3. |
| Navegador | Chromium executou ativação como administrador, compra clássica e Blocks como cliente e `order-pay`. Cada checkout apresentou o endereço gravado para o pedido pendente. O pedido pago exibiu estado pago e zero botões de cópia. |
| Atualização e rollback | A loja preservou as linhas On-Chain e Lightning, identificadores e status. O retry reutilizou a cobrança. O novo HD recebeu índice 1 após um retry do pedido de índice 0. O endereço fixo continuou com carteira 0. BTCPay e lnd REST atravessaram o adaptador HTTP com nós simulados. |
| Pacote | Plugin Check 2.2.0 retornou “Success: Checks complete. No errors found.”. O smoke de GMP, GD, iconv e fileinfo e o audit de drift passaram. |
| Mutações negativas | Remover o banco da identidade, o ID da carteira ou a aquisição fez falhar o teste específico de cada mutação. Cada execução registrou exatamente uma falha de asserção. As três alterações foram montadas somente no container unitário. |

O ensaio isolado de transição confirmou que a conexão de uma versão pode adquirir o nome
antigo enquanto outro processo detém o nome novo, para instalador e carteira. Depois da
drenagem, foram comparados os valores de configuração e as impressões dos metadados
`_paycrypto_me_*` de cada pedido antes e depois de cada troca, antes de disparar o retry.

## Contraprova de auditoria

O [arquivo de evidências](../tests/manual/advisory-locks/evidence.zip) contém os resumos SQL,
saídas de PHPUnit, resultados dos controles negativos, eventos de navegador, screenshots
dos identificadores de teste, versões e resultados do Plugin Check. Os hashes SHA-256 dos
membros estão em `SHA256SUMS`. Para verificar o arquivo, descompacte-o em uma pasta vazia
e rode `sha256sum -c SHA256SUMS` nessa pasta. Os resultados de upgrade incluem o digest
do pacote carregado. Os screenshots foram recortados para o identificador visível.

Na execução final (`verified-unit.log`), a suíte com GMP declarou 470 testes e 1.169
asserções, com quatro casos específicos de ambiente sem GMP pulados. Em PHP sem GMP,
os dois grupos de testes ambientais executaram 23 casos, 38 asserções e um skip
esperado para um caso exclusivo de host com GMP. A integração final com WordPress e
MySQL declarou **35 testes e 255 asserções**, sem skips.

As rodadas `pcm-fa98146d`, `pcm-08b06425` e `pcm-da395c44` usaram ambientes limpos
e o mesmo SHA-256 do candidato. O arquivo contém os resumos das três. Em `pcm-e565b0dc`,
as suítes completas de A e C executaram simultaneamente no mesmo banco com opções e tabelas
temporárias independentes. Ambas passaram com 35 testes e 255 asserções. Ao preparar essa
verificação, o worker de teste Lightning rejeitou o novo prefixo hexadecimal; sua validação
foi alinhada ao gerador e as duas suítes foram reexecutadas desde o início.

SHA-256 do arquivo de evidências: `f0c48afbeb2d97a114941536d8f72ddbb6706c64860c5ca9dbbbb91dc1c2364c`.

## Limites e achados

O procedimento de upgrade e rollback requer drenagem dos processos da loja. A manutenção
do WordPress por si só não encerra workers antigos. A troca preserva o schema 2. O ensaio
usou WordPress 7.1.4, WooCommerce 11.3.1, PHP 8.3.36, MySQL 8.0.46, Playwright 1.65.0 e
Chromium 158.0.8127.18. O banco era uma instância única e os pagamentos usaram fixtures
locais. O laboratório não mediu tráfego de produção ou prevalência entre comerciantes.

A primeira inserção simultânea do mesmo xPub/rede é uma disputa anterior à reserva do índice.
Na 0.6.0 o diagnóstico observou `Duplicate entry` na chave `unique_xpub_network` e falha de
uma tentativa. O banco preservou a unicidade da carteira. Esse caminho continua fora da seção
crítica alterada pela issue #1. Tratar o perdedor relendo a linha vencedora requer uma mudança
específica no fluxo de criação da carteira. O reset administrativo de índices também fica fora
do procedimento de atualização.

No desenvolvimento da fixture, a abertura direta de Blocks logo depois de adicionar um produto
à sessão de administrador provocou HTTP 409 do WooCommerce antes de invocar o gateway.
O cenário final usa um cliente, percorre o carrinho e aguarda a página carregar. O runner
não repete o clique de compra para mascarar erros. O laboratório não dispõe de sendmail.
Os avisos de envio de e-mail não interferiram nas asserções sobre pedidos e pagamentos.

Veja o [procedimento de reprodução](../tests/manual/advisory-locks/README.md) e a
[transição operacional](GUIDE-RELEASE.md#transição-dos-locks-de-instalação-e-derivação-após-060).
