#!/usr/bin/env bash

set -euo pipefail

if [ "$#" -ge 1 ]; then VERSION="$1"; else VERSION="0.1.0"; fi
ROOT_DIR="$(cd "$(dirname "$0")/../../../.." && pwd)"
MODULE_DIR="$ROOT_DIR/tools/nextcloud-talk-connect/php"
DIST_DIR="$ROOT_DIR/dist"
PACKAGE_DIR="$DIST_DIR/package-tmp"
ZIP_NAME="mantis-bat-nextcloud-talk-connect-php-v$VERSION.zip"

rm -rf "$PACKAGE_DIR"
mkdir -p "$PACKAGE_DIR" "$DIST_DIR"
cp -R "$MODULE_DIR" "$PACKAGE_DIR/nextcloud-talk-connect-php"
find "$PACKAGE_DIR" \
  \( -name '.DS_Store' -o \
     -name '__MACOSX' -o \
     -name '.env' -o \
     -name 'config.php' -o \
     -name 'config.local.php' -o \
     -name '*.sqlite' -o \
     -name '*.sqlite3' -o \
     -name '*.sqlite-*' -o \
     -name '*.db' -o \
     -name '*.log' -o \
     -name '*.lock' \) \
  | while read -r path; do rm -rf "$path"; done
(cd "$PACKAGE_DIR" && zip -rq "$DIST_DIR/$ZIP_NAME" nextcloud-talk-connect-php)
rm -rf "$PACKAGE_DIR"
printf 'Created %s\n' "$DIST_DIR/$ZIP_NAME"
