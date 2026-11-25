#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../../.."
if ! node -e "process.exit(require('playwright/package.json').version === '1.65.0' ? 0 : 1)" 2>/dev/null; then
    for package in "${npm_config_cache:-$HOME/.npm}"/_npx/*/node_modules/playwright; do
        if [[ -d "$package" ]] && node -e "process.exit(require(process.argv[1]).version === '1.65.0' ? 0 : 1)" "$package/package.json"; then
            export NODE_PATH="${package%/playwright}${NODE_PATH:+:$NODE_PATH}"
            break
        fi
    done
fi
node -e "const assert=require('node:assert/strict');assert.equal(require('playwright/package.json').version,'1.65.0');require('playwright').chromium.launch().then(async browser=>{console.log('Chromium',browser.version());await browser.close()})"
podman run --rm -v "$PWD/src/trunk:/plugin" -w /plugin --entrypoint composer \
    "${QA_WP_IMAGE:-localhost/paycrypto-plugin-release-tools:0.4.3}" dump-autoload
if ! python3 - <<'CHECK'
from pathlib import Path
from zipfile import ZipFile
import sys
archive = Path('releases/advisory-locks/candidate.zip')
if not archive.is_file():
    sys.exit(1)
root = Path('src/trunk')
prefix = 'paycrypto-me-for-woocommerce/'
with ZipFile(archive) as package:
    expected = {'CHANGELOG.md', 'composer.json', 'composer.lock'}
    for path in (root / 'includes').rglob('*'):
        name = path.relative_to(root).as_posix()
        if path.is_file() and not name.startswith(('includes/blocks/js/', 'includes/blocks/scss/')):
            expected.add(name)
    actual = {name[len(prefix):] for name in package.namelist()
              if name.startswith(prefix + 'includes/') and not name.endswith('/')}
    if actual != {name for name in expected if name.startswith('includes/')}:
        sys.exit(1)
    sys.exit(0 if all(package.read(prefix + name) == (root / name).read_bytes() for name in expected) else 1)
CHECK
then
    bash tests/manual/advisory-locks/package.sh
fi
exec python3 tests/manual/advisory-locks/lab.py "$@"
