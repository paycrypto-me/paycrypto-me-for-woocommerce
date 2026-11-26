# [VALIDATION] Concurrent first use of a Bitcoin wallet

[Issue #2](https://github.com/paycrypto-me/paycrypto-me-for-woocommerce/issues/2) affects two different WooCommerce orders using the same previously unseen extended public key and network. The baseline was commit `9ea45b43a549b13d7ce2c9aa28660e2309e31ee9` (0.6.1). The candidate was the working tree based on that commit. Both ran from source on disposable WordPress 7.1.4 stores with WooCommerce 11.3.1, PHP 8.3.36 and MySQL 8.0.46.

| Production file loaded by WordPress | Baseline SHA-256 | Candidate SHA-256 |
|---|---|---|
| Bitcoin payment processor | `1ac460fd728e5108e66fe64b169e6be84c20ad923901f6a53e0e949fe49e16f0` | `102c33f3fecbfbbc240160cd79a1f3faf53428dfa6f02f6ca3ac3124d4602f8c` |
| On-chain DB statements service | `fcbd2c53552a2f37e5eeb2d3ff1c4e8dd1a0b46ce3b87401bdefaa9011b0ee5e` | `85d6b18ce0a34947a297ae0a4961ad0a2fd3a48370bbc90a6230f4f30c2a18ed` |

## Reproduction and persisted results

Use the [first-wallet runner](../tests/manual/advisory-locks/README.md#concurrent-first-use-of-a-wallet-issue-2). For a baseline comparison, copy the candidate's test runner and WP-CLI fixtures onto a clean baseline checkout with its development dependencies installed. Run the same command on both revisions. Each run writes a JSON report with the two connection IDs, gateway results and independent SQL snapshots.

- **Baseline** (`pcm-c28a23a4`). Connections 31 and 30 paused before writing the wallet for orders 11 and 12. Order 11 succeeded and reserved index 0. Order 12 failed with a duplicate on `unique_xpub_network`. The new wallet had one transaction and one reservation. Repeating the baseline in a separate checkout with the final test fixtures reproduced the same failure (`pcm-146d08d9`).
- **Candidate** (`pcm-7276098e`). Both workers reached the same barrier. The coordinator released order 12 before 11. Both returned success. The control wallet retained ID 1 and had no transactions or reservations. Independent reads linked the two orders to the new wallet as shown below.
- **Retries**. Both orders returned success from fresh processes. The IDs of the wallet and transactions, addresses, indexes and row counts matched the pre-retry snapshot.
- **Write failure**. A temporary trigger rejected the first write for a third wallet. Order 13 returned failure with a checkout message and the gateway logged the wallet persistence failure for order 13. The SQL snapshot remained identical and WooCommerce had no address or index metadata for that order. After removing the trigger, order 13 obtained the new wallet's index 0.

| Synthetic order | Transaction ID | Wallet | Index | Address in the vector, transaction and order meta |
|---|---:|---:|---:|---|
| 11 | 1 | 2 | 0 | `1Bu5Dxprxc1uajCcdVWDDKpUdatG1T7nPB` |
| 12 | 2 | 2 | 1 | `148DggsWCYd9mV5ZmM3qpSfAbNnzCNaQxc` |

## Additional verification

- The focused PHPUnit filter `PayCryptoMeDBStatementsServiceTest|BitcoinPaymentProcessorTest|ProcessorIntegrationTest` passed 35 tests and 97 assertions. The full unit run reported 474 tests, 1,173 assertions and four skips requiring PHP without GMP. The complementary no-GMP filter documented in the [QA guide](GUIDE-QA.md#hosts-without-gmp) reported 23 tests and 38 assertions, skipping the single GMP-present case covered by the full run.
- `QA_FIRST_WALLET_REVERSE=1 bash tests/manual/advisory-locks/run.sh --integration-only` completed the regression and passed 35 MySQL integration tests and 255 assertions. Repeating the same suite with the default release order on another fresh store (`pcm-5127c8e1`) also passed the regression and all 35 integration tests.
- Playwright 1.65.0 with Chromium 158.0.8127.18 submitted first purchases on two clean stores, each configured with vector 0. Classic checkout used classic order storage (order 13). Blocks used HPOS (order 12). Both showed the expected address at index 0. A separate WP-CLI process confirmed the payment method, pending status, address and reservation. The loaded source hashes matched the candidate in both stores (`pcm-d180940d`).
- `./scripts/check-docs-drift.sh` and `git diff --check` passed.
- An early Blocks run stopped on the login form before submitting a purchase. The username field had gained focus during credential entry. The fixture now assigns both fields together and omits screenshots on the login page. An early negative-control run also stopped in setup because it tried to update a missing debug setting. Inserting the setting enabled verification of the actual payment error log.
