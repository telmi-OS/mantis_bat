<p><img src="../assets/logo/mantis-mini.svg" width="56" alt="Mantis Bat"></p>

# Self-Hosting RAG Prep PHP

The RAG prep tool is a normal PHP app.

## Requirements

- PHP `8.1+`
- `cURL`
- `PDO_SQLite`
- `ZipArchive`
- `fileinfo`
- `mbstring`
- `pdftotext`
- HTTPS
- writable `storage/`
- cron access, either CLI cron or URL cron

## Expected App Shape

Upload this tool directory:

```text
tools/rag-prep/php/
```

Expose only this directory to the web:

```text
tools/rag-prep/php/public/
```

That is the whole public app surface.

## Public Entry Points

- `install.php`
- `index.php`
- `download.php`
- `cron.php`
- `status.php`
- `health.php`
- `maintenance.php`

## Runtime Files

The installer creates the live runtime inside:

```text
tools/rag-prep/php/storage/
```

Important runtime files and directories:

- `config.php`
- `mantis_bat.sqlite`
- `mantis_bat.log`
- `installed.lock`
- `jobs/`
- `uploads/`

Keep that folder private.

## Operational Pages

After install, the tool gives you private operational URLs for:

- app access
- cron
- status
- health
- maintenance

Treat those URLs like credentials.

## Processing Model

- users create upload jobs in the browser
- cron extracts source text locally
- cron sends the normalized text to Ghost API v2
- Ghost returns the final RAG-ready plain-text artifact
- users download the resulting `.txt` file from the dashboard

## Maintenance

The maintenance page can:

- reset all jobs and artifacts while keeping the runtime config
- factory-reset the entire tool
