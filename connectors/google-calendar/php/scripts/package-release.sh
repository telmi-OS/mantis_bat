#!/usr/bin/env bash

set -euo pipefail

VERSION="${1:-0.1.0}"
ROOT_DIR="$(cd "$(dirname "$0")/../../../.." && pwd)"
MODULE_DIR="$ROOT_DIR/connectors/google-calendar/php"
DIST_DIR="$ROOT_DIR/dist"
ZIP_NAME="mantis-bat-google-calendar-php-v${VERSION}.zip"
TMP_DIR="$DIST_DIR/package-tmp"

rm -rf "$TMP_DIR"
mkdir -p "$TMP_DIR" "$DIST_DIR"
cp -R "$MODULE_DIR" "$TMP_DIR/google-calendar-php"

find "$TMP_DIR" \
  -name ".DS_Store" -o \
  -name "__MACOSX" -o \
  -name "config.php" -o \
  -name "*.sqlite" -o \
  -name "*.sqlite-*" -o \
  -name "*.db" -o \
  -name "*.key" -o \
  -name "*.lock" \
  | while read -r path; do
      rm -rf "$path"
    done

(cd "$TMP_DIR" && zip -rq "$DIST_DIR/$ZIP_NAME" google-calendar-php)
rm -rf "$TMP_DIR"

echo "Created $DIST_DIR/$ZIP_NAME"
