# QA das reservas On-Chain e da instalação em MySQL compartilhado

Execute a partir da raiz do repositório, com Python 3, Node, Podman, rsync, zip e unzip.
Use uma imagem WordPress Apache com o entrypoint oficial, WP-CLI, Composer, PHP 8.1+,
mysqli, GMP e GD. Neste host a imagem é `localhost/paycrypto-plugin-release-tools:0.4.3`.
Em outro host, defina `QA_WP_IMAGE` ao construir a imagem do Dockerfile do repositório
(use root para o processo inicial do Apache). O banco descartável usa `mysql:8.0`.

1. Instale as dependências de desenvolvimento do plugin com `composer install` em
   `src/trunk` (pode usar a imagem de QA montando essa pasta em `/plugin`).
2. Disponibilize Playwright 1.65.0 e seu Chromium com
   `npx --yes --package=playwright@1.65.0 playwright install chromium`. O runner aceita
   `NODE_PATH` e procura também no cache npm do usuário.
3. Coloque o ZIP público em `releases/paycrypto-me-for-woocommerce-0.6.0.zip`.
   O [`package.sh`](package.sh) contém o SHA-256 da 0.6.0 e o confere ao preparar o candidato.
   Opcionalmente, aponte `QA_PLUGIN_CACHE` para uma pasta contendo as árvores de
   `woocommerce/` e `plugin-check/` para reutilizá-las em modo somente leitura.
4. Rode:

   ```bash
   bash tests/manual/advisory-locks/run.sh
   python3 tests/manual/advisory-locks/controls.py
   ```

O runner regenera o classmap de desenvolvimento. O pacote de QA conserva as dependências
de produção e os assets da distribuição 0.6.0 e incorpora os fontes PHP em `includes/`
e o changelog desta alteração. O script `package.sh` confere os manifests Composer e
reconstrói o classmap de produção. Para forçar novo empacotamento, rode
`bash tests/manual/advisory-locks/package.sh`. A versão do cabeçalho permanece 0.6.0
até o processo de release. Use o SHA-256 para identificar o candidato.

## Execução e evidência

Cada rodada cria uma rede, um MySQL e instalações WordPress descartáveis. A e B usam
`wp_` em bancos diferentes; A e C usam prefixos diferentes no mesmo banco. A fixture
segura o recurso de A até receber o sinal de liberação do coordenador. Os checkouts
clássico e Blocks passam pelo navegador de um cliente. O runner lê a linha do pedido
em outro processo e compara índice, status e endereço. Depois converte A em multisite,
alterna blogs e verifica também um serviço construído antes da troca de blog.

Uma quarta loja mantém o mesmo banco durante 0.6.0 → candidato → 0.6.0. A troca de
pacote ocorre com o servidor da loja parado. Os pedidos usam os gateways reais e
respostas de nó locais para BTCPay e lnd REST. O diagnóstico de primeiro uso simultâneo
de uma carteira tem resultado separado dos cenários que partem de uma carteira existente.

Os logs e resultados ficam em `releases/advisory-locks/pcm-*/`. O resumo de concorrência
só é gravado após as asserções de banco e navegador. O resumo de upgrade só é gravado
após o rollback. Os eventos de rede do Playwright contêm caminho, método, status e
tempo relativo; os parâmetros de consulta, corpos, cookies e cabeçalhos são omitidos.
O runner remove seus containers, volumes anônimos e rede ao sair. Para a auditoria de
três rodadas, executar o comando sequencialmente três vezes. Fechar a rodada pelo código
de saída e conferir os dois resumos. As fotos de falha servem para diagnóstico local.

Para reproduzir o cenário original de duas suítes PHPUnit em paralelo, use
`run.sh --parallel-suites`. As duas instalações têm opções WordPress próprias e compartilham
um banco; os testes geram prefixos temporários independentes para suas tabelas descartáveis.

Para repetir somente a integração, usar `run.sh --integration-only`. Para repetir o
pacote com Plugin Check e rollback, usar `run.sh --upgrade-only`. Para os quatro casos
unitários específicos de host sem GMP, rodar PHPUnit com
`--filter 'OnchainWithoutGmpTest|UnavailabilityNoticeScopeTest'` em PHP sem essa extensão.
Os três controles alteram somente cópias temporárias sobrepostas em containers de teste.
Cada controle exige a falha de asserção esperada e guarda a saída em `controls/`.
