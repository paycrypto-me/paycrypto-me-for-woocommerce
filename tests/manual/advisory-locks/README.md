# On-chain reservation and installer QA on shared MySQL

Run from the repository root with Python 3, Podman and a disposable MySQL 8.0 instance managed by the runner. Browser modes also need Node, Playwright 1.65.0 and matching Chromium. Building the historical fixture requires rsync, zip and unzip. Use a WordPress Apache image with the official entrypoint, WP-CLI, Composer, PHP 8.1+, mysqli, GMP and GD. This host uses `localhost/paycrypto-plugin-release-tools:0.4.3`. On another host, build from the repository Dockerfile, set `QA_WP_IMAGE` to the resulting image and start the Apache entrypoint as root.

## Validate a packaged release

1. Install the browser with `npx --yes --package=playwright@1.65.0 playwright install chromium`. The runner accepts `NODE_PATH` and also searches the user's npm cache.
2. Supply the published baseline ZIP and the exact release candidate ZIP. Optionally set `QA_PLUGIN_CACHE` to a directory containing reusable read-only `woocommerce/` and `plugin-check/` trees.
3. Run the package checks below, substituting the actual paths.

```bash
bash tests/manual/advisory-locks/run.sh --upgrade-only \
  --baseline-zip releases/paycrypto-me-for-woocommerce-0.6.0.zip \
  --candidate-zip releases/paycrypto-me-for-woocommerce-0.6.1.zip
```

The lab records the candidate SHA-256 with clean-install and upgrade evidence. Package-only mode uses the production ZIPs and Playwright. For the full run, install development dependencies with `composer install` in `src/trunk` and omit `--upgrade-only`. The runner regenerates the development classmap and adds source suites and browser checkout. See the [QA matrix](../../../docs/GUIDE-QA.md#verification-matrix).

## Concurrent first use of a wallet (issue #2)

Run `bash tests/manual/advisory-locks/run.sh --first-wallet-only` with development dependencies installed. The coordinator holds two distinct WP-CLI checkouts immediately before their wallet writes. An independent SQL connection verifies the initial state, shared wallet ID, index/address pairs and retry stability. A separate checkout exercises a real write failure and recovery. The runner saves the loaded source hashes, barrier events, order IDs and database snapshots in `first-wallet.json` under its printed evidence directory. Set `QA_FIRST_WALLET_REVERSE=1` to reverse the release order in a fresh run.

The integration-only mode includes this regression. The upgrade runner's simultaneous first purchases remain a historical package diagnostic.

## Reproduce the original pre-release investigation

The default fixture builder targets the pre-bump source with Composer manifests matching 0.6.0. On that checkout, install development dependencies, prepare the browser and place the official ZIP at `releases/paycrypto-me-for-woocommerce-0.6.0.zip`. Run:

```bash
bash tests/manual/advisory-locks/run.sh
python3 tests/manual/advisory-locks/controls.py
```

[package.sh](package.sh) checks the pinned baseline SHA-256 and compares both Composer manifests. It keeps the 0.6.0 production dependencies, assets and plugin header, overlays candidate PHP sources in `includes/` and the changelog, then rebuilds the production classmap. Identify the fixture by its digest. Run `bash tests/manual/advisory-locks/package.sh` to force a rebuild. For a bumped checkout, use explicit ZIPs as above.

## Execution and evidence

Each run creates a disposable network, MySQL server and WordPress installations. A and B use `wp_` on different databases. A and C use different prefixes on the same database. The fixture holds A's resource until the coordinator releases it. A customer completes classic and Blocks checkouts in the browser. A separate process checks the order row, derivation index, status and address. The runner then converts A to multisite, switches blogs and exercises a service constructed before the switch.

A fourth store retains its database across baseline → candidate → baseline. Its server stops for each package switch. Orders use the real gateways with local BTCPay and lnd REST node responses. The simultaneous first-use wallet diagnostic has a separate result from cases starting with an existing wallet.

Find results under `releases/advisory-locks/pcm-*/`. The concurrency summary appears after the browser and database assertions pass. The upgrade summary appears after rollback. Playwright network evidence records path, method, status and relative time, omitting query parameters, bodies, cookies and headers. Failure screenshots are for local diagnosis. The runner removes its containers, anonymous volumes and network on exit. For the three-run audit, run the full command three times sequentially and verify each exit status and both summaries.

Use `run.sh --parallel-suites` for the original parallel PHPUnit case. Two installations share a database with separate WordPress options and independent temporary table prefixes. Use `run.sh --integration-only` for integration alone. Both modes require development dependencies and run independently of Node, Chromium and ZIP files. For the four no-GMP unit cases, run PHPUnit with `--filter 'OnchainWithoutGmpTest|UnavailabilityNoticeScopeTest'` in PHP without GMP. Each of the three negative controls mutates a temporary copy mounted in a test container, requires the intended assertion failure and saves the output under `controls/`.
