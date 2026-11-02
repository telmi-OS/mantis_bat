<p><img src="../assets/logo/mantis-mini.svg" width="56" alt="Mantis Bat"></p>

# Files Sync Tool

Files Sync is a Mantis Bat guide page for syncing one Telmi OS Files space with a local macOS or Windows folder. It provides a guided walkthrough and downloadable client scripts. After the guide's one-time setup, the sync runs locally on the user's computer.

## What it does

- Uses the Ghost API v2 Files surface to read, download, create, and replace files.
- Recurses through folders and mirrors relative paths.
- Offers a dry-run preview, pull-only and push-only transfer modes, and two-way sync.
- Preserves local conflict copies when the remote version wins.
- Never propagates file or folder deletions.
- Stops on an empty or potentially incomplete inventory instead of assuming that missing files are safe to upload.

The implementation is a pair of local clients, not a web-server sync service. No account sign-in, installer page, database, cron worker, or remote-control endpoint is part of the guide module.

## Clients and full guides

- [macOS client](../tools/files-sync/php/public/downloads/telmi-files-sync.sh.txt) — rename to `.sh` after downloading. Requires Bash 3.2+, Python 3, and curl.
- [macOS technical guide](../tools/files-sync/php/public/downloads/TELMI-FILES-SYNC-MACOS.md)
- [Windows client](../tools/files-sync/php/public/downloads/telmi-files-sync-windows.ps1.txt) — rename to `.ps1` after downloading. Uses built-in Windows PowerShell 5.1.
- [Windows technical guide](../tools/files-sync/php/public/downloads/telmi-files-sync-windows-guide.md)

The module page is [tools/files-sync/php/README.md](../tools/files-sync/php/README.md). It documents the Hades PHP-public layout, optional display config, image slots, and release packaging.

## Required configuration and safety

Each local client needs the Telmi OS HTTPS origin, one Files-space ID, a Ghost JWT with read/write access to that space, and a private local sync directory. The script is configured locally or through its supported process environment variables. Do not put credentials into this repository or the guide page.

Run `--dry-run --verbose` first and inspect its inventory and action plan. Start with `--pull --verbose`, review the downloaded files, then test uploads and replacements using disposable content before enabling unattended two-way sync. Keep `.telmi-sync-state.json` with the local directory; it records sync history and prevents some deleted remote files from being re-uploaded as new local files.
