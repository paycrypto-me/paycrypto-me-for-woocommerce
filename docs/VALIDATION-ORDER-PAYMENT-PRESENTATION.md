# [VALIDATION] Apresentação do pagamento conforme o estado do pedido

Validação local em 24 de novembro de 2026 com WordPress 7.1.4, PHP 8.3, MySQL 8 e Chromium 158. O pacote publicado 0.5.2 serviu de referência. O candidato foi executado a partir da árvore de trabalho.

| Loja isolada em Podman | Caminho de checkout exercitado pelo navegador | Armazenamento |
|---|---|---|
| WooCommerce 10.9.0 | Checkout clássico, formulário e submissão pelo endpoint AJAX | HPOS |
| WooCommerce 11.3.1 | Checkout Blocks, carrinho e submissão pela Store API | Pedidos clássicos |

## Resultados

- **Mudança pelo editor.** Em ambas as lojas, um pedido On-Chain salvo como Processando e um Lightning salvo diretamente como Concluído passaram de “Awaiting Payment” a “Payment recorded by the store”. Verificados painel, pedido recebido e Minha Conta. Apenas o painel conservou a referência copiável. O teste de clipboard copiou a referência de um pedido encerrado sem navegar ou salvar o formulário.
- **Convidado.** As duas submissões de checkout percorreram o processador real do gateway. A página de retorno na sessão autorizada exibiu a cobrança pendente e retirou os controles após a confirmação e a recarga. O endpoint Lightning usou o nó BTCPay simulado do harness do repositório.
- **Compatibilidade.** Com o template ativo da 0.5.2 copiado para o tema, as alterações em lote para Processando e pela API REST para Cancelado renderizaram o resumo sem endereço copiável, link ou imagem no cartão do cliente. Um pedido criado e concluído pelo ZIP 0.5.2 foi projetado como pago pelo candidato no mesmo banco.
- **Reabertura e nova tentativa.** Uma fatura vencida em pedido reaberto exibiu o link de nova tentativa para o formulário nativo do mesmo pedido. A submissão reutilizou a fatura válida e substituiu a expirada. O teste de derivação reutilizou o endereço já atribuído. Duas leituras do pedido pago preservaram a linha Lightning e a contagem de chamadas de criação no nó simulado.
- **Efeitos comerciais.** Em produto físico com estoque gerenciado, Em espera → Processando → Concluído reduziu o estoque de cinco para quatro. Leituras repetidas mantiveram o estoque e a quantidade de notas. Um reembolso parcial de 3,55 em pedido pago conservou o estado pago. Um produto virtual baixável seguiu o caminho de conclusão direta do WooCommerce.
- **Concorrência.** Na 0.5.2, uma finalização iniciada em Falhou sobrescreveu Processando com Pendente. No candidato, uma requisição aguardou na resposta HTTP simulada do nó enquanto outra marcou o pedido como Processando. Nas duas versões de WooCommerce, com origem Pendente e Falhou, a retomada preservou Processando e o identificador da fatura emitida. A suíte também cobre o retry sem concorrência e o rollback de uma transação externa.
- **Host mínimo.** Os cinco cenários de `scripts/smoke-minimal-host.sh` passaram. O pedido usado para testar a exibição sem GD agora começa como Pendente com total positivo, pois um pedido de valor zero não tem pagamento a receber.

## Reexecução

Rodar a suíte unitária a partir de `src/trunk` com `vendor/bin/phpunit` e a de integração pelo fluxo do repositório em `scripts/schema-tests.sh`. Em duas lojas que compartilhem o mesmo servidor MySQL, rodar as suítes de integração em sequência: o teste de contenção do instalador usa um advisory lock compartilhado no servidor.

A execução com GMP descobriu 459 testes unitários. Os quatro condicionados à ausência de GMP passaram em uma segunda execução com a extensão desativada. A suíte real completou 33 testes e 243 asserções em cada loja. Os oito cenários do harness de projeção Lightning passaram. O Plugin Check do candidato montado com os arquivos de distribuição passou. A auditoria de i18n passou e os sete catálogos compilaram com 138 mensagens traduzidas cada.

Na preparação da 0.6.0, o script de release gerou o ZIP em uma cópia isolada da árvore. A inspeção confirmou os dois blocos, o autoloader otimizado e o template de resumo. O Plugin Check 2.2.0 passou sem erros ou avisos em uma instalação limpa do ZIP. No mesmo ambiente, um pedido Pendente exibiu o endereço ao cliente e a transição para Processando retirou essa instrução de pagamento.
