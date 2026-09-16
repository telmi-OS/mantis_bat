<p><img src="../assets/logo/mantis-mini.svg" width="56" alt="Mantis Bat"></p>

# RAG Prep Tool

The Mantis Bat RAG prep tool is a PHP app for turning uploaded source documents into telmi OS memory upload artifacts.

It is not a channel connector. It is a user-facing utility tool that sits next to the connector layer in the same Mantis Bat deployment model.

## Current Path

```text
tools/rag-prep/php/
```

## Supported Input

- `txt`
- `pdf`
- `docx`

## What It Does

1. accepts one or more uploaded source files
2. extracts text locally
3. normalizes the text mechanically
4. sends one full normalized source document to Ghost API v2
5. lets the Ghost produce retrieval-optimized chunk blocks
6. saves one final plain-text artifact for telmi OS memory upload

## Output Contract

The result is one `.txt` file:

- plain text only
- no JSON
- no markdown code fences
- no wrapper text
- one empty line between chunks

## Ghost Processing Goal

The Ghost is not asked to summarize for a human reader.

It is asked to produce self-contained semantic memory blocks that:

- retrieve well later
- preserve factual detail
- stand on their own
- stay within a practical size band for downstream RAG use

## Current Limits

- 7 MiB per file
- 7 MiB total upload size per job
- 5 files per job
- 120000 extracted characters per Ghost pass

## Runtime Model

- browser installer
- protected dashboard
- cron worker for extraction and Ghost processing
- private status / health / maintenance URLs

## Dashboard Access

The dashboard does not use a traditional username-and-password login. After setup, the installer displays an access URL in this form:

```text
index.php?key=YOUR_PRIVATE_ACCESS_TOKEN
```

The token is a bearer credential: possession of the URL grants dashboard access, including upload and completed-artifact download access. A successful token request creates a PHP session for the browser. Keep the URL out of browser history shared with others, referrers, access logs, screenshots, and chat messages where possible.

The cron, status, health, and maintenance pages are separate private operational URLs. Treat every generated URL as a credential. The maintenance page uses the configured status secret.

## Hades Runtime Contract

The tool is designed for PHP 8.1+ hosting with cURL, SQLite/PDO, ZipArchive, DOMDocument, mbstring, fileinfo, iconv, and zlib. HTTPS is required for Ghost API requests. GD is not required because OCR and image processing are outside this version's scope; iconv and zlib are expected to be supplied by the PHP base image.

The runtime must not depend on:

- `shell_exec`, `exec`, `system`, `proc_open`, `popen`, or `pcntl_*`
- `pdftotext` or any other external PDF process
- Composer execution at runtime
- OS package installation at runtime

PDF extraction is performed by the bundled, pinned pure-PHP parser. It supports text-based PDFs, explicitly rejects encrypted PDFs, and reports that scanned/image-only PDFs require OCR. OCR is not included and must not be implemented through a process wrapper.

## Execution Budget and Retry Policy

Each cron request processes one job only. The Ghost cURL request uses a 5-second connection timeout and a 20-second total request timeout, keeping the network call below Hades' 30-second PHP execution limit and the existing approximately 45-second cron-caller budget. The worker does not call `sleep()`; deferred work is picked up by a later cron call.

Transient failures are retried up to three total attempts:

- attempt 1: immediate
- attempt 2: after 1 minute
- attempt 3: after 5 minutes

The retry schedule is persisted in SQLite using `attempt_count`, `next_attempt_at`, and `last_error`. Retryable failures are network timeouts, connection failures, HTTP 429, and HTTP 5xx responses. Invalid files, authentication failures, and other permanent HTTP 4xx responses are failed without retry. After the third transient failure, the job is terminally failed while retaining its last error.

## Hades Compatibility Test

From `tools/rag-prep/php/`, run:

```bash
php \
  -d disable_functions=exec,shell_exec,system,passthru,popen,proc_open,proc_get_status,proc_terminate,pcntl_exec,pcntl_fork,dl \
  public/install.php
```

The CI compatibility test runs the installer with those restrictions, checks TXT, DOCX, text-based PDF, encrypted-PDF, and OCR-required PDF behavior, checks retry persistence, and scans PHP source for forbidden process-execution calls.

## Operational Notes

- PDF extraction uses a bundled, pinned pure-PHP parser for text-based PDFs
- encrypted PDFs are rejected explicitly
- scanned/image-only PDFs require OCR, which is outside the current runtime contract
- DOCX extraction uses `ZipArchive` and `DOMDocument`
- transient Ghost failures retry up to three total attempts with persisted backoff
- text chunking is semantic and Ghost-driven, not local regex slicing
