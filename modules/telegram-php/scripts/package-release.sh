#!/usr/bin/env bash

set -euo pipefail

VERSION="${1:-0.1.0}"
ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
MODULE_DIR="$ROOT_DIR/modules/telegram-php"
DIST_DIR="$ROOT_DIR/dist"
ZIP_NAME="mantis-bat-telegram-php-v${VERSION}.zip"
TMP_DIR="$DIST_DIR/package-tmp"

rm -rf "$TMP_DIR"
mkdir -p "$TMP_DIR" "$DIST_DIR"
cp -R "$MODULE_DIR" "$TMP_DIR/telegram-php"

find "$TMP_DIR" \
  -name ".DS_Store" -o \
  -name "__MACOSX" -o \
  -name ".env" -o \
  -name "config.php" -o \
  -name "*.sqlite" -o \
  -name "*.db" -o \
  -path "*/storage/logs/*" \
  | while read -r path; do
      rm -rf "$path"
    done

(cd "$TMP_DIR" && zip -rq "$DIST_DIR/$ZIP_NAME" telegram-php)
rm -rf "$TMP_DIR"

echo "Created $DIST_DIR/$ZIP_NAME"
