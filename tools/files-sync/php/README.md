<p><img src="../../../assets/logo/mantis-mini.svg" width="64" alt="Mantis Bat"></p>

# Mantis Bat Files Sync Guide (PHP)

Files Sync is a guided, responsive page for pairing one Telmi OS Files space with a local folder on macOS or Windows. The page provides the two local sync scripts, a step-by-step walkthrough, and full technical guides. The sync itself runs on the user's computer through the Ghost API v2.

This module does not sync files on the web server, run a background worker, or allow a Ghost to execute commands on a user's computer. The guide has no sign-in or installer screen. It is available at the module's `public/index.php` after the module files are placed on Hades or a self-hosted PHP host.

## Files in this module

- `public/index.php` — guided flow with **Guided** and **Read all** views
- `public/downloads/telmi-files-sync.sh.txt` — macOS Bash/Python client; rename to `.sh` after downloading
- `public/downloads/telmi-files-sync-windows.ps1.txt` — Windows PowerShell client; rename to `.ps1` after downloading
- `public/downloads/TELMI-FILES-SYNC-MACOS.md` — full macOS reference
- `public/downloads/telmi-files-sync-windows-guide.md` — full Windows reference
- `src/Config.php` — optional display configuration adapter, with safe defaults
- `storage/config.example.php` — title, timezone, and optional start/end image paths

The client scripts are text-suffixed for safe HTTPS transfer. They contain generic placeholders, not a deployment URL, space ID, or credential. Each user configures a copy locally. A Ghost JWT is never sent to or stored by this web page.

## Requirements

- PHP 8.1 or newer for the guide page
- HTTPS for serving the page and downloading the client files
- macOS users: Bash 3.2+, Python 3, and curl
- Windows users: Windows PowerShell 5.1, included with Windows 10/11
- A Ghost JWT with read/write access to the chosen Files space

The page bundles its small navigation script locally and has no external JavaScript, API, database, cURL, SQLite, cron, or write-permission dependency. The Google Fonts link is optional and falls back to system fonts. The optional `storage/config.php` is not needed for the page to work.

## Hades and self-hosting layout

The module follows the Mantis Bat PHP-public layout: Mantis Bat stages `public/` as the Hades web root and keeps `src/` and `storage/` in the private instance directory. `src/bootstrap.php` resolves these paths relative to the module root. The catalog entrypoint is `index.php`; there is deliberately no `install.php` or application setup flow.

For a separate PHP host, expose only `public/` as the document root and keep `src/` and `storage/` outside the web root. The supplied access rules deny direct access to private source/configuration paths and dotfiles. No live configuration is required.

To use the optional image slots, add local image files at:

- `public/assets/images/files-sync-start.webp`
- `public/assets/images/files-sync-end.webp`

The defaults in `src/Config.php` pick them up automatically when present. A host may instead create `storage/config.php` from `storage/config.example.php` and set different local asset paths. The page uses its CSS artwork when these images are absent.

## Sync behavior shown in the guide

The clients recursively compare one remote Files space against one local directory. They download new or changed server files, upload new local files, and use `file_replace` to update an existing remote file. If both copies changed, **server wins** and the divergent local copy is retained beside the active file. The scripts do not call file-delete or folder-delete API actions. Preserve `.telmi-sync-state.json`; it tracks prior syncs and remote deletions.

Both clients fail closed on API, inventory, path, integrity, or transfer errors. The Files listing API has no pagination metadata, so a listing that reaches its configured limit is ambiguous and stops. The full remote inventory must contain at least one file. Mutating API calls are not automatically retried, and a hard stop does not roll back transfers that already completed earlier in the same run.

## Packaging

From the repository root, create a Hades-ready archive with:

```bash
tools/files-sync/php/scripts/package-release.sh 1.0.0
```

The archive includes the optional config example and both text-safe client downloads, but excludes any live `storage/config.php`.
