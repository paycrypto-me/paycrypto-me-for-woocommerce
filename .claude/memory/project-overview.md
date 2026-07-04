---
name: project-overview
description: "O que é o plugin paycrypto-me-for-woocommerce, seu propósito, stack e estrutura de diretórios"
metadata: 
  node_type: memory
  type: project
  originSessionId: bb8519f4-c6c4-4565-868e-f947b0f2ee52
---

## PayCrypto.Me for WooCommerce

Plugin WordPress GPL-3.0 que adiciona gateways de pagamento em Bitcoin ao WooCommerce. Versão atual: **0.1.0**. Autor: Lucas Rosa (lucasrosa95 / lucas.rosa95br@gmail.com).

**Por que existe:** Permitir lojas WooCommerce aceitarem Bitcoin de forma non-custodial, sem intermediários.

**Dois gateways registrados:**
- `paycrypto_me` — Bitcoin On-Chain (via xPub/ypub/zpub derivation, mainnet e testnet)
- `paycrypto_me_lightning` — Bitcoin Lightning Network (BTCPay Server ou lnd REST)

**Stack:**
- PHP ≥ 7.4, namespace `PayCryptoMe\WooCommerce`
- Composer: `endroid/qr-code`, `lucas-rosa95/bitcoin` (fork privado de `bitwasp/bitcoin-php`)
- JS/CSS: `@wordpress/scripts` (webpack), blocos WooCommerce (Gutenberg checkout blocks)
- Tests: PHPUnit 9.5 (unit-only, sem WordPress real — usa shims em `tests/_support/`)

**Estrutura de diretórios (raiz do plugin):** `src/trunk/`
- `paycrypto-me-for-woocommerce.php` — entrypoint, registra os dois gateways
- `includes/` — toda a lógica PHP
- `assets/` — CSS/JS compilados (bloco e admin)
- `templates/` — templates PHP do WooCommerce (checkout, order-details)
- `tests/` — PHPUnit
- `scripts/` — shell scripts de build (translations, release)
- `webpack.config.js`, `package.json`, `composer.json`

**Como as coisas se ligam:**
- `WC_PayCryptoMe` (singleton) registra gateways via `woocommerce_payment_gateways`, inclui arquivos de classe e carrega suporte a blocos.
- Ativação do plugin cria as tabelas via `PayCryptoMeBitcoinGatewayActivate::activate` (3 tabelas
  On-Chain) e `PayCryptoMeLightningGatewayActivate::activate` (tabela de invoices Lightning) — as
  duas registradas via `register_activation_hook` em `paycrypto-me-for-woocommerce.php`.

**Why:** Manter esta memória ajuda futuros agentes a não confundir arquivos compilados com fontes editáveis, e a entender que o plugin tem dois gateways completamente separados com lógicas distintas.

**How to apply:** Ao trabalhar no plugin, sempre editar fontes em `src/trunk/includes/` e `src/trunk/includes/blocks/js/`. Nunca editar diretamente `assets/blocks/` (são artefatos de build). Os dois gateways (On-Chain e Lightning) estão completos e funcionais; webhook e conversão fiat→sats são escopo reservado de propósito para um add-on premium separado, não trabalho pendente na versão free — ver `CLAUDE.md` § "Premium add-on: scope boundaries and extension points" e [[architecture]].
