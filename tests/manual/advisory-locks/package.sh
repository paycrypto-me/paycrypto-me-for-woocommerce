#!/usr/bin/env bash
# Build a QA artifact from the audited 0.6.0 distribution and the candidate sources.
set -euo pipefail
cd "$(dirname "$0")/../../.."
slug=paycrypto-me-for-woocommerce
base="releases/$slug-0.6.0.zip"
out="$PWD/releases/advisory-locks/candidate.zip"
stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT
echo "a9fc4e36cf9c8a13262b360bd674201d558524c00d6f43b845b35e163af55659  $base" | sha256sum -c -
unzip -q "$base" -d "$stage"
cmp "$stage/$slug/composer.lock" src/trunk/composer.lock
cmp "$stage/$slug/composer.json" src/trunk/composer.json
rsync -a --delete src/trunk/includes/ "$stage/$slug/includes/" --exclude=/blocks/js/ --exclude=/blocks/scss/
cp src/trunk/CHANGELOG.md "$stage/$slug/"
podman run --rm -v "$stage/$slug:/package" -w /package --entrypoint composer \
    "${QA_WP_IMAGE:-localhost/paycrypto-plugin-release-tools:0.4.3}" dump-autoload --no-dev --optimize
mkdir -p "$(dirname "$out")"
(cd "$stage" && zip -qr "$stage/candidate.zip" "$slug")
mv "$stage/candidate.zip" "$out"
sha256sum "$out" > "$out.sha256"
