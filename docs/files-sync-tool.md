<p><img src="../assets/logo/mantis-mini.svg" width="56" alt="Mantis Bat"></p>

# Files Sync Tool

Files Sync is a Mantis Bat guide page for syncing one Telmi OS Files space with a local macOS or Windows folder. It provides a guided walkthrough and downloadable client scripts. After the guide's one-time setup, the sync runs locally on the user's computer.

## What it does

- Uses the Ghost API v2 Files surface to read, download, create, and replace files.
- Recurses through folders and mirrors relative paths.
- Offers a dry-run preview, pull-only and push-only transfer modes, and two-way sync.
- Preserves local conflict copies when the remote version wins.
- Preserves remote IDs when it can identify file and folder moves.
- Propagates file deletions for previously synchronized files: if the server copy is unchanged, a local deletion archives and verifies its bytes before `file_delete`; if it changed, server wins and is restored. Server deletions remove tracked local copies without an archive, even when edited.
- Does not propagate empty-folder deletions.
- Stops on an empty or potentially incomplete inventory instead of assuming that missing files are safe to upload.

The implementation is a pair of local clients, not a web-server sync service. No account sign-in, installer page, database, cron worker, or remote-control endpoint is part of the guide module.

## Clients and full guides

- [macOS client](../tools/files-sync/php/public/downloads/telmi-files-sync.sh.txt) — rename to `.sh` after downloading. Requires Bash 3.2+, Python 3, and curl.
- [macOS technical guide](../tools/files-sync/php/public/downloads/telmi-files-sync-macos-guide.md)
- [Windows client](../tools/files-sync/php/public/downloads/telmi-files-sync-windows.ps1.txt) — rename to `.ps1` after downloading. Uses built-in Windows PowerShell 5.1.
- [Windows technical guide](../tools/files-sync/php/public/downloads/telmi-files-sync-windows-guide.md)

The module page is [tools/files-sync/php/README.md](../tools/files-sync/php/README.md). It documents the Hades PHP-public layout, optional display config, image slots, and release packaging.

## Required configuration and safety

Each local client needs the Telmi OS HTTPS origin, one Files-space ID, a Ghost JWT with read/write access to that space, and a private local sync directory. The script is configured locally or through its supported process environment variables. Do not put credentials into this repository or the guide page.

Run `--dry-run --verbose` first and inspect its inventory and action plan, especially any `ARCHIVE+DELETE-REMOTE` or `DELETE-LOCAL` entries. Start with `--pull --verbose`, review the downloaded files and note that it applies server deletions to tracked local files without an archive. Then test uploads, replacements, moves and both deletion directions with disposable content before enabling unattended two-way sync. Local deletions create persistent, hidden archives under `.telmi-deleted/`; protect these files as they may contain sensitive data. Keep `.telmi-sync-state.json` with the local directory; it records object IDs, moves, hashes, tombstones and pending delete operations.

The scripts cap local-to-server deletions per run and refuse to delete the final remote file. Inventory completeness, API or filesystem uncertainty causes a hard stop. The run is not transactional: transfers completed before a later error are not rolled back, and the API does not provide atomic preconditions for `file_delete`, `file_update` or `folder_update`.
