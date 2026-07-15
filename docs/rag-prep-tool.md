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

- 15 MB per file
- 20 MB total upload size per job
- 5 files per job
- 120000 extracted characters per Ghost pass

## Runtime Model

- browser installer
- protected dashboard
- cron worker for extraction and Ghost processing
- private status / health / maintenance URLs

## Operational Notes

- PDF extraction currently depends on `pdftotext`
- DOCX extraction uses `ZipArchive`
- text chunking is semantic and Ghost-driven, not local regex slicing
