---
name: dev-workflow
description: "Como fazer build, testes, tradução e release do plugin"
metadata: 
  node_type: memory
  type: project
  originSessionId: bb8519f4-c6c4-4565-868e-f947b0f2ee52
---

## Workflow de Desenvolvimento

### Build de assets JS/CSS

Trabalhar dentro de `src/trunk/`:

```bash
# Build de produção dos blocos WooCommerce
npm run build

# Watch mode (desenvolvimento)
npm run dev
```

Fontes JS em `includes/blocks/js/`. Saída em `assets/blocks/`. O webpack está configurado em `webpack.config.js`. Usa `@wordpress/scripts`.

### Testes PHPUnit

```bash
cd src/trunk
./vendor/bin/phpunit
```

Configuração em `phpunit.xml.dist`. Bootstrap em `tests/bootstrap.php`. Usa shims próprios (sem WordPress real) em `tests/_support/`.

Arquivos de teste principais:
- `tests/phpunit/unit/` — todos os testes unitários
- `tests/phpunit/BitcoinAddressVectorsTest.php` — testa geração de endereços contra vetores JSON

### Traduções

```bash
npm run translate        # Gera .pot, .po e .mo
npm run translate:pot    # Só .pot
npm run translate:po     # Só .po
npm run translate:mo     # Só .mo
```

Scripts shell em `scripts/`. Documentação em `docs/TRANSLATION.md`.

### Release

```bash
# Ver o script para detalhes
./scripts/release.sh
```

Releases ficam em `releases/`. Artes finais de banner/ícone/screenshots ficam em `src/assets/`
(pasta `artifacts/` com a arte-fonte da marca antiga foi descartada após o rebrand de
2026-07-04 — detalhes na memória cross-sessão do agente, não neste repo; ver histórico de commits
de 2026-07-04 se precisar do antes/depois).

### Ambiente local

Docker Compose disponível (`docker-compose.yml` na raiz e `.devcontainer/`). Existe script para ajustar `upload_max_filesize` no PHP: `scripts/modify-upload-max-size-php-ini.sh`.

### Dependências Composer incomuns

`lucas-rosa95/bitcoin` é um fork privado de `bitwasp/bitcoin-php` hospedado em `https://github.com/lucas-rosa95/bitcoin-php`. O `composer.json` define repositórios VCS customizados para ele e para `buffertools-php`.

**Why:** Saber que as dependências PHP vêm de repos VCS privados/forked é importante ao rodar `composer install` em ambientes novos ou de CI.
