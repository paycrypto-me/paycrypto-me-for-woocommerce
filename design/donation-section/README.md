# Donation section design study

Local visual proposal for the donation block at the bottom of both gateway settings pages. This is separate from WordPress.org directory branding. The owner-supplied replacement address is set in the gateway base class, and its bundled donation PNG uses the new branded QR. The approved compact card is integrated into the local runtime template and admin CSS/JS for release 0.4.3. This folder remains a standalone visual reference; no WordPress.org release has been published.

The proposed card adapts to light and dark surroundings. Light mode blends with standard WordPress admin; dark mode uses graphite `#080A0D` / `#101318`, warm light text and restrained brand gold. The QR and its full quiet zone always stay opaque white, without inversion or filters. A restrained gold inset, the approved faceted P inside the QR, the full selectable address, a copy button with inline feedback, and a secondary wallet link provide hierarchy without competing with Save changes. No animation, blocking alert, preset donation amount or automatic wallet opening.

The laboratory's **Preview theme** selector offers System, Light and Dark. System follows `prefers-color-scheme`, including live preference changes; explicit choices override it and are reflected in shareable `?theme=light` / `?theme=dark` URLs. This control is for design review, not an additional plugin setting. Production integration must follow the effective admin theme's explicit signal, when one exists, before considering the system preference. Standard WordPress admin color schemes do not necessarily mean a dark page; no third-party admin theme integration has been implemented or validated yet.

The compact revision uses 16 × 18 px desktop padding (14 px on mobile), an 18 px desktop column gap, tighter copy/address spacing, and no reserved height for empty copy feedback. QR display remains 128 px; typography and button hit areas retain their previous sizes. Copy feedback occupies space only after interaction.

Approved copy: **Help move the project forward** / **Finding the plugin useful? Your Bitcoin donation helps support ongoing development.** Keep **PayCrypto.Me · Open source**, **Copy address** and **Open wallet**. The invitation describes support for development without pressure or promising features in exchange. The same wording is translated in all seven bundled runtime locales.

`brand.svg` is an exact copy of the canonical V3 `symbol/symbol-square-dark.svg` from the branding repository. Do not redraw it. The QR is generated programmatically from the current `support_btc_address` in the gateway abstract class: the displayed address and BIP21 payload both come from the same exported JSON. New address changes must be explicitly supplied by the owner, validated and rendered again.

Current authorized mainnet donation address: `bc1qca6mftuav8al60ag7g2wrwv9dj4m35hlw94uum`. The renderer validates its Bech32 checksum, witness version and 20-byte program length before generating. Its address validation intentionally accepts only this mainnet SegWit-v0 address format; a different future format requires a deliberate validator update.

## Reproduce

```sh
python -m venv /tmp/paycrypto-donation-tools
/tmp/paycrypto-donation-tools/bin/pip install -r design/donation-section/requirements.txt
/tmp/paycrypto-donation-tools/bin/python design/donation-section/render.py
# Explicitly synchronize the bundled donation PNG after successful decode checks:
/tmp/paycrypto-donation-tools/bin/python design/donation-section/render.py --sync-runtime
python -m http.server 4327 --bind 127.0.0.1 --directory design/donation-section
```

Open `http://127.0.0.1:4327/preview.html`. The page fetches its local JSON, so use HTTP rather than opening it as a file. No remote requests or real payment is needed. Save changes is deliberately inactive in this illustrative preview.

Segno generates a standard QR at error correction H, with a **four-module quiet zone**, square graphite modules on opaque white, and a nine-module white center plate around the seven-module brand tile. The logo does not cover finder patterns. Error correction percentages do not by themselves guarantee a branded QR will decode; the renderer checks the actual result with the independent ZXing-C++ decoder at 95/110/120/128/144/160/240/256 px and native resolution. This includes the existing runtime card's 95/110 px sizes and the study's 128 px. It fails if the decoded string differs from the exact donation URI; `--sync-runtime` copies the PNG only after checks pass. These checks are not a guarantee for every camera/wallet; perform real-wallet camera review before shipping a final destination, without sending funds.

Historical screenshots in directory assets still depict the real 0.4.2 UI and its previous donation details. They are capture evidence, not active donation configuration; do not paint over them. Refresh those captures from a new real demonstration build when the runtime redesign is integrated. Do not modify historical Git/SVN release snapshots as part of a destination update.

Margin reference: [DENSO WAVE QR sizing guidance](https://www.qrcode.com/en/howto/code.html). The integration does not alter the order-payment `QrCodeService`, payment payloads or gateway network icons. Any production integration must preserve that boundary, keep the copy button from submitting settings, localize new settings copy, test copy success/failure and mobile layout, and synchronize the QR, visible address and wallet link from one authoritative donation address. Updating runtime UI requires a plugin release; uploading directory assets alone will not install it.
