#!/usr/bin/env bash
# Empaqueta el módulo Trusteed para instalar en PrestaShop (Back Office > Módulos > Subir).
# Genera un zip con la carpeta raíz "trusteed/" y el archivo principal "trusteed.php"
# (PrestaShop exige carpeta == $this->name == archivo principal == nombre de clase).
# Excluye vendor/, tests/, docs/, screenshots/ y artefactos de desarrollo.
# Uso: bash bin/package-module.sh [version]

set -euo pipefail

MODULE_NAME="trusteed"
REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUTPUT_DIR="${REPO_DIR}/dist"

# Versión: argumento o leída de config.xml
VERSION="${1:-}"
if [ -z "${VERSION}" ]; then
  VERSION=$(grep -oE '<version><!\[CDATA\[[0-9.]+' "${REPO_DIR}/config.xml" | grep -oE '[0-9.]+$')
fi
PACKAGE_NAME="trusteed-agentic-commerce-prestashop-${VERSION}.zip"

echo "==> Empaquetando ${MODULE_NAME} v${VERSION}"

# Coherencia de versión entre config.xml y trusteed.php
PHP_VERSION=$(grep -oE "this->version[[:space:]]*=[[:space:]]*'[0-9.]+'" "${REPO_DIR}/trusteed.php" | grep -oE "[0-9.]+'" | tr -d "'")
if [ -n "${PHP_VERSION}" ] && [ "${PHP_VERSION}" != "${VERSION}" ]; then
  echo "ERROR: version en trusteed.php (${PHP_VERSION}) != ${VERSION}. Sincroniza ambos."
  exit 1
fi

STAGING_DIR="$(mktemp -d)"
trap 'rm -rf "${STAGING_DIR}"' EXIT
MODULE_STAGE="${STAGING_DIR}/${MODULE_NAME}"
mkdir -p "${MODULE_STAGE}"

# Copiar solo runtime + README, respetando exclusiones.
rsync -a \
  --exclude '.git/' \
  --exclude '.gitignore' \
  --exclude 'bin/' \
  --exclude 'dist/' \
  --exclude 'docs/' \
  --exclude 'screenshots/' \
  --exclude 'tests/' \
  --exclude 'vendor/' \
  --exclude 'node_modules/' \
  --exclude '.phpunit.cache/' \
  --exclude '*.zip' \
  --exclude '.DS_Store' \
  --exclude 'composer.lock' \
  "${REPO_DIR}/" "${MODULE_STAGE}/"

# index.php anti-listing en cada carpeta (convención de seguridad PrestaShop).
INDEX_GUARD="${MODULE_STAGE}/index.php"
cat > "${INDEX_GUARD}" <<'PHP'
<?php
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Location: ../');
exit;
PHP
while IFS= read -r -d '' dir; do
  [ -e "${dir}/index.php" ] || cp "${INDEX_GUARD}" "${dir}/index.php"
done < <(find "${MODULE_STAGE}" -type d -print0)

mkdir -p "${OUTPUT_DIR}"
PACKAGE_PATH="${OUTPUT_DIR}/${PACKAGE_NAME}"
rm -f "${PACKAGE_PATH}"

( cd "${STAGING_DIR}" && zip -rq "${PACKAGE_PATH}" "${MODULE_NAME}" )

echo ""
echo "==> Paquete generado:"
echo "    ${PACKAGE_PATH}"
echo "    $(du -h "${PACKAGE_PATH}" | cut -f1)"
echo ""
echo "==> Estructura (carpeta raíz == nombre del módulo):"
unzip -l "${PACKAGE_PATH}" | awk '{print $4}' | grep -E "^${MODULE_NAME}/[^/]*$" | head
echo ""
echo "==> Instalar: Back Office > Módulos > Subir un módulo > seleccionar el zip"
