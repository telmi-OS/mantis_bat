# Telmi Files Sync for macOS

**Two-way, recursive, fail-closed synchronization between a local macOS directory and one Telmi OS Files space.**

This guide describes the current Bash/Python client. It is independent of any particular customer, group, workspace name, host, or space ID. Each installation must supply its own configuration.

> **Important:** The script may ship with installation-specific defaults. Review `TELMI_BASE_URL`, `TELMI_SPACE_ID`, `SYNC_DIR`, and `TELMI_GHOST_JWT` before running or scheduling it. This document does not change the script's configuration.

## 1. Behavior

| Situation | Action |
| --- | --- |
| New file on server | Download it to the corresponding local path. |
| Existing server file changed; local copy unchanged | Download the server version. |
| New local file | Create it remotely with `upload`. |
| Existing local file changed; server unchanged | Update the same remote file using `file_replace`. |
| Both copies changed since the recorded state | **Server wins.** Preserve a local conflict copy, then download the server version. |
| Same path exists on both sides, content differs, and there is no prior state | **Server wins.** Preserve the local file as a conflict copy, then download. |
| File deleted locally, but still exists remotely | Download it again (except in `--push` mode). |
| Previously synchronized file deleted remotely | Keep the local copy, log the deletion, and **do not re-upload it**. |
| New remote folder (even empty) | Create the corresponding local directory. |
| New local folder containing files | Create the necessary remote directories when uploading those files. |
| New local folder containing no files | Not uploaded; the client synchronizes files, not standalone empty local directories. |

**No deletion operations are sent to the API.** Existing file contents can still be replaced during synchronization. File renames and moves are *not* tracked as rename operations: they can appear as a disappearance at one path and a new file at another path.

### Conflict copies

When both sides changed, the local divergent version is saved beside the original using a name similar to:

```text
report.md.conflict-20261101T164500-12345-a1b2c3d4
```

These `.conflict-` files are excluded from uploads. The server version is written to `report.md` only after the downloaded SHA-256 has been verified.

### Server-wins does not mean silent overwriting

For an existing remote file, the client uses:

```http
POST /api/ghost/v2/files.php?action=file_replace
Content-Type: multipart/form-data

file_id=<existing-file-id>
expected_sha256=<current-remote-sha256>
file=<new-content>
```

`file_replace` updates the same remote file record and preserves its `file_id` and associated shares. If the server changes between inspection and replacement, an HTTP **409** causes an immediate hard stop. The client does not retry the mutation or fall back to create-only `upload`. On a subsequent run, the latest server state is read and the normal server-wins comparison applies.

For a **new** local file, the client uses `upload` (create-only), then verifies the resulting remote filename and SHA-256. The client also checks that replacements retain their `file_id`.

## 2. Requirements

- macOS, with the system `/bin/bash` (Bash 3.2 is supported).
- Python 3 available as `python3`.
- `curl` available on the command path.
- Network access to a Telmi OS deployment over HTTPS.
- A valid Ghost JWT with access to the selected Files space, including write permission for uploads/replacements.

No Homebrew Bash, Docker, background daemon, third-party Python packages, or `shasum` utility is required. SHA-256 hashing is performed by Python.

Check locally:

```bash
command -v python3
python3 --version
command -v curl
```

If Python 3 is missing, install an appropriate Python distribution (for example, using Apple's Command Line Tools where available) and ensure that the scheduled job can find it.

## 3. Configuration and installation

Save the supplied script outside directories automatically synchronized by other services, for example:

```text
~/bin/telmi-files-sync.sh
```

Review the configuration section near the top of the script:

| Setting | Purpose |
| --- | --- |
| `TELMI_GHOST_JWT` | Credential identifying the Ghost; required. |
| `TELMI_BASE_URL` | HTTPS origin of the Telmi OS deployment, with no trailing slash. |
| `TELMI_SPACE_ID` | ID of the one Files space to synchronize. |
| `SYNC_DIR` | Local root directory for that space. |
| `STATE_FILE` | State filename within `SYNC_DIR` (default `.telmi-sync-state.json`). |
| `LOCK_DIR` | Process lock directory (default `~/.telmi-files-sync.lock`). |
| `LOCK_STALE_MINUTES` | Minimum age before a *dead-owner* lock can be recovered (default 360). |
| `HTTP_RETRIES` / `HTTP_RETRY_SLEEP` | Retry count and delay for eligible read requests only. |
| `LIST_PAGE_SIZE` | Listing limit; default 500, maximum 500. |
| `MAX_FOLDER_DEPTH` | Maximum nested remote folder depth (default 32). |
| `CURL_CONNECT_TIMEOUT`, `CURL_READ_TIMEOUT`, `CURL_TRANSFER_TIMEOUT` | Network timeouts in seconds. |

Every setting can also be supplied through environment variables; those override the script's configured defaults. For example, in an **interactive Terminal session**:

```bash
export TELMI_BASE_URL="https://your-telmi-host.example"
export TELMI_SPACE_ID="YOUR_SPACE_ID"
export SYNC_DIR="$HOME/TelmiSync/MySpace"
export TELMI_GHOST_JWT="YOUR_GHOST_JWT"
```

Protect the script, particularly if it contains a JWT:

```bash
mkdir -p "$HOME/bin"
chmod 700 "$HOME/bin/telmi-files-sync.sh"
bash -n "$HOME/bin/telmi-files-sync.sh"
```

**Do not put a JWT in Git, documentation, screenshots, or logs.** Consider a dedicated Ghost credential with only the permissions needed for the chosen space. Avoid putting confidential synced files in a publicly shared or automatically cloud-synced local folder.

**Important for scheduling:** `launchd` and `cron` do **not** automatically inherit variables exported only in an interactive Terminal. If your manual run relies on `export TELMI_GHOST_JWT=...`, the scheduled run needs its *own* noninteractive credential configuration. For the supplied script as-is, the simplest option is to configure its JWT and space settings directly in the protected script. A separate secure credential-loading mechanism can be added later. Do not put the JWT in a world-readable plist.

## 4. Command-line usage

All commands and options are unchanged:

```bash
bash ~/bin/telmi-files-sync.sh --dry-run --verbose  # Plan only, verbose API/listing logs
bash ~/bin/telmi-files-sync.sh --dry-run            # Plan only
bash ~/bin/telmi-files-sync.sh                      # Full two-way sync
bash ~/bin/telmi-files-sync.sh --pull               # Remote -> local only
bash ~/bin/telmi-files-sync.sh --push               # Local -> remote only
bash ~/bin/telmi-files-sync.sh --verbose            # Full sync with detailed logs
```

`--pull` and `--push` are mutually exclusive. `--dry-run` can be combined with either mode.

`--dry-run` does not modify the server, local synchronized files, or sync state. It still performs actual API **reads** and uses a temporary work directory and short-lived process lock.

`--pull` and `--push` are one-way **transfer modes**, not offline modes: both still retrieve and validate the full remote inventory before making changes. A real run may update the local state file even in a one-way mode.

## 5. How the remote inventory works

The Ghost API v2 `list` action returns the **immediate contents of one folder**, not an entire recursive tree. The client therefore:

1. Lists the root of the chosen space.
2. Walks discovered subfolders breadth-first, listing each folder once.
3. Validates folder IDs, file IDs, names, paths, parents, and SHA-256 values.
4. Rejects repeated IDs, unsafe paths, case/Unicode path collisions, and excessive folder depth.
5. Builds a complete remote file inventory and compares it to the local files and remembered sync state.
6. Executes planned transfers only after the whole inventory and local preflight have succeeded.

The current Files API does **not** expose `has_more`, `offset`, `cursor`, or a total count for this listing. The limit applies independently to the files and subfolders returned for each folder.

**Completeness rule:** If any individual folder listing returns **500 files or 500 subfolders** (or reaches a lower configured `LIST_PAGE_SIZE`), the client **aborts**. Without pagination metadata, it cannot prove that the result is complete. Do not interpret this as an empty or successful listing.

**Empty-server rule:** If the entire remote inventory contains **zero files**, the client unconditionally aborts, **even if remote folders exist**. At least one file must be returned by the API. This is an intentional deployment requirement, not an API error to work around. Seed a new space with a legitimate file before using this client.

## 6. Error and safety behavior

The client is intentionally **fail-closed**:

- Unsuccessful HTTP responses, invalid JSON, or API responses without `ok: true` stop the run with a nonzero exit code and timestamped `ERROR HARD STOP` log entry.
- Eligible HTTP GET failures (network errors, HTTP 408/429, and HTTP 5xx) receive bounded retries. If still unsuccessful, the run aborts.
- Mutating POST requests (`upload`, `file_replace`, `folder_create`) are **not retried automatically**. A timeout may occur after the server has already committed a change.
- HTTP 409 on `file_replace` stops the run; it never overwrites a newer remote version.
- Downloads are SHA-256-checked and staged before replacing local files.
- Uploads use a snapshot of the planned local bytes and are verified against a fresh remote listing after the API operation.
- A missing, incomplete, or ambiguous inventory is never treated as permission to upload every local file.
- The script refuses symlinks and unsafe or colliding paths in the sync tree.
- The lock is owned by the running process. A second run aborts rather than deleting its lock. Recovery requires a sufficiently old lock **and** a confirmed dead owner process.
- No API file-delete or folder-delete operations are used.

**Hard stop is not rollback.** If a later action fails after earlier files were successfully transferred, those completed actions are not reversed. The error is logged; the next run re-evaluates current state. This is a per-file sync client, not a single cross-system transaction.

**Ignored local files:** `.DS_Store`, the configured state file, files whose names start with `.telmi-`, and filenames containing `.conflict-` are not uploaded. Do not use those names for data you expect to sync. Other local symlinks and unsupported filesystem entries can still abort preflight.

## 7. Sync state — keep this file

The state file defaults to:

```text
$SYNC_DIR/.telmi-sync-state.json
```

It records the last accepted local and remote SHA-256 values per relative file path. It is updated after a verified transfer or state adoption and is written atomically.

**Do not delete it as a routine troubleshooting step.** Removing the state loses historical knowledge of which remote files were previously synchronized and later deleted. A local copy of such a file could then be interpreted as a brand-new local file and uploaded again. Back up and preserve the state file when relocating or recovering a sync directory.

Changing `TELMI_SPACE_ID` or `SYNC_DIR` should be treated as a **new synchronization pairing**. Use a separate local directory and state file for each remote space. The default lock is shared; using the same script configuration concurrently for multiple spaces requires separate lock directories.

## 8. Expected dry-run output

Example only; counts and paths depend on the selected space and local state:

```text
2026-11-01T16:50:17+01:00 telmi-files-sync start mode=both dry_run=1 space=<space-id>
2026-11-01T16:50:18+01:00 HTTP GET https://your-telmi-host.example/api/ghost/v2/files.php?... -> 200 (attempt 1/3)
2026-11-01T16:50:18+01:00 LIST /: 2 folders, 1 files
2026-11-01T16:50:19+01:00 LIST Projects: 1 folders, 2 files
2026-11-01T16:50:20+01:00 LIST Notes: 0 folders, 1 files
2026-11-01T16:50:21+01:00 LIST Projects/Reports: 0 folders, 3 files
2026-11-01T16:50:21+01:00 Remote inventory validated: 7 files, 4 folders
2026-11-01T16:50:21+01:00 Plan: 7 actions, 0 previously-synced remote deletions
2026-11-01T16:50:21+01:00 DOWNLOAD Projects/Reports/summary.md (dry-run)
...
2026-11-01T16:50:21+01:00 telmi-files-sync done: folders=4 downloads=7 uploads=0 conflicts=0 skipped=0 errors=0
```

Checklist: confirm that nested folders are listed, remote files have their full relative paths, the inventory count is nonzero, the proposed actions make sense, and the summary says `errors=0`. During a first run into an empty local directory, expect downloads but no uploads. `folders=` includes the space root.

Possible action labels include `DOWNLOAD`, `UPLOAD`, `REPLACE`, `ADOPT`, `MKDIR`, `MKDIR-REMOTE`, `CONFLICT-SERVER-WINS`, and `SKIP`. `ADOPT` means matching content was accepted into the sync state without transferring file bytes.

## 9. Recommended initial rollout

1. Verify the script path, JWT, HTTPS base URL, space ID, local directory, and chosen log location.
2. Run `--dry-run --verbose` and inspect the complete plan.
3. If the local folder has important existing files, back it up before the first real run: **server wins**.
4. Run `--pull --verbose` once to verify actual download, checksums, and state creation without making remote changes.
5. Run another `--dry-run --verbose`; review any remaining upload or replacement actions.
6. Test new-file `UPLOAD` and existing-file `REPLACE` with **disposable** files before relying on unattended two-way sync.
7. Enable `launchd` only once the full run behaves as intended.

The dry run proves inventory discovery and planning, **not** actual download, upload, or replacement behavior.

## 10. Scheduling with launchd (recommended)

The supplied client is compatible with a normal **user LaunchAgent**. The existing setup using `/bin/bash`, `ProgramArguments`, `StartInterval=900`, and log paths is valid. `StartInterval=900` means roughly every 15 minutes; missed runs during sleep are coalesced into one wake-up trigger, not replayed one by one.

**Use absolute paths in the plist.** Replace every `YOUR_USERNAME` placeholder with the actual local macOS account short name. The target script filename must match the file that really exists. The label below is a generic product-level identifier, not a group or customer name.

Save as:

```text
~/Library/LaunchAgents/com.telmi.files-sync.plist
```

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>Label</key>
  <string>com.telmi.files-sync</string>

  <key>ProgramArguments</key>
  <array>
    <string>/bin/bash</string>
    <string>/Users/YOUR_USERNAME/bin/telmi-files-sync.sh</string>
  </array>

  <key>EnvironmentVariables</key>
  <dict>
    <key>PATH</key>
    <string>/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin</string>
  </dict>

  <key>StartInterval</key>
  <integer>900</integer>

  <key>StandardOutPath</key>
  <string>/Users/YOUR_USERNAME/Library/Logs/telmi-files-sync.out.log</string>
  <key>StandardErrorPath</key>
  <string>/Users/YOUR_USERNAME/Library/Logs/telmi-files-sync.err.log</string>
</dict>
</plist>
```

The script writes its diagnostic messages to **stderr**, so the `.err.log` file is the main log. The `PATH` setting makes typical Homebrew and system Python locations available to the scheduled job; adjust it if your `python3` installation lives elsewhere. `RunAtLoad` is deliberately omitted so registering the job does not itself force an immediate sync. Add `<key>RunAtLoad</key><true/>` only if an immediate run at registration/login is desired.

**Credential note:** The plist above does **not** set `TELMI_GHOST_JWT` or any space configuration. Those must already be available noninteractively (for this unchanged script, normally via its protected configuration section). A value exported in Terminal for testing is insufficient for the LaunchAgent.

### Validate and enable an existing or new LaunchAgent

Execute these as the logged-in user, **without `sudo`**:

```bash
PLIST="$HOME/Library/LaunchAgents/com.telmi.files-sync.plist"
LABEL="gui/$(id -u)/com.telmi.files-sync"

# Before enabling: confirm that this is the real executable path.
ls -l "$HOME/bin/telmi-files-sync.sh"
bash -n "$HOME/bin/telmi-files-sync.sh"
plutil -lint "$PLIST"

# If the agent was already loaded under this label, unload it first.
# Do this only when no sync is actively running.
launchctl bootout "$LABEL" 2>/dev/null || true

# Load the new/updated plist into the current user's GUI session.
launchctl bootstrap "gui/$(id -u)" "$PLIST"
launchctl print "$LABEL"

# Trigger one run now, without killing an already-running job.
launchctl kickstart "$LABEL"

# Inspect the result and the diagnostic log.
launchctl print "$LABEL"
tail -n 100 "$HOME/Library/Logs/telmi-files-sync.err.log"
```

If you have already loaded a plist pointing at a **placeholder script filename**, do not merely rename the script and assume the new configuration is loaded. First make the `ProgramArguments` path correct, then `bootout`/`bootstrap` as above. If the plist itself did not change and the configured path already points at the intended filename, putting the actual script at that exact path is sufficient; check the loaded job with `launchctl print` before enabling real transfers.

To stop future scheduled runs:

```bash
launchctl bootout "gui/$(id -u)/com.telmi.files-sync"
```

To inspect the current registration:

```bash
launchctl print "gui/$(id -u)/com.telmi.files-sync"
```

A user LaunchAgent generally runs while that user is logged in; it is not a system-wide daemon. This guide uses `launchctl bootstrap` and `bootout` rather than the older `load`/`unload` syntax.

### Optional: cron instead of launchd

```cron
*/15 * * * * /bin/bash "$HOME/bin/telmi-files-sync.sh" >> "$HOME/Library/Logs/telmi-files-sync.log" 2>&1
```

As with `launchd`, scheduled executions require a noninteractive JWT and a usable `python3` path. Do not schedule the same pairing through **both** cron and launchd.

## 11. Troubleshooting

### `ERROR HARD STOP: remote inventory contains ZERO FILES`

The server returned no usable files in the full space. The script **must not** sync that space, even if it contains folders. Verify space ID, access permissions, and the actual contents; then place at least one legitimate file in the space if it is intentionally new.

### `truncated/ambiguous result` or listing at the configured limit

One folder reached the API's non-paginated listing limit. Sync stops to avoid an incomplete inventory. Reduce the number of immediate items in that folder or extend the server API with real pagination before syncing it.

### HTTP 401 / 403

Check the JWT and the Ghost's current access rights to the chosen space. Membership or permission changes can affect access even if the JWT was previously valid.

### HTTP 409 `file_changed`

The server changed between preflight and `file_replace`. The script aborts rather than overwriting the newer server file. Inspect the log and rerun; the subsequent comparison will follow server-wins rules.

### HTTP 5xx, timeout, invalid JSON, or `ok: false`

Read the timestamped hard-stop line. Eligible GET requests are retried before aborting; mutations are not retried automatically. Because a timed-out POST may have committed, inspect current server state before making manual changes.

### `Another sync holds lock` / lock cannot be acquired

Check whether a sync is already running. A lock is not automatically removed just because it is old: recovery requires evidence that its owning PID has exited. Remove a lock manually **only after confirming no instance is running**.

### Scheduled job works differently from Terminal

The LaunchAgent does not inherit your shell's exported JWT or shell initialization. Verify the final script path, `PATH`, `python3`, credential configuration, log locations, and `launchctl print` output. A plist whose `ProgramArguments` points to a placeholder or renamed script will not run the intended file.

### `Operation not permitted`

macOS privacy controls can restrict access to protected folders. Consider a dedicated sync directory outside Desktop/Documents/Downloads and iCloud Drive. Depending on how the script is launched, you may need to grant access to the responsible process. Check permissions and macOS privacy settings rather than disabling protections indiscriminately.

### Logs appear missing

Ensure the parent log directory exists and is writable, and check `StandardErrorPath`. With the example configuration, normal script diagnostics appear in:

```text
~/Library/Logs/telmi-files-sync.err.log
```

### A remote deletion was unexpectedly uploaded again

Check whether the state file was deleted, replaced, or associated with a different space. Without historical state, previously deleted remote paths cannot be reliably distinguished from truly new local files. Preserve the original state file when moving or restoring a pairing.

## 12. Files Sync guide

The Mantis Bat Files Sync page provides this client and a guided setup walkthrough. The page is a static guide; the actual synchronization runs on this Mac, either manually or through the local scheduler. It does **not** expose an HTTP endpoint or let a remote Ghost initiate a Mac-side sync.

Keep the API host, space ID, JWT, local directory, permissions, schedule, and lock scope configurable per pairing. Do not put live credentials or customer identifiers in distributed scripts or examples.

---

**Compatibility note:** This guide reflects the current fail-closed Bash/Python script and the Ghost API v2 Files contract available as of November 2026. It does not promise transactional rollback, automatic remote pagination, remote execution of the macOS client, or support for deletions.
