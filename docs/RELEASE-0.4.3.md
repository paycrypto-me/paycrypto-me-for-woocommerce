# [VALIDATION] Release 0.4.3

Prepared November 15, 2026. The owner approved the directory artwork, gallery, compact donation card, both theme variants and copy, supplied the new destination, and camera-tested the QR. **WordPress.org publication remains pending the owner's final SVN command.** Git publication and a local fake-SVN rehearsal do not publish the plugin directory.

## Delivered behavior

- V3 faceted-P directory icon (SVG and raster fallbacks), graphite/gold standard and retina banners, and eight full-resolution real screenshots.
- Compact optional donation card in both gateway settings, translated into the seven bundled locales. It follows the actual admin surface; the QR keeps a white quiet zone. Copy feedback is inline, with a manual-selection fallback.
- Owner-supplied mainnet destination shared by displayed address, copy action, Bitcoin URI and branded QR. Order-payment QR generation, customer destinations, invoice handling and database schema are unchanged.
- Locked sodium_compat updated from 2.5.0 to patched 2.5.2 following a real Composer advisory; every other package version remains pinned. Remove the redundant WordPress Tested up to PHP header; retain it in readme.
- SVN preparation explicitly assigns artwork MIME properties with auto-props disabled.

## Validation completed

- Official release script: dry-run, full npm build, 437 unit tests / 1,094 assertions / four expected GMP-host skips, platform-pin audit, documentation drift and i18n conventions passed.
- Real MySQL integration: 23 tests / 174 assertions. Host-minimum smoke: five missing-extension/rendering checks passed.
- Seven PO/MO catalogs: complete translations and gettext format validation. Metadata refreshed after the version bump; JSON runtime catalogs retained.
- Real WordPress review of both gateway settings, desktop/mobile, clipboard success/denial, actual dark-background adaptation, and QR decoding against the exact owner-provided address.
- Final ZIP installed into a separate clean WordPress without a source bind mount, activated and reviewed. Plugin Check 2.2.0: **no errors or warnings**. The installed ZIP's two gateway screens also passed browser/copy/theme/QR checks.
- Composer audit after the targeted dependency update: no advisories. ZIP structure and all packaged runtime files/captions/catalogs matched the final source; compiled bundles and optimized autoloader present, development/test artifacts absent.
- Local fake SVN publication rehearsed the same script's full trunk/assets commit and server-side tag copy. Exported tag contents were byte-identical to the ZIP; SVG/PNG MIME properties checked.
- Real SVN working copy prepared from the approved ZIP with `--svn-prepare`; no remote commit or tag creation performed. Review contains only trunk/assets changes, no historical tag edits.

Artifact: `releases/paycrypto-me-for-woocommerce-0.4.3.zip` (ignored, local release output), SHA-256:

```text
5f878d1f35a7fd202e809bc69f1b47488857c852c60f0f5ecffe251d280d896f
```

## Owner handoff

The locally prepared `releases/publish-0.4.3.sh` validates the approved ZIP digest and runs the standard publisher in the existing local tools image, with an interactive terminal for SVN authentication. It performs no rebuild. From the repository root:

```sh
./releases/publish-0.4.3.sh
```

The helper and ZIP are local operational outputs, not tracked files in a fresh clone. The portable equivalent, with native SVN/rsync/unzip installed, is:

```sh
./scripts/release.sh -v 0.4.3 -s paycrypto-me-for-woocommerce \
  --no-build --no-tests --no-zip --no-docker --svn-publish
```

The publisher authenticates as `paycryptome` by default (`SVN_USER` can override it), commits trunk/assets and then copies the committed trunk to `tags/0.4.3`. Never put a password in command arguments or documentation. After the owner publishes, record the real SVN revision and confirm the official listing before using the landing-page `paycrypto-plugin-release` skill to present 0.4.3 as available. See [the canonical release guide](GUIDE-RELEASE.md).
