#!/usr/bin/env bash

set -euo pipefail

VERSION="${1:-0.1.0}"
ROOT_DIR="$(cd "$(dirname "$0")/../../../.." && pwd)"
MODULE_DIR="$ROOT_DIR/connectors/private-mail/php"
DIST_DIR="$ROOT_DIR/dist"
TMP_DIR="$DIST_DIR/package-tmp"
PACKAGE_DIR="$TMP_DIR/private-mail-php"
ZIP_PATH="$DIST_DIR/mantis-bat-private-mail-php-v${VERSION}.zip"

rm -rf "$TMP_DIR"
mkdir -p "$PACKAGE_DIR" "$DIST_DIR"
cp -R "$MODULE_DIR"/. "$PACKAGE_DIR"/
find "$PACKAGE_DIR/storage" -mindepth 1 -maxdepth 1 -type f \
  ! -name 'config.example.php' ! -name '.htaccess' ! -name '.gitkeep' -delete
find "$PACKAGE_DIR" \
  \( -name '.DS_Store' -o -name '__MACOSX' -o -name '.env' -o -name '*.sqlite' -o -name '*.sqlite-*' -o -name '*.db' -o -name '*.log' -o -name '*.lock' -o -name 'ghost-settings-*.json' \) \
  -exec rm -rf {} +
(cd "$TMP_DIR" && zip -rq "$ZIP_PATH" private-mail-php)
rm -rf "$TMP_DIR"

echo "Created $ZIP_PATH"
