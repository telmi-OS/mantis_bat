<p><img src="../assets/logo/mantis-mini.svg" width="56" alt="Mantis Bat"></p>

# Self-Hosting RAG Prep PHP

The RAG prep tool is a normal PHP app.

## Requirements

- PHP `8.1+`
- `cURL`
- `PDO_SQLite`
- `ZipArchive`
- `DOMDocument`
- `fileinfo`
- `mbstring`
- `iconv`
- `zlib`
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

The dashboard is protected by a private bearer URL rather than a traditional password form:

```text
index.php?key=YOUR_PRIVATE_ACCESS_TOKEN
```

Opening the valid URL grants a PHP session. Anyone who obtains the URL can upload documents and download completed artifacts, so keep it private. The cron, status, health, and maintenance URLs use private operational secrets; the maintenance URL uses the configured status secret.

## Processing Model

- users create upload jobs in the browser
- cron extracts source text locally
- cron sends the normalized text to Ghost API v2
- Ghost returns the final RAG-ready plain-text artifact
- users download the resulting `.txt` file from the dashboard

The tool supports text-based PDFs only. Encrypted PDFs are rejected, and scanned/image-only PDFs require OCR, which is not included. Each job is limited to 7 MiB per file and 7 MiB combined. The browser request only creates the job; one later cron call processes one job.

Ghost requests use a 5-second connection timeout and a 20-second total timeout. This stays below Hades' 30-second PHP execution limit and the existing approximately 45-second cron-caller budget. The worker never sleeps while waiting for a retry.

Transient failures are retried up to three total attempts: the first attempt is immediate, the second is scheduled after 1 minute, and the third after 5 minutes. `attempt_count`, `next_attempt_at`, and `last_error` are persisted in SQLite. Only network timeouts, connection failures, HTTP 429, and HTTP 5xx responses are retryable. Invalid files, authentication failures, and other permanent 4xx responses are not retried; after the third transient failure the job is marked failed.

The bundled PDF parser is `smalot/pdfparser` v2.12.5, pinned under `src/ThirdParty/Smalot/PdfParser/`; Composer is not executed at runtime.

GD is intentionally not required. The tool does not use `shell_exec`, `exec`, `system`, `proc_open`, `popen`, `pcntl_*`, `pdftotext`, runtime Composer execution, or runtime OS package installation. `iconv` and `zlib` are expected to be available from the PHP base image.

## Hades Compatibility Check

From the tool directory, run:

```bash
php \
  -d disable_functions=exec,shell_exec,system,passthru,popen,proc_open,proc_get_status,proc_terminate,pcntl_exec,pcntl_fork,dl \
  public/install.php
```

The repository CI workflow also runs this command and the extraction/retry compatibility tests on PHP 8.1 and a current PHP version.

## Maintenance

The maintenance page can:

- reset all jobs and artifacts while keeping the runtime config
- factory-reset the entire tool
