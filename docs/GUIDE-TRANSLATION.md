# [GUIDE] Translation catalogs

English is the plugin's source language. WordPress uses the source strings for English. The shipped catalogs cover `pt_BR`, `es_ES`, `de_DE`, `fr_FR`, `it_IT`, `ru_RU` and `zh_CN`. For placeholders, product names, context and HTML, see the [string-authoring conventions](GUIDE-I18N-CONVENTIONS.md).

## Catalog scope

| Audience or purpose | Policy | Examples |
|---|---|---|
| Customer | Translate all visible messages, including payment failures. | Order details, checkout gateway titles and descriptions, wallet actions, BIP21 memo. |
| Administrator configuring the plugin | Translate settings titles, descriptions, field labels, buttons and badges. | “BTCPay Server URL”, “Invoice Expiry”, “Test connection”. |
| Administrator troubleshooting the plugin | Write errors, warnings, notices and logs in literal English so support can search the original message. | `WC_Admin_Settings::add_error()`, security failures and `register_paycrypto_me_log()`. |
| Diagnostic action results | Write both successful and failed diagnostic results in literal English. | “Connection OK (HTTP %d).”, “Reset request received.” |
| Internal order history | Store order and status-change notes in English. These persist in the language used at write time. | “Awaiting cryptocurrency payment”. |

When an English diagnostic names a field, interpolate its translated settings label so the administrator can find it on screen:

```php
\WC_Admin_Settings::add_error(sprintf('%s must use HTTPS.', esc_html__('BTCPay Server URL', 'paycrypto-me-for-woocommerce')));
```

Escape dynamic values for their output context. Gettext translator comments belong with translatable messages. When changing a string's audience, check that message counts change as expected in every locale.

## Generate and update catalogs

Run commands from the repository root with the Compose `wordpress` service running. The script invokes WP-CLI and gettext inside the container. The npm translation aliases in `src/trunk/package.json` resolve `./scripts/` relative to the package directory and miss the repository scripts.

```bash
docker compose up -d wordpress
./scripts/build-translations.sh
```

The default command extracts the POT, merges each PO and compiles each MO. It updates catalog structure and leaves new or fuzzy translations for review. `scripts/quick-translate.sh` wraps the default command. For individual stages:

```bash
./scripts/build-translations.sh pot
./scripts/build-translations.sh po pt_BR
./scripts/build-translations.sh mo pt_BR
./scripts/build-translations.sh json pt_BR
```

1. Write source messages using a literal `paycrypto-me-for-woocommerce` text domain. Regenerate the template and POs after editing translatable sources, even when only line references move. The POT also records `Project-Id-Version` from the plugin header. Review added or removed `msgid` entries separately from reference and timestamp changes.
2. Fill empty `msgstr` entries and review fuzzy matches in each PO. Use a text editor, PoEdit or Loco Translate. Contributors and agents can edit requested translations directly. Preserve placeholders, plural forms and meaning. Use the project build script for extraction, merge, header generation and compilation.
3. Validate every PO and inspect the per-locale statistics from inside the container:

   ```bash
   docker compose exec -w /var/www/html/wp-content/plugins/paycrypto-me-for-woocommerce/languages \
     wordpress bash -ec 'for f in *.po; do echo "$f"; msgfmt --check --statistics -o /dev/null "$f"; done'
   ```

4. After editing a PO, run the `mo` and `json` stages for that locale. Check output files, switch the WordPress site language and verify the affected PHP and Blocks screens. Include reviewed catalogs with the pull request.

## Runtime files

In `src/trunk/languages/`, the shared `.pot` holds source messages, each `.po` holds editable translations and each `.mo` contains compiled runtime translations. For Blocks, each locale also ships two JSON catalogs, one each for `paycrypto_me-blocks` and `paycrypto_me_lightning-blocks`. WP-CLI initially hashes source paths in `includes/blocks/js/`. WordPress requests hashes based on the registered bundles in `assets/blocks/`. The JSON stage renames outputs to the measured runtime hashes. Ship the two runtime files per locale (14 total for seven locales). Source-hash intermediates can be regenerated.

After a merge, `create_po_file()` uses `msgattrib --no-obsolete` to prune removed messages marked `#~`. It writes a temporary file before replacing the PO to avoid truncating its input. If the tool is missing, the script warns and retains obsolete entries. They do not enter the MO. If a pruned message returns, translate it again or recover its translation from Git history.

## Add a locale

Add the locale to the default language list in `scripts/build-translations.sh` and define the correct gettext plural rule in `plural_forms_for_locale()` for `_n()`. Generate the PO, translate and validate it, then build the MO and both runtime JSON catalogs. Update the locale inventory in the readme, this guide and the documentation audit. Verify singular, plural and Blocks messages on a disposable store using the new locale.

## Troubleshooting

- **Build script unavailable.** Start in the repository root and run the script directly. Check that `docker compose` or `docker-compose` is installed and that the `wordpress` service is running. The image supplies WP-CLI, xgettext, msgmerge and msgfmt. POT extraction can fall back to xgettext.
- **Missing source messages.** Check the gettext function and literal domain, regenerate the POT and inspect it for the exact source message.
- **Translation missing on screen.** Check that the locale's MO was rebuilt and the Blocks runtime JSON files match the registered bundle paths. Verify the plugin header's `Domain Path: /languages/` and `load_plugin_textdomain()` on `init`. Clear the WordPress cache and repeat the check.
- **Coverage unclear.** Use the per-locale statistics above and inspect fuzzy and untranslated entries. Historical counts describe the catalog at a particular revision.
