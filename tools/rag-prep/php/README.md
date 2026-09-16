<p><img src="../../../assets/logo/mantis-mini.svg" width="64" alt="Mantis Bat"></p>

# Mantis Bat RAG Prep PHP Tool

The RAG prep PHP tool is the first public utility app in the official Teleport AI `Mantis Bat` framework for `telmi OS`.

It lets a user upload TXT, PDF, and DOCX source files, extract the text locally, and ask a Ghost to produce one telmi OS-ready plain-text memory artifact.

## Requirements

- PHP 8.1+
- cURL
- SQLite
- ZipArchive
- DOMDocument
- fileinfo
- mbstring
- iconv
- zlib
- HTTPS

The PDF parser is bundled at a pinned version (`smalot/pdfparser` v2.12.5) with its LGPL-3.0 license and source metadata in `src/ThirdParty/Smalot/PdfParser/`. Runtime Composer execution is not required.
GD is intentionally not required. `iconv` and `zlib` are expected to be supplied by the PHP base image.

The tool has no runtime dependency on `shell_exec`, `exec`, `system`, `proc_open`, `popen`, `pcntl_*`, `pdftotext`, Composer execution, or OS package installation.

## What It Does

- provides a protected browser dashboard
- accepts TXT, PDF, and DOCX uploads
- stores upload jobs locally
- extracts source text locally
- extracts text-based PDFs with the bundled pure-PHP parser
- rejects encrypted PDFs and reports that scanned/image-only PDFs require OCR
- sends the normalized source text plus fixed RAG guidance to Ghost API v2
- saves one final `.txt` artifact per job
- exposes private cron, status, health, and maintenance pages

## Processing Model

- upload one or more files into a job
- the browser request only creates the job; one cron call processes one job
- cron extracts the raw text locally
- the tool normalizes the text mechanically
- Ghost performs the semantic chunking
- the final output is saved as one `.txt` file

The dashboard is not a username-and-password login. The installer generates a private bearer URL such as `public/index.php?key=...`; opening it grants a PHP session. Anyone with that URL can use the dashboard, upload files, and download completed artifacts.

## Output Contract

- one plain-text file
- chunks separated by exactly one empty line
- no JSON
- no markdown fences
- no wrapper text

## Limits In v0.1.0

- 7 MiB per file
- 7 MiB total per job
- 5 files per job
- 120000 extracted characters per Ghost pass
- 3 total processing attempts; transient Ghost failures retry after 1 minute and 5 minutes
- 5-second Ghost connection timeout and 20-second total Ghost request timeout

Retry state is persisted in SQLite as `attempt_count`, `next_attempt_at`, and `last_error`. Only network timeouts, connection failures, HTTP 429, and HTTP 5xx responses are retried. Invalid files, authentication failures, and other permanent 4xx responses fail immediately; no retry uses `sleep()` inside the cron request.

## Security

- expose only `public/`
- keep `storage/` private
- keep Ghost JWT private
- keep dashboard, cron, health, status, and maintenance URLs private
- validate file types and sizes before processing

## Hades Compatibility Test

From this directory:

```bash
php \
  -d disable_functions=exec,shell_exec,system,passthru,popen,proc_open,proc_get_status,proc_terminate,pcntl_exec,pcntl_fork,dl \
  public/install.php
```

The CI test additionally verifies TXT, DOCX, text-based PDF, encrypted-PDF, OCR-required PDF, retry, and forbidden-process-call behavior.
