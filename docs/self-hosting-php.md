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
modules/telegram-php/public/
```

Practical shared-hosting reality:

Some users will upload the whole module into `public_html`. The connector therefore needs both:

- protective `.htaccess` defaults where supported
- runtime behavior that never exposes secrets intentionally

## Runtime Config File

The current connector runtime reads:

```text
modules/telegram-php/storage/config.php
```

This file is created by the installer.

Important:

- the running connector does not load `.env` directly in `v0.1.0`
- [modules/telegram-php/.env.example](/Users/tomschaal/Documents/Github/mantis_bat/modules/telegram-php/.env.example) is a reference sheet for the values you will be asked for
- the real live values end up in `storage/config.php`
