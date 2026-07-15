<p><img src="../../../assets/logo/mantis-mini.svg" width="64" alt="Mantis Bat"></p>

# Mantis Bat RAG Prep PHP Tool

The RAG prep PHP tool is the first public utility app in the official Teleport AI `Mantis Bat` framework for `telmi OS`.

It lets a user upload TXT, PDF, and DOCX source files, extract the text locally, and ask a Ghost to produce one telmi OS-ready plain-text memory artifact.

## Requirements

- PHP 8.1+
- cURL
- SQLite
- ZipArchive
- fileinfo
- mbstring
- `pdftotext`
- HTTPS

## What It Does

- provides a protected browser dashboard
- accepts TXT, PDF, and DOCX uploads
- stores upload jobs locally
- extracts source text locally
- sends the normalized source text plus fixed RAG guidance to Ghost API v2
- saves one final `.txt` artifact per job
- exposes private cron, status, health, and maintenance pages

## Processing Model

- upload one or more files into a job
- cron extracts the raw text locally
- the tool normalizes the text mechanically
- Ghost performs the semantic chunking
- the final output is saved as one `.txt` file

## Output Contract

- one plain-text file
- chunks separated by exactly one empty line
- no JSON
- no markdown fences
- no wrapper text

## Limits In v0.1.0

- 15 MB per file
- 20 MB total per job
- 5 files per job
- 120000 extracted characters per Ghost pass

## Security

- expose only `public/`
- keep `storage/` private
- keep Ghost JWT private
- keep dashboard, cron, health, status, and maintenance URLs private
- validate file types and sizes before processing
