#!/usr/bin/env bash
# Packages the installable PrestaShop module zip.
# Folder root inside the zip == PS technical name == "trusteed"
# (must match $this->name in trusteed.php for Module::getInstanceByName to work).
# vendor/ is intentionally excluded: the module ships a PSR-4 fallback
# autoloader (see trusteed.php) that resolves Trusteed\* classes directly
# from src/ when vendor/autoload.php is absent, avoiding a stale-classmap
# class of bug entirely.
# Usage: bash bin/build-zip.sh
set -euo pipefail

MODULE_SLUG="trusteed"
REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION=$(grep "\$this->version" "${REPO_DIR}/trusteed.php" | head -1 | sed "s/.*'\([0-9.]*\)'.*/\1/")
OUTPUT_DIR="${REPO_DIR}/dist"
OUTPUT="${OUTPUT_DIR}/trusteed-agentic-commerce-prestashop-${VERSION}.zip"

echo "==> Empaquetando ${MODULE_SLUG} v${VERSION}"

TEMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TEMP_DIR}"' EXIT
STAGE="${TEMP_DIR}/${MODULE_SLUG}"
mkdir -p "${STAGE}"

# Archivos/carpetas de runtime que necesita el módulo instalado.
cp "${REPO_DIR}/trusteed.php"              "${STAGE}/"
cp "${REPO_DIR}/post-install.php"          "${STAGE}/"
cp "${REPO_DIR}/agentic-tools-catalog.json" "${STAGE}/"
cp "${REPO_DIR}/composer.json"             "${STAGE}/"
cp "${REPO_DIR}/README.md"                 "${STAGE}/"
cp "${REPO_DIR}"/README.*.md               "${STAGE}/" 2>/dev/null || true
cp "${REPO_DIR}/LICENSE"                   "${STAGE}/" 2>/dev/null || true
cp -r "${REPO_DIR}/src/"                   "${STAGE}/src/"
cp -r "${REPO_DIR}/controllers/"           "${STAGE}/controllers/"
cp -r "${REPO_DIR}/override/"              "${STAGE}/override/"
cp -r "${REPO_DIR}/views/"                 "${STAGE}/views/"

# Quitar de la copia lo que no debe distribuirse.
find "${STAGE}" -name "*.test.*" -delete
find "${STAGE}" -name ".gitignore" -delete
find "${STAGE}" -name ".DS_Store" -delete
find "${STAGE}" -name ".phpunit.cache" -type d -prune -exec rm -rf {} +

mkdir -p "${OUTPUT_DIR}"
rm -f "${OUTPUT}"
( cd "${TEMP_DIR}" && zip -rq "${OUTPUT}" "${MODULE_SLUG}" )

echo ""
echo "==> Paquete generado:"
echo "    ${OUTPUT}"
echo "    $(du -h "${OUTPUT}" | cut -f1)"
echo ""
echo "==> Estructura (carpeta raíz == technical name del módulo):"
unzip -l "${OUTPUT}" | awk '{print $4}' | grep -E "^${MODULE_SLUG}/[^/]*$" | head -20
echo ""
echo "==> Instalar: Back Office > Modules > Module Manager > Upload a module"
