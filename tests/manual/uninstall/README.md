# Uninstall browser test

Use an isolated Podman container with WordPress, an active WooCommerce installation, WP-CLI, the default native file logger and a localhost site URL. The web server and WP-CLI user (`www-data`) need write access to the installed plugins and uploads directories. The test creates orders and deletes the installed plugin four times. Use a disposable database and volumes. The repository's development Compose file bind-mounts the source tree and is unsuitable.

Set `PAYCRYPTOME_DISPOSABLE_UNINSTALL_QA` to the boolean `true` in the disposable site's wp-config.php. Provide the site's administrator login via `QA_ADMIN` and `QA_PASSWORD`. With Playwright and its Chromium browser available to Node, run from the repository root:

```sh
node tests/manual/uninstall/browser.cjs "$QA_CONTAINER" \
  releases/paycrypto-me-for-woocommerce-0.4.3.zip \
  releases/paycrypto-me-for-woocommerce-0.5.0.zip
```

Run the matrix with classic order storage and with HPOS (with and without compatibility synchronization). It upgrades a populated 0.4.3 installation, saves and revokes consent across gateway screens, deactivates and deletes through the Plugins page, and checks orders, extension metadata, all four tables, settings, the schema version, current/rotated files and database logs. The last case also deactivates WooCommerce during deletion.
