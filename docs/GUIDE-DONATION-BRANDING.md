# [GUIDE] Donation branding and destination

The owner approved the compact donation card, both theme variants, the copy, and camera-tested the branded QR in November 2026. Runtime integration is prepared for 0.4.3; no WordPress.org publication is implied by this guide.

## Authoritative destination

`Abstract_WC_Gateway_PayCryptoMe::$support_btc_address` is the canonical Bitcoin mainnet donation address shared by the two gateway settings screens. Current owner-supplied value: `bc1qca6mftuav8al60ag7g2wrwv9dj4m35hlw94uum`. This is optional project support, never a merchant's order-payment destination.

The visible address, copy action and `bitcoin:` link use that property. The bundled static donation QR must encode the identical URI without an amount. Update the property and regenerate the PNG together; never edit just the displayed address. `esc_url()` explicitly allows the Bitcoin scheme for the wallet link. No external QR API is used.

From the repo root, with the development-only Python dependencies installed:

```sh
python -m venv /tmp/paycrypto-donation-tools
/tmp/paycrypto-donation-tools/bin/pip install -r design/donation-section/requirements.txt
/tmp/paycrypto-donation-tools/bin/python design/donation-section/render.py --sync-runtime
```

The renderer validates a mainnet Bech32 SegWit-v0 20-byte destination before generating; other future address formats need deliberate validator support. It decodes the actual branded result at nine sizes with ZXing-C++ and copies to `src/trunk/assets/wallet_address_qrcode.png` only after success. No transfer is needed to verify decoding. The image uses error correction H and an opaque white four-module quiet zone around graphite modules. The central P is the exact approved V3 dark square symbol, sourced in the standalone lab. Never invert the QR for a dark panel.

## Runtime presentation

- `donation_description()` renders the local admin donation template; the existing title-type field remains display-only and is not saved as a payment setting. Its custom title renderer avoids an empty generated heading.
- `src/trunk/templates/admin/donation.php` contains escaped markup and translated settings copy. Main copy: **Help move the project forward** / **Finding the plugin useful? Your Bitcoin donation helps support ongoing development.**
- The hand-written admin CSS/JS files in the runtime assets directory are their actual sources. The generated Blocks directory remains off-limits to direct edits.
- Card padding: 16 × 18 px desktop, 14 px mobile; QR display 128 px. Empty copy feedback reserves no height. The copy button has `type="button"`, uses inline live-region feedback, and selects the address for manual copying if Clipboard access is unavailable or denied. No blocking alert, automatic wallet opening or preset amount.
- Theme selection follows the closest opaque painted admin ancestor; standard WordPress remains light even when the OS is dark. Root/body/ancestor theme attribute changes and media preference changes trigger re-evaluation. The dark card uses graphite with warm light text and restrained gold. The laboratory's manual/System selector is not shipped as a plugin option. Third-party themes that invert the whole page with filters are outside the tested integration; do not promise universal admin-theme support.

## Verification and screenshots

Check both gateway settings screens in real WordPress, desktop/mobile, light/dark backgrounds, keyboard focus, clipboard success/denial, and unchanged Save settings behavior. Decode the displayed QR and compare the full destination against the visible address and wallet URI. Run the normal release gates, translation audit and Plugin Check on the final ZIP. Order-payment `QrCodeService`, invoice payloads and network icons are not changed by this redesign.

The standalone study remains under `design/donation-section/` for reproduction and comparison; it is not shipped in the plugin ZIP. The directory screenshot gallery must show real screens. Recapture changed settings pages from a local running build rather than retouching old addresses or branding in historical images. Historical release snapshots remain immutable.
