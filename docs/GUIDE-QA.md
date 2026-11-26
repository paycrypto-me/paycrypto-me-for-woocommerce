# [GUIDE] Quality assurance and documentation

## From investigation to delivery

1. Record the issue, published version or commit, environment, initial state, reproduction steps, observed result and impact. For a suspected regression, run the same scenario against the baseline and candidate. Distinguish confirmed findings, hypotheses, pre-existing failures and unrelated issues.
2. Identify the behavior the change must preserve. Add assertions that would catch a test loading the wrong version, selecting another gateway, skipping the operation or receiving an empty fixture.
3. Reuse the least expensive test that observes the failure. Show it fails for the expected reason on the baseline or with one disposable mutation. Repeat it after the fix and select the remaining checks from the matrix.
4. Once the focused test passes, run the subsystem suite. Revalidate everything affected by the final diff. Recreate the environment for an infrastructure retry. For an intermittent test, retain the first failure, investigate its cause and report the outcome.
5. Link the pull request to its issue. Record results, evidence, limitations and residual risks. When authorized to post, attach the audit evidence to the original issue. Track confirmed unrelated findings separately with reproduction steps, impact and acceptance criteria.

## Verification matrix

| Changed area | Required evidence |
|---|---|
| Pure PHP, validation, URI generation or presentation | Focused PHPUnit boundary cases and the full unit suite. Exercise both environments when an extension changes the result. |
| Persistence, locks, migrations, retries or Lightning status projection | WordPress with real MySQL. Compare records before and after. Exercise two connections, write failure, lock release, timeout, re-entry and recovery where applicable. Follow the [schema guide](GUIDE-DB-SCHEMA-UPGRADE.md). |
| Gateway, checkout or order screen | Unit tests for the rule and Playwright for the user journey. Verify authorization, selected method, status and visible payment reference. Read the stored record from a separate process. Cover classic checkout and Blocks for shared logic, and classic order storage and HPOS for changes affecting both. |
| Settings, credentials, retention or uninstall | Exercise administrator and customer views as applicable. Verify permissions and messages. For retention, test the default and opted-in choices, preserved and deleted records, and independent stores. Use the [uninstall runner](../tests/manual/uninstall/README.md). |
| PHP extensions or vendor packages | WordPress smoke test, platform pin audit, affected runtime modes and ZIP dependency comparison. See the [dependency guide](GUIDE-DEPENDENCIES.md). |
| Translations, UI strings or Blocks | Run the i18n audit, regenerate affected catalogs, build Blocks and inspect the affected screen. |
| Documentation, fixtures or automation | Audit the docs and execute new or changed commands in the appropriate disposable environment. Check invalid usage, visible errors and cleanup. |
| Release | Run unit, MySQL, smoke and build audits. Install the exact ZIP on a clean store, run Plugin Check and rehearse the transition from a published release. Apply the other rows for the areas changed. Follow the [release runbook](GUIDE-RELEASE.md). |

### Test design and maintenance

- **Concurrency.** Hold the first process at an observable barrier before releasing the second. Vary database, prefix and wallet independently. Record both outcomes and persisted state. A sleep alone cannot establish contention.
- **Silent failures.** Cover missing data, SQL NULL, discarded writes, a version that fails to advance, pending work, duplicates and locks retained after errors. Inspect structure and content through another connection. An HTTP 200, a success message or an empty `last_error` provides only partial evidence.
- **Browser.** Record the Chromium version Playwright actually uses. Select elements by role, label or stable markup. Wait for the UI state that permits the action and the outcome that proves it completed. Click once for one purchase. Match order ID, status and payment reference to the database record. BTCPay and lnd stubs exercise the adapters with controlled responses.
- **Sensitivity.** For high-impact changes, mutate one disposable copy or mount at a time. Remove a guard, lock acquisition or identity component and verify the intended assertion fails. Distinguish setup errors, syntax errors and runner timeouts from that failure. See the [negative controls](../tests/manual/advisory-locks/controls.py).
- **Cost.** Cover input combinations in unit tests, persisted effects in MySQL and interactions in the browser. Share setup when cases need the same state. Measure intermittent delays and replace timing guesses with observable conditions. Before parallelizing, prove each worker owns its prefixes, options, sessions and temporary files. Repeat the entire suite for concurrency, intermittency or a specific repeatability requirement.
- **Reproducibility.** Use synthetic keys, simulated nodes, local ports and disposable databases. Cache images, plugins and Chromium as needed and record loaded versions. Validate the final ZIP in a fresh environment. Exercise minimum PHP and WordPress versions and different MySQL or MariaDB versions when their differences affect the change. Report the combinations covered.

## Reference commands

Run from the repository root. Use Bash for blocks containing Bash assignments, substitutions or subshells. In fish, invoke the scripts with `bash` and literal arguments.

### Unit tests, static checks and Compose

```bash
(cd src/trunk && composer install --no-interaction && ./vendor/bin/phpunit)
./scripts/check-docs-drift.sh
./scripts/check-i18n-conventions.sh
```

Use `./start` to prepare a disposable WordPress store with Compose. The ephemeral `release` service installs development dependencies independently of WordPress. The smoke test uses the running WordPress container and disables PHP functions per process.

```bash
docker compose run --rm release composer install --no-interaction
./scripts/schema-tests.sh
./scripts/smoke-minimal-host.sh
```

Pass additional PHPUnit arguments to `schema-tests.sh`, for example `--filter SchemaUpgradeTest`. The full suite exercises replay and concurrency. Check both test output and exit status. Use `docker-compose` if that is the installed Compose executable.

### Podman and Chromium

On this host use `localhost/paycrypto-plugin-release-tools:0.4.3`. An equivalent image must start WordPress Apache and include WP-CLI, Composer, GMP, GD and mysqli. The [lab runbook](../tests/manual/advisory-locks/README.md) covers setup and variables. Install development dependencies once before source suites.

```bash
podman run --rm -v "$PWD/src/trunk:/plugin" -w /plugin --entrypoint composer \
  localhost/paycrypto-plugin-release-tools:0.4.3 install --no-interaction
bash tests/manual/advisory-locks/run.sh --integration-only
bash tests/manual/advisory-locks/run.sh --parallel-suites
```

Integration-only mode runs MySQL tests. Parallel mode exercises two suites on the same database. Both run independently of Node, Chromium and ZIP files. For browser tests, the runner accepts `NODE_PATH` and searches the npm cache for Playwright 1.65.0. Install the matching browser if needed.

```bash
npx --yes --package=playwright@1.65.0 playwright install chromium
bash tests/manual/advisory-locks/run.sh --upgrade-only \
  --baseline-zip releases/paycrypto-me-for-woocommerce-0.6.0.zip \
  --candidate-zip releases/paycrypto-me-for-woocommerce-0.6.1.zip
```

Replace the example paths with the published baseline and exact candidate ZIPs. Upgrade-only mode exercises clean installation, upgrade and rollback. The full run also covers source unit tests, MySQL, smoke, activation, classic checkout, Blocks and multisite. For settings, run `node tests/manual/settings/browser.cjs "$QA_SITE_URL"` with Playwright available to Node, a local URL ending in a slash and disposable `QA_ADMIN` and `QA_PASSWORD` credentials.

### Hosts without GMP

The smoke test disables functions in isolated processes and exercises a positive QR control. Availability tests also require a process where `extension_loaded('gmp')` returns false. For the local image that enables GMP through an INI file, run the complementary cases as follows.

```bash
podman run --rm -v "$PWD/src/trunk:/plugin" -w /plugin --entrypoint bash \
  localhost/paycrypto-plugin-release-tools:0.4.3 -ec '
    qa_ini=$(mktemp -d)
    for ini in /usr/local/etc/php/conf.d/*.ini; do
      case "$ini" in *gmp.ini) continue ;; esac
      ln -s "$ini" "$qa_ini/"
    done
    export PHP_INI_SCAN_DIR="$qa_ini"
    php -r "exit(extension_loaded(\"gmp\") ? 1 : 0);"
    php vendor/bin/phpunit -c phpunit.xml.dist --filter "OnchainWithoutGmpTest|UnavailabilityNoticeScopeTest"
  '
```

To run smoke alone against a prepared Podman store, use `env QA_PODMAN_WORDPRESS=wordpress-container ./scripts/smoke-minimal-host.sh`, substituting the running container name. It needs active gateways, WooCommerce and the mounted source checkout. Use the lab stores to validate the packaged plugin.

## Audit evidence

Record baseline and candidate commits, local changes, versions, ZIP SHA-256, command and flags, WordPress/WooCommerce/PHP/MySQL/Chromium versions, scenario, test and assertion counts, reasons for skips and outcome. Identify the primary case, negative controls and diagnostics separately. For database or checkout changes, include the query or transition summary and the synthetic ID linking it to the browser. For releases, link clean installation, Plugin Check, SVN comparison and public download verification.

Keep full logs under `releases/` or in the job artifact. Share a reproducible summary and selected sanitized logs at a location reviewers can access. Remove passwords, cookies, tokens, headers, URL query parameters and customer data. Archive only the necessary binary evidence and member hashes. Record each pending or skipped check and its residual risk.

## Documentation maintenance

- Write maintained repository documentation in English, including headings, captions, example comments, QA instructions and release records. Preserve exact command output when quoting it and use the target language for translation catalog entries.
- Keep cross-cutting rules and routing in `AGENTS.md`, within 16 KiB measured in UTF-8 bytes. Put details in the guide that owns the subject. Create a guide when a distinct audience or contract warrants one.
- Use `[GUIDE]` for maintained references, `[PLAN — NOT STARTED]` for approved pending work and `[VALIDATION]` for branch evidence. After merge, mark the validation `[DONE]` and move the local copy to ignored `docs/archive/`. Use a commit permalink for durable evidence. List an archived path in the checker when a guide references it by path.
- Cite stable symbols and headings. When renaming a heading or moving a guide, search the repository for incoming links and verify the destination. Recover historical records with `git log --all -- <path>` and `git show <commit>:<path>`.
- Run `./scripts/check-docs-drift.sh` for the byte budget, simple relative links, cited paths, PHP references, hook table and selected invariant counts. Review language, heading anchors and procedure accuracy explicitly. Exercise changed commands. Keep each command in its owning guide and link to it elsewhere.
