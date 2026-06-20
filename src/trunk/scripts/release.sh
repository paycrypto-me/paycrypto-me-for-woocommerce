#!/usr/bin/env bash

set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSER_CONTAINER="${COMPOSER_CONTAINER:-composer}"
COMPOSER_WORKDIR="${COMPOSER_WORKDIR:-/var/www/html/wp-content/plugins/paycrypto-me-for-woocommerce}"

echo "[release] Installing production PHP dependencies via Docker container: ${COMPOSER_CONTAINER}"
docker exec "${COMPOSER_CONTAINER}" composer install \
  --working-dir="${COMPOSER_WORKDIR}" \
  --no-dev \
  --optimize-autoloader

echo "[release] Building frontend checkout assets"
cd "${PLUGIN_DIR}"
npm run build

echo "[release] Release build completed"
