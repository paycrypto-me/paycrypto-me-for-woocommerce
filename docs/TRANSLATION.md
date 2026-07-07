# 🌍 PayCrypto.Me Translation Guide

Este guia explica como gerenciar as traduções do plugin PayCrypto.Me for WooCommerce.

## 🚀 Scripts Automatizados

### Usando NPM (Recomendado)

```bash
# Gerar todas as traduções (POT + PO + MO)
npm run translate

# Script rápido (mesma função, interface simplificada)
npm run translate:quick

# Gerar apenas arquivo POT (template)
npm run translate:pot

# Criar/atualizar arquivo PO específico
npm run translate:po pt_BR

# Compilar arquivo MO específico
npm run translate:mo pt_BR
```

### Usando Scripts Diretos

```bash
# Tornar executável (primeira vez)
chmod +x ./scripts/build-translations.sh
chmod +x ./scripts/quick-translate.sh

# Gerar todas as traduções
./scripts/build-translations.sh

# Script rápido
./scripts/quick-translate.sh

# Comandos específicos
./scripts/build-translations.sh pot
./scripts/build-translations.sh po pt_BR
./scripts/build-translations.sh mo pt_BR
```

## 📁 Estrutura de Arquivos

```
languages/
├── paycrypto-me-for-woocommerce.pot        # Template (gerado automaticamente)
├── paycrypto-me-for-woocommerce-pt_BR.po   # Tradução Português Brasil
├── paycrypto-me-for-woocommerce-pt_BR.mo   # Compilado Português Brasil
├── paycrypto-me-for-woocommerce-en_US.po   # Tradução Inglês EUA
├── paycrypto-me-for-woocommerce-en_US.mo   # Compilado Inglês EUA
├── paycrypto-me-for-woocommerce-es_ES.po   # Tradução Espanhol
└── paycrypto-me-for-woocommerce-es_ES.mo   # Compilado Espanhol
```

## 🛠️ Ferramentas Recomendadas

### 1. PoEdit (Desktop)
- **Download**: https://poedit.net/
- **Uso**: Abrir arquivos `.po` para tradução visual
- **Vantagens**: Interface amigável, validação automática, compilação MO

### 2. Loco Translate (WordPress Plugin)
- **Instalação**: WordPress Admin > Plugins > Adicionar Novo > "Loco Translate"
- **Uso**: Admin > Loco Translate > Plugins > PayCrypto.Me
- **Vantagens**: Tradução direto no WordPress, sem arquivos externos

### 3. Editor Manual
- **Arquivos**: Editar `.po` em qualquer editor de texto
- **Formato**: `msgid "Original"` → `msgstr "Tradução"`

## 📝 Como Adicionar Nova Tradução

### 1. Adicionar Novo Idioma

```bash
# Criar arquivos para novo idioma (ex: francês)
./scripts/build-translations.sh po fr_FR
./scripts/build-translations.sh mo fr_FR
```

### 2. Atualizar Script (Opcional)
Editar `scripts/build-translations.sh`, linha com `LANGUAGES=`:

```bash
LANGUAGES=("pt_BR" "en_US" "es_ES" "fr_FR")
```

## 🔄 Workflow de Tradução

### Para Desenvolvedores

1. **Adicionar novas strings**:
   ```php
   // Sempre usar funções de tradução
   __('New string', 'paycrypto-me-for-woocommerce')
   esc_html__('Safe string', 'paycrypto-me-for-woocommerce')
   ```

2. **Regenerar POT**:
   ```bash
   npm run translate:pot
   ```

3. **Atualizar traduções existentes**:
   ```bash
   npm run translate
   ```

### Para Tradutores

1. **Abrir arquivo PO** no PoEdit ou Loco Translate
2. **Traduzir strings** vazias (`msgstr ""`)
3. **Salvar arquivo** (PoEdit compila MO automaticamente)
4. **Testar** mudando idioma do WordPress

## 🎯 Boas Práticas

### ✅ Fazer
- Usar sempre text domain: `'paycrypto-me-for-woocommerce'`
- Regenerar POT após adicionar strings
- Testar traduções em diferentes idiomas
- Manter traduções curtas e claras

### ❌ Evitar
- Strings hardcoded sem tradução
- Text domain incorreto ou ausente
- Concatenação de strings traduzidas
- Tradução de strings de debug/desenvolvimento

## 🔧 Dependências

### Automaticamente Detectadas
- **WP-CLI** (preferencial): `wp i18n make-pot`
- **xgettext** (alternativa): `apt-get install gettext`
- **msgfmt** (compilação MO): incluído no gettext

### Instalação Ubuntu/Debian
```bash
# Instalar gettext
sudo apt-get update
sudo apt-get install gettext

# Instalar WP-CLI (opcional, mas recomendado)
curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
chmod +x wp-cli.phar
sudo mv wp-cli.phar /usr/local/bin/wp
```

## 🐛 Solução de Problemas

### Erro: "WP-CLI não encontrado"
- Instalar WP-CLI ou usar gettext como alternativa
- Script detecta automaticamente qual usar

### Erro: "msgfmt não encontrado"
```bash
sudo apt-get install gettext
```

### Traduções não aparecem
1. Verificar se arquivo MO existe e está compilado
2. Verificar Domain Path no plugin header
3. Verificar função `load_textdomain()` no plugin
4. Limpar cache do WordPress

### Strings não aparecem no POT
1. Verificar se usam funções de tradução corretas
2. Verificar text domain nas strings
3. Regenerar POT: `npm run translate:pot`

## 📊 Status Atual

- ✅ Text Domain configurado: `paycrypto-me-for-woocommerce`
- ✅ Domain Path: `/languages/`
- ✅ Função load_textdomain implementada
- ✅ Strings usando funções corretas de tradução
- ✅ Scripts de automação criados (incluindo `Plural-Forms` e headers corretos por locale)
- ✅ Idiomas traduzidos (125/125 strings, 100%): `pt_BR`, `es_ES`, `de_DE`, `fr_FR`, `it_IT`, `ru_RU`, `zh_CN`

## 🤝 Contribuindo com Traduções

Para contribuir com traduções:

1. Fork do repositório
2. Criar/atualizar arquivo de tradução
3. Testar tradução
4. Enviar Pull Request

Ou usar plataforma de tradução online (se configurada futuramente).