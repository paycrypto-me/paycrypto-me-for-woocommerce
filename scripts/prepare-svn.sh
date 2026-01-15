#!/usr/bin/env bash
set -euo pipefail

# Script para preparar uma pasta plugin-svn/ pronta para commitar ao WordPress SVN.
# Usa `source/trunk` como base (conforme solicitado).

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
SRC_DIR="$ROOT_DIR/source/trunk"
OUT_DIR="$ROOT_DIR/plugin-svn"

echo "Preparando plugin-svn em: $OUT_DIR"

# Limpa destino
rm -rf "$OUT_DIR"
mkdir -p "$OUT_DIR/trunk" "$OUT_DIR/tags" "$OUT_DIR/assets"

echo "Copiando arquivos do plugin (excluindo dev/artifacts não desejados)..."
# Excluir node_modules, .git, vendor (opcional) e testes; inclua vendor se quiser.
rsync -av --delete \
  --exclude 'node_modules' \
  --exclude '.git' \
  --exclude '.github' \
  --exclude 'tests' \
  --exclude 'composer.lock' \
  --exclude 'vendor' \
  "$SRC_DIR/" "$OUT_DIR/trunk/"

echo "Copiando assets públicos (se existirem)..."
# Copia assets do source/trunk/assets se houver
if [ -d "$SRC_DIR/assets" ]; then
  rsync -av "$SRC_DIR/assets/" "$OUT_DIR/assets/"
fi

# Copia banner do diretório artifacts, se existir
if [ -f "$ROOT_DIR/artifacts/paycrypto-me-banner-1544x500" ]; then
  cp "$ROOT_DIR/artifacts/paycrypto-me-banner-1544x500" "$OUT_DIR/assets/banner-1544x500.png" || true
fi

# Copia releases/ para plugin-svn/releases e tenta criar tags a partir de zips/dirs
if [ -d "$ROOT_DIR/releases" ]; then
  echo "Copiando releases/ para $OUT_DIR/releases/"
  mkdir -p "$OUT_DIR/releases"
  rsync -av --delete "$ROOT_DIR/releases/" "$OUT_DIR/releases/"

  for f in "$ROOT_DIR/releases"/*; do
    [ -e "$f" ] || continue
    filename=$(basename -- "$f")
    # Se for diretório e nome contiver versão (ex: plugin-1.2.3), tente extrair versão
    if [ -d "$f" ]; then
      if [[ $filename =~ ([0-9]+\.[0-9]+\.[0-9]+) ]]; then
        version="${BASH_REMATCH[1]}"
        echo "Criando tag $version a partir do diretório $filename"
        mkdir -p "$OUT_DIR/tags/$version"
        rsync -av --delete "$f/" "$OUT_DIR/tags/$version/"
      fi
    else
      # Se for um zip com versão no nome, requer unzip para extrair em tags/<version>
      if [[ $filename =~ ([0-9]+\.[0-9]+\.[0-9]+) ]] && [[ $filename == *.zip ]]; then
        version="${BASH_REMATCH[1]}"
        if command -v unzip >/dev/null 2>&1; then
          echo "Extraindo $filename para tags/$version (via unzip)"
          mkdir -p "$OUT_DIR/tags/$version"
          unzip -q "$f" -d "$OUT_DIR/tags/$version"
        else
          echo "unzip não encontrado — copiando zip para releases/ (não foi possível criar tag $version)"
        fi
      fi
    fi
  done
fi

echo "Estrutura criada com sucesso. Revise $OUT_DIR antes de commitar."
echo "Lembre-se: assets/ (top-level) do SVN é onde o WordPress.org lê banners e ícones." 

echo "Para commitar após aprovação SVN execute (exemplo):"
echo "  svn checkout https://plugins.svn.wordpress.org/<plugin-slug>/ plugin-svn-svn"
echo "  cp -R plugin-svn/* plugin-svn-svn/"
echo "  cd plugin-svn-svn && svn add --force * --auto-props --parents --depth infinity -q && svn commit -m 'Initial commit'"
