# [GUIDE] Database schema, locks and migrations

This guide covers DDL, `DbInstaller`, table and wallet locks, and payment tables. Use the [QA matrix](GUIDE-QA.md#verification-matrix) for execution commands and evidence.

## Tables and installation lifecycle

All four tables use the site's WordPress prefix. The activators define their names and `DbInstaller::tables()` exposes them.

| Table | Identity and data |
|---|---|
| `paycrypto_me_bitcoin_wallet_xpubkeys` | Wallet ID, xPub and network. |
| `paycrypto_me_bitcoin_derivation_indexes` | Composite primary key on derivation index and wallet ID. |
| `paycrypto_me_bitcoin_transactions_data` | Order, address, index and wallet. Fixed-address payments store `WALLET_ID_STATIC_ADDRESS` (0) in both references. A `LEFT JOIN` preserves these rows on read. |
| `paycrypto_me_lightning_invoices` | Order, node type, invoice ID, BOLT11, status, expiry and amount in sats. |

Activation forces a check and repair. `admin_init` and `upgrader_process_complete` handle upgrades. The `paycrypto_me_db_health_check` transient throttles missing-table checks. Checkout may precede the upgrade, so the payment path checks `DbInstaller::is_current()` before relying on new structure. Run `dbDelta()`, then imperative migrations. Store the version only after all postconditions hold. Inspect `paycrypto_me_db_activation_errors` and the `paycrypto_me_db_upgrade_retry` transient when testing failures, waiting and recovery.

`AdvisoryLockName` uses the selected database, operation, physical table and, for derivation, wallet ID. In `finally`, the caller releases the same name it acquired. A timeout indicates contention. Diagnose SQL errors and invalid identities separately. Cover shared tables, different prefixes and databases, independent wallets with the same numeric ID and blog switching. Drain workers before the [transition from 0.6.0](GUIDE-RELEASE.md#installation-and-derivation-lock-transition-after-060).

## Observed dbDelta behavior

| Case | Consequence |
|---|---|
| F1. Existing column nullability changes | An upgrade can leave the change pending with an empty `last_error`. Test nullability on new columns with existing rows separately. |
| F2. New column or type change | Can generate an `ALTER`. Verify the result and compatibility with existing data. |
| F3. Two columns on one line | The parser may ignore the second column. Use one definition per line and parser-compatible syntax. |
| F4. Removal, rename or backfill | Use an imperative migration. Preserve payment records. The version 2 migration removed only the legacy confirmation fields within its approved scope. |
| F5. Failed statement followed by a successful one | `wpdb::query()` clears the error before the next statement. `DbDeltaRunner` checks the final error and runs `dbDelta($sql, false)` to detect pending table, column or index creation. Inspect `SHOW COLUMNS` and `SHOW INDEX` for changes to type, default or nullability. |

Activators use `CREATE TABLE` without `IF NOT EXISTS` or foreign keys. `DbDeltaErrorVisibilityTest` demonstrates F5 on real MySQL. For a different engine, reproduce the discrepancy and test the structural postcondition before changing the classifier.

## Implement a schema change

1. Define the final structure, data to preserve, baseline version, behavior before the upgrade hook and rollback path. Discarding old operational state requires explicit scope. Include identities, settings and payment data in validation.
2. Update the activator and increment `DbInstaller::DB_VERSION` for a schema change. Order `DbSchemaMigrations` steps by target version. Each step checks whether work is due and verifies its result. Re-running after a partial failure must converge. Older code must preserve newer recorded versions. For large tables, measure DDL cost on representative data and document the maintenance window.
3. Generate `src/trunk/tests/schema/v<N>.sql` against a disposable database using the new code. Preserve published snapshots. The suite discovers snapshots automatically.
4. Exercise clean installation, replay of every snapshot, re-entry and recovery from partial failure. `SchemaUpgradeTest` compares column and index fingerprints independently of DDL order. Add a negative control for the intended column, type, index or postcondition. Cover existing rows and order retries when payment behavior depends on the schema.
5. For release, switch from the published ZIP to the final ZIP and exercise applicable rollback. Compare rows, options and metadata **before** retrying. Verify the retry reuses the payment reference and a new charge persists in the expected format. Record lasting contracts here and observable effects in the changelog.

From the repository root, with a disposable WordPress installation running in Docker Compose:

```bash
docker compose exec -T -w /var/www/html/wp-content/plugins/paycrypto-me-for-woocommerce \
  wordpress php tests/bin/dump-schema.php
./scripts/schema-tests.sh
```

For Podman, use the lab and integration commands in the [QA guide](GUIDE-QA.md). To generate a snapshot, run the same PHP script inside the disposable WordPress container with `src/trunk` mounted.
