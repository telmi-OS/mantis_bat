<p><img src="../assets/logo/mantis-mini.svg" width="56" alt="Mantis Bat"></p>

# Self-Hosting PHP

The first public Mantis Bat module is designed for cheap PHP hosting.

## Requirements

- PHP 8.1+
- cURL extension
- SQLite extension
- writable storage directory
- HTTPS
- cron or URL cron access

## Hosting Shape

Preferred public web root:

```text
connectors/telegram/php/public/
```

Practical shared-hosting reality:

Some users will upload the whole module into `public_html`. The connector therefore needs both:

- protective `.htaccess` defaults where supported
- runtime behavior that never exposes secrets intentionally

## If You Cannot Point The Web Root To `public/`

This is not the preferred setup, but the module is hardened for the common shared-hosting fallback where the whole folder is uploaded under a public directory.

In that case:

- use only URLs inside `public/`
- do not browse `src/`, `storage/`, `templates/`, or `scripts/`
- the bundled `.htaccess` files should block direct access to those paths on Apache-compatible hosting

### Required Manual Checks

After upload, test these in the browser:

```text
https://example.com/mantis-bat/storage/config.php
https://example.com/mantis-bat/storage/mantis_bat.sqlite
https://example.com/mantis-bat/src/Config.php
https://example.com/mantis-bat/templates/install.html.php
https://example.com/mantis-bat/scripts/package-release.sh
```

All of them should fail with `403`, `404`, or an equivalent blocked response.

If any of them downloads, renders, or exposes file contents, stop and fix hosting before using the connector.

## Runtime Config File

The current connector runtime reads:

```text
connectors/telegram/php/storage/config.php
```

This file is created by the installer.

Important:

- the running connector does not load `.env` directly in `v0.1.0`
- [connectors/telegram/php/.env.example](/Users/tomschaal/Documents/Github/mantis_bat/connectors/telegram/php/.env.example) is a reference sheet for the values you will be asked for
- the real live values end up in `storage/config.php`
