# [GUIDE] WordPress.org directory branding

V3 banner/icon and screenshot selection shipped with 0.4.3 on November 15, 2026: SVN trunk/assets r3803918, tag r3803919. Public package contents matched the approved ZIP.

Canonical identity: `paycrypto-me/branding`, V3 C +8. Application sources and standalone instructions: [`platforms/wordpress-org/`](https://github.com/paycrypto-me/branding/tree/main/platforms/wordpress-org) on `main` after the authorized integration push. The local source is `../branding/platforms/wordpress-org/`; the vector icon is byte-identical to `symbol/symbol-square-dark.svg`. The banner contains the approved outlined wordmark and outlined supporting copy; fonts are not required for export. This application is newer than the immutable `v3.0.0` branding release.

| Local directory file | Use |
| --- | --- |
| `src/assets/banner-772x250.png` | Standard WordPress.org banner, 772 × 250 |
| `src/assets/banner-1544x500.png` | Retina banner, 1544 × 500; keep the standard banner too |
| `src/assets/icon.svg` | Scalable dark square faceted-P icon |
| `src/assets/icon-128x128.png` | Standard icon / SVG fallback, 128 × 128 |
| `src/assets/icon-256x256.png` | Retina icon / SVG fallback, 256 × 256 |
| `src/assets/screenshot-1.png` through `src/assets/screenshot-8.png` | Real full-resolution screenshots, ordered to match the local readme |

Banner copy: **Bitcoin payments for WooCommerce** / **Direct to your wallet. No third-party custody.** Removed the old hand-and-Bitcoin logo and unsupported cryptocurrency artwork. Preserve the gold facets, internal Bitcoin mark, C +8 optical positioning, square icon padding, and exact aspect ratios.

From the branding checkout, verify first, then render/sync with its pinned Python dependencies:

```sh
python scripts/verify.py
python scripts/render-wordpress-assets.py --plugin-dir /path/to/paycrypto-me-for-woocommerce
```

Thirteen image files are synchronized. The local `src/trunk/readme.txt` Screenshots section is updated separately to match: Blocks, classic checkout, customer payment details, admin order, gateway list, on-chain settings, Lightning settings and mobile Blocks. The source is the landing page's real 0.4.2 showcase captured November 8, 2026 (WooCommerce 11.2.2, Twenty Twenty-Four 1.6 Onyx). Original WebPs, hashes, source commit and exact captions are included in the branding application's `screenshots.json` and screenshots folder. PNGs are lossless and pixel-identical to the source, with original dimensions and no quantization.

Dark storefront styling belongs to the demonstration theme/CSS, not a plugin dark-mode feature; admin stays standard WordPress. Fictional customer/product, pending testnet order and example Lightning credentials are intentional. No live Lightning connection or payment occurred. Existing old branding inside the released UI/product thumbnail remains as actually captured; update the demo and recapture to change it rather than retouching screenshots. Runtime order-payment icons and QR generation remain unchanged; the optional donation block is integrated separately as described in [GUIDE-DONATION-BRANDING.md](GUIDE-DONATION-BRANDING.md). Review the offline branding preview before publication.

Per the [official specifications](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/) (checked November 15, 2026), directory banners must be PNG/JPEG at exactly the named dimensions (maximum 4 MB); icons accept SVG plus a PNG fallback (maximum 1 MB). The outputs are optimized PNGs generated from vectors, optionally using pngquant at quality 95–100 if smaller.

For artwork-only publication, copy the thirteen image files into top-level SVN `assets/`, beside `trunk/` and `tags/`, flattening the screenshots folder. Review status and MIME properties, and commit only authorized paths. Artwork alone needs no version bump. The 0.4.3 release publishes the updated captions with its new stable-tag readme. For a future artwork-only update, captions still come from the readme parsed for the stable tag: do not assume publishing trunk or images alone will update that section. Inspect remote metadata and the project's immutable-tag convention before choosing an authorized metadata/release route; no SVN tag has been modified here. Do not invoke `release.sh --svn-publish` just to upload artwork: it also publishes software. Read [GUIDE-RELEASE.md](GUIDE-RELEASE.md) for SVN operational details. A Git push alone does not update WordPress.org. Record the approved Git commit and published SVN revision when publication actually occurs; allow for CDN caching.
