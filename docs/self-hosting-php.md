<p><img src="../assets/logo/mantis-mini.svg" width="56" alt="Mantis Bat"></p>

# Self-Hosting PHP

The Telegram connector is now a normal PHP app.

## Requirements

- PHP `8.1+`
- `cURL`
- `PDO_SQLite`
- HTTPS
- writable `storage/`
- cron access, either CLI cron or URL cron

## Expected App Shape

Upload this connector directory:

```text
connectors/telegram/php/
```

Expose only this directory to the web:

```text
connectors/telegram/php/public/
```

That is the whole public app surface.

## Public Entry Points

- `install.php`
- `webhook.php`
- `cron.php`
- `status.php`
- `health.php`
- `pairing.php`
- `maintenance.php`

## Runtime Files

The installer creates the live runtime inside:

```text
connectors/telegram/php/storage/
```

Important runtime files:

- `config.php`
- `mantis_bat.sqlite`
- `mantis_bat.log`
- `installed.lock`

Keep that folder private.

## Config Reality

- the running connector reads `storage/config.php`
- the installer creates that file
- `.env.example` is only a reference sheet
- the connector does not load `.env` directly at runtime in `v0.1.0`

## Operational Pages

After install, the connector gives you private operational URLs for:

- cron
- status
- health
- pairing recovery
- maintenance

Treat those URLs like credentials.
