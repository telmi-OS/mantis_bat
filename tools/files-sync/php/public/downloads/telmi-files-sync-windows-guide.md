# Telmi Files Sync for Windows — v6 identity-aware sync

A single **Windows PowerShell 5.1** script synchronizes one local Windows folder with one selected **Telmi OS Files space** using the **Ghost API v2**. No Python, WSL, Git Bash, curl, PowerShell 7, package manager, administrative privileges, or additional PowerShell modules are needed on a normal Windows 10/11 installation.

This is a **local sync client**, not a cloud service or remotely controllable Windows agent. The client operates with the credentials and Files-space access of the configured Ghost.

> **Safety principle: SERVER WINS.** Local deletions propagate after a verified archive; server deletions remove tracked local copies directly, without an archive, including locally edited copies. The client requires at least **one remote file** in a validated, complete inventory before proceeding. Network, API, integrity, local filesystem, and state failures cause a logged **HARD STOP**.

## 1. Synchronization rules

| Situation | Behavior |
| --- | --- |
| New file on server | Download to the corresponding local path. |
| Existing server file changed | Download the new server version if the local version is unchanged. |
| New local file | Create remotely using `action=upload`. |
| Existing local file changed, server unchanged | Replace bytes remotely using `action=file_replace` with the existing `file_id` and `expected_sha256`. |
| Both local and server changed | **Server wins**. Preserve the divergent local bytes in a `.conflict-...` copy, then download the server version. |
| File deleted locally | Download and SHA-256 verify a hidden archive, journal, then `file_delete`; `--pull` restores it instead. |
| Local deletion while server contents changed | Server wins: restore the current server version (`--push` defers the download). |
| File deleted remotely | Delete the tracked local copy **without an archive** in default / `--pull`; `--push` retains tracking for a later pull. |
| Local file move / rename | `file_update`, preserving `file_id` and shares. |
| Local folder move / rename | `folder_update`, preserving the folder and child IDs. |
| Remote file / folder move | Follow the existing remote ID to its new local location. |
| Ambiguous move vs delete | Hard stop; never silently invent an identity. |
| Empty remote space (even if folders exist) | **HARD STOP**. At least one remote file must be returned by the API. |
| HTTP 409 on file replacement | **HARD STOP**; never overwrite the changed server version or fall back to create-only upload. |
| Any incomplete or unsafe inventory | **HARD STOP** before transfer operations begin. |

The sync is **recursive**. Remote folders are mirrored locally, and local subfolders containing new files can be created on the server. File deletion uses `file_delete`. Empty folder deletion is not propagated: deleting a server folder removes its tracked files locally, but empty local directories may remain. Empty remote folders may also remain after local deletion.

**Move identity:** The native Windows volume serial + file index identifies ordinary renames on the same filesystem. A move preserves the remote ID; it does not delete and re-upload the file. Copy-and-delete or cross-volume moves can lose that identity. If an unchanged copy makes a deletion ambiguous, the script stops. Keep a move and a content edit as separate sync steps when changing volumes. Hard links with multiple matching paths are rejected.

## 2. Requirements

- Windows 10 or Windows 11 with **Windows PowerShell 5.1** (the built-in `powershell.exe`).
- .NET Framework HTTP libraries included with Windows PowerShell 5.1.
- An HTTPS endpoint for a Telmi OS deployment, a valid Ghost JWT, and permission to read/write the selected Files space.
- A local folder on a normal Windows filesystem; **NTFS is recommended** for atomic file replacement.
- Internet/network access to the configured endpoint.

Verify the installed version in PowerShell:

```powershell
$PSVersionTable.PSVersion
```

The **major version should be 5**, or you may choose to run the same script with a newer PowerShell version if installed. The standard Windows instructions below deliberately use `powershell.exe`, not `pwsh.exe`.

## 3. Installation and configuration

1. Create a folder for the client, for example `%USERPROFILE%\bin`.
2. Save the supplied script there as `telmi-files-sync-windows.ps1`.
3. Open it in Notepad or another text editor.
4. Update the **CONFIGURATION** block at the beginning of the script, specifically:
   - `TELMI_BASE_URL`: the deployment's HTTPS **origin**, e.g. `https://YOUR-TELMI-HOST.example` (no `/api/...` suffix).
   - `TELMI_SPACE_ID`: the ID of the Files space to synchronize.
   - `TELMI_GHOST_JWT`: the Ghost's actual JWT.
   - `SYNC_DIR`: the destination folder; default is `%USERPROFILE%\TelmiSync\MySpace`.
5. Save the script in a folder that **only your Windows account (and trusted administrators)** can read. Do not publish it with a real JWT.

**For a quick Windows user setup, putting the credentials in the protected script is the simplest option.** The script also supports process-environment overrides for the same settings. An environment variable exported in an interactive PowerShell window is **not a reliable credential mechanism for a scheduled task**; configure the scheduled account's environment separately or keep the credential in the protected script.

### Settings reference

| Setting | Function | Default/notes |
| --- | --- | --- |
| `TELMI_BASE_URL` | HTTPS deployment origin | **Required**; placeholder must be replaced. |
| `TELMI_SPACE_ID` | One Files space | **Required**; placeholder must be replaced. |
| `TELMI_GHOST_JWT` | Ghost bearer token | **Required**; placeholder must be replaced. |
| `SYNC_DIR` | Windows sync folder | `%USERPROFILE%\TelmiSync\MySpace` |
| `STATE_FILE` | Local three-way comparison state | `.telmi-sync-state.json` within `SYNC_DIR` |
| `LOG_DIR` | Persistent diagnostic logs | `%LOCALAPPDATA%\TelmiFilesSync\Logs` |
| `LOCK_DIR` | Per-user OS lock files | `%LOCALAPPDATA%\TelmiFilesSync\Locks` |
| `HTTP_RETRIES` | Maximum attempts for eligible GETs | `3` |
| `HTTP_RETRY_SLEEP` | Delay between GET retries | `3` seconds |
| `HTTP_READ_TIMEOUT` | GET listing / JSON request timeout | `120` seconds |
| `HTTP_TRANSFER_TIMEOUT` | Upload / download timeout | `3600` seconds |
| `LIST_PAGE_SIZE` | Maximum requested items per folder | `500`; may be reduced, cannot exceed 500 |
| `MAX_LOCAL_DELETES_PER_RUN` | Maximum local-to-server file deletions in one run | `10` |
| `MAX_LOCAL_DELETE_PERCENT` | Percentage limit for local-to-server deletion batches | `25` (at least one permitted; final server file always protected) |
| `MAX_FOLDER_DEPTH` | Remote nesting cap | `32` |

Environment variables whose names match the table override script settings when present in the running process. Avoid using the **same `SYNC_DIR` with different spaces**; assign one directory/state pairing per space.

### Credential and local file security

The script authenticates as a Ghost. Use credentials with the minimum necessary access to the chosen space, protect the script and the local folder with Windows file permissions, and rotate exposed credentials. Be aware that files named `*secrets*`, internal reports and other confidential Files-space content will also be downloaded to the PC.

Do not put your script, state file or synchronized directory in a public Git repository, OneDrive/Dropbox-synced folder, shared PC profile, or other location where its contents are unintentionally exposed. Logs include relative filenames and space identifiers, **not the JWT**.

## 4. Running the script

Open **Windows PowerShell** (not necessarily as administrator). Run:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$env:USERPROFILE\bin\telmi-files-sync-windows.ps1" --dry-run --verbose
```

The command-line options match the macOS client:

```powershell
# Preview every planned action; no local sync-folder/server/state changes:
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$env:USERPROFILE\bin\telmi-files-sync-windows.ps1" --dry-run --verbose

# Full two-way sync:
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$env:USERPROFILE\bin\telmi-files-sync-windows.ps1"

# Server -> PC only:
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$env:USERPROFILE\bin\telmi-files-sync-windows.ps1" --pull --verbose

# PC -> server only:
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$env:USERPROFILE\bin\telmi-files-sync-windows.ps1" --push --verbose
```

`--pull` applies server deletions locally but never mutates the server. `--push` can archive/delete server files in response to local deletions, but does not apply server deletions locally. `--pull` and `--push` are mutually exclusive. `--dry-run` can be combined with either mode. Add `-ExecutionPolicy Bypass` **to the individual command** if Windows' execution policy would otherwise prevent the downloaded script from running. This does **not** change the machine's persistent execution policy. It does not override organizational security controls such as application allowlisting.

`--dry-run` still reads the remote API and acquires a short-lived Windows file lock. It creates diagnostic logs and a temporary work folder **outside** the sync directory, but it does not create/change synchronized files, remote content or the sync state.

## 5. Remote inventory: how completeness is enforced

The Ghost API v2 `action=list` only returns the **immediate children** of a folder. The Windows client walks the complete tree breadth-first, validates names and parent IDs, and constructs relative paths before planning any transfer.

The current public listing contract has **no pagination cursor, `offset`, `has_more`, or reliable total**. Each folder may return at most **500 immediate files and 500 immediate child folders**. To remain fail-safe, if either collection contains **500 items** (or hits the configured lower `LIST_PAGE_SIZE`), the run **aborts**. The script cannot prove an inventory is complete if the limit is reached.

A space with **zero remote files** is always rejected, even if remote folders exist. This intentionally prevents accidental bulk uploads caused by a missing or incomplete server inventory. Seed a legitimately empty space with a file **before** enabling sync.

The client also rejects duplicate IDs, Windows-invalid filenames, reserved Windows device names (`CON`, `NUL`, etc.), name collisions that differ only in case or Unicode normalization, mismatched parents, excessive nesting, symlinks, junctions and other reparse points in the local sync tree.

### Why Windows filenames may be stricter than macOS

Some filenames valid in Unix/macOS are invalid on Windows (such as names containing `:` or ending with a period). A Windows client **aborts rather than renaming or silently skipping** such files. Resolve those names in the Files space before syncing Windows clients.

## 6. HTTP operations and fail-safe behavior

| API action | Method | Purpose |
| --- | --- | --- |
| `list` | GET | Remote folder discovery and later verification. |
| `content` | GET | Stream file bytes to a local staging file. |
| `folder_create` | POST JSON | Create a missing remote folder for a local upload. |
| `upload` | POST multipart | Create a **new** remote file (`space_id`, optional `folder_id`, `file`). |
| `file_replace` | POST multipart | Update an existing file **without changing `file_id`** (`file_id`, `expected_sha256`, `file`). |
| `file_update` | POST JSON | Rename/move by `file_id`, `name`, nullable `folder_id`. |
| `folder_update` | POST JSON | Rename/move by `folder_id`, `name`, nullable `parent_id`. |
| `file_delete` | POST JSON | Delete by `file_id`, after verified archive + journal. |

- HTTPS is mandatory. Redirects are refused rather than following a URL with the bearer token.
- Successful JSON responses must contain `ok: true`; HTTP 200 alone is insufficient.
- Eligible **GET** network failures, HTTP 408/429 and HTTP 5xx are retried up to the configured maximum. A failed final attempt hard-stops.
- **POST requests are never retried automatically**. A timeout may occur after the server committed the operation. Inspect the server if an upload result is uncertain.
- **HTTP 409** on replacement hard-stops and never falls back to `upload`. Re-run later to apply server-wins conflict handling against a fresh inventory.
- Downloads are SHA-256 verified, the server file's ID/hash is rechecked, and bytes are staged before being atomically installed locally.
- Uploads use a verified local snapshot, check the remote path immediately before mutation, and verify the resulting server filename/hash and `file_id` after mutation. A replacement must keep its original `file_id`.
- Any unsafe, missing, ambiguous or malformed response causes an error and a nonzero process exit code.

**Hard stop is not rollback.** If the tenth transfer fails, the nine earlier successful transfers are not reversed. On the next run, the client reads the current server and state again.

## 7. State and deletions — do not erase the state file

The local state lives at:

```text
%USERPROFILE%\TelmiSync\MySpace\.telmi-sync-state.json
```

(Or inside your configured `SYNC_DIR`.) Manifest v2 stores `files` keyed by remote file ID and `folders` keyed by remote folder ID, with their paths and local Windows identities. It also stores `scope` (server URL + space), `tombstones`, and `pending_deletes`. Updates are flushed, staged and atomically replaced in the same local directory.

**Upgrade: keep the old JSON.** Back it up outside the sync directory, keep the existing task disabled while replacing the script, then run `--dry-run --verbose`. The existing path/two-hash manifest migrates automatically; a dry run migrates only in memory, and the real run saves v2. Local-to-server deletions are disabled during migration; if a previously tracked local file is already missing, migration stops. A preliminary `--pull` is optional. Existing Task Scheduler commands and script paths stay compatible.

Local deletion archives the remote bytes under `.telmi-deleted/YYYY-MM-DD/<unique-id>/<original-path>`, flushes and verifies SHA-256, rechecks the server, saves a pending journal, then deletes remotely and verifies the old ID is absent. The archive directory is marked hidden on Windows and excluded recursively from sync. Archives are never automatically removed. Both configured deletion limits apply; the last remote file is protected.

A timed-out deletion is not retried blindly. On a later run, a verified archive plus an absent remote ID completes the journal; if the ID still exists, the script stops for manual investigation. Do not clear the pending entry blindly. Old `remote_deleted` tombstones are cleaned up by removing their retained local files when still absent remotely; local-deletion tombstones prevent accidental resurrection.

Remote deletion removes the identified local copy even if edited or renamed, without an archive or conflict copy. A completely empty remote inventory still hard-stops before any such deletion. On a dry-run whole-folder move, the script reports the move and defers subsequent file planning until that move can be applied.

The API supports atomic `expected_sha256` only for replacement, not move/delete. Immediate preflight checks reduce races but cannot atomically prevent a concurrent server edit between the last check and metadata/delete POST.

**Never delete the state file as routine troubleshooting.** The state is needed to distinguish a genuinely new local file from one that was **previously synchronized and then deleted remotely**. Without this history, an old local copy can be mistaken for a new file and uploaded again.

The state file is not shared between Windows and macOS machines. Each PC maintains its own local three-way comparison baseline. Preserve the local state when recovering/migrating a machine, and never point two separate installations at the same local sync directory/state file without a deliberate migration plan.

Directories beginning `.telmi-` (including the complete archive subtree), files beginning `.telmi-`, files containing `.conflict-`, `.DS_Store`, and the configured state file are excluded from local uploads. Remote names that would be excluded are rejected. Do not intentionally use excluded names for files you want synchronized.

## 8. Logging and process locking

All runs append timestamped diagnostics to:

```text
%LOCALAPPDATA%\TelmiFilesSync\Logs\telmi-files-sync.log
```

The same messages appear on the console when running manually. To inspect the last lines:

```powershell
Get-Content "$env:LOCALAPPDATA\TelmiFilesSync\Logs\telmi-files-sync.log" -Tail 80
```

Errors appear as `ERROR HARD STOP: ...` and the script exits with a nonzero code. Each instance uses an **exclusive Windows file lock** in `%LOCALAPPDATA%\TelmiFilesSync\Locks`, scoped to the configured local root. The handle is held for the full run and automatically released when the process exits or crashes. The `.lock` file may remain on disk; **do not delete it to resolve a running-sync warning**. Close or investigate the active process instead. No stale-lock age cleanup is required.

## 9. What a correct dry run looks like

Illustrative example; paths and counts depend on the selected space:

```text
2026-11-02T10:00:00+01:00 telmi-files-sync start mode=both dry_run=1 space=<space-id>
2026-11-02T10:00:01+01:00 HTTP GET https://YOUR-TELMI-HOST.example/api/ghost/v2/files.php?... -> 200 (attempt 1/3)
2026-11-02T10:00:01+01:00 LIST /: 2 folders, 1 files
2026-11-02T10:00:02+01:00 LIST Projects: 1 folders, 2 files
2026-11-02T10:00:02+01:00 LIST Notes: 0 folders, 1 files
2026-11-02T10:00:03+01:00 LIST Projects/Reports: 0 folders, 1 files
2026-11-02T10:00:03+01:00 Remote inventory validated: 5 files, 4 folders
2026-11-02T10:00:03+01:00 Plan: 5 actions; archive_deletes=0
2026-11-02T10:00:03+01:00 DOWNLOAD Projects/Reports/summary.md (dry-run)
...
2026-11-02T10:00:03+01:00 telmi-files-sync done: folders=4 downloads=5 uploads=0 conflicts=0 skipped=0 moves=0 deletes=0 local_deletes=0 errors=0
```

`MOVE-LOCAL`, `MOVE-REMOTE-FILE`, and `MOVE-REMOTE-FOLDER` describe moves. `ARCHIVE+DELETE-REMOTE` previews an archive-first deletion, `DELETE-REMOTE` confirms it, and `DELETE-LOCAL` reports direct server-driven deletion. A root directory counts as a folder in the summary. `UPLOAD` means create, `REPLACE` means update the same remote file ID, `CONFLICT-SERVER-WINS` means preserve a divergent local copy, and `ADOPT` means hashes already match but need recording in state.

## 10. Recommended first-time rollout

1. Double-check your deployment URL, Ghost JWT, Files space, Windows sync directory, and log path.
2. Run `--dry-run --verbose`. Confirm all expected folders/files appear and that the action plan makes sense.
3. **Back up any valuable pre-existing local files** before the first real transfer; server wins if names collide.
4. Run `--pull --verbose` first to verify **actual downloads**, checksums, local file creation and state persistence without uploading.
5. Run another `--dry-run --verbose` and review proposed `UPLOAD`/`REPLACE` actions.
6. Create a **disposable local test file**, run the two-way sync, edit it, and verify that a subsequent run uses `REPLACE` rather than creating a duplicate server file.
7. Simulate a conflict with a disposable file. Confirm the server bytes win and the divergent local copy is retained as a `.conflict-...` file.
8. Test file/folder moves and both deletion directions using disposable files while retaining an anchor file on the server. Verify IDs survive moves, local deletion has an exact archive, and remote deletion has none. Confirm an empty server aborts.
9. Only then turn on scheduled automatic two-way sync.

A successful dry run proves **inventory and planning**, not actual transfer reliability. Windows API integration should be tested using disposable files before syncing business-critical data.

## 11. Automatic scheduling with Windows Task Scheduler

**Windows Task Scheduler is built in. No service installation is required.** Use a task that runs as the same Windows account that owns the sync folder and JWT. Do not schedule the same pairing twice under two different task names.

### Configure through the Task Scheduler GUI (recommended)

1. Press **Win + R**, enter `taskschd.msc`, and open **Task Scheduler**.
2. Select **Create Task...** (not *Create Basic Task*).
3. **General**:
   - Name: `Telmi Files Sync` (or another descriptive name).
   - Select **Run only when user is logged on** for the simplest single-user setup. Once validated, you may choose **Run whether user is logged on or not** if your account/policies permit; ensure that task has access to the same local profile and credentials.
   - Do **not** check **Run with highest privileges**; normal user access is sufficient.
4. **Triggers** → **New...**:
   - Begin the task: **On a schedule**.
   - Choose **Daily**, starting at a convenient time.
   - Check **Repeat task every: 15 minutes**, **for a duration of: Indefinitely**.
   - Leave **Enabled** checked.
5. **Actions** → **New...** → **Start a program**:
   - **Program/script:**

     ```text
     C:\Windows\System32\WindowsPowerShell\v1.0\powershell.exe
     ```

   - **Add arguments:** (replace the example username/path with the actual file path)

     ```text
     -NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -File "C:\Users\YOUR_USERNAME\bin\telmi-files-sync-windows.ps1"
     ```

   - **Start in:** leave empty (the script uses absolute paths). **Do not** put the script path into the *Program/script* field.
6. **Conditions**:
   - If needed on a laptop, uncheck **Start the task only if the computer is on AC power**.
   - Optionally choose a network-availability condition if supported by your setup.
7. **Settings**:
   - **If the task is already running:** **Do not start a new instance**. The script's own file lock provides an additional safeguard.
   - Optionally enable **Run task as soon as possible after a scheduled start is missed**.
   - Review **Stop the task if it runs longer than...**; do not terminate legitimate large-file transfers prematurely.
8. Save. Right-click the task → **Run** for a manual scheduled-task test. Inspect its **Last Run Result** and the log file.

**Important:** `-ExecutionPolicy Bypass` here applies to that `powershell.exe` invocation; the setup does not require a system-wide execution-policy change. Task Scheduler does not reliably inherit environment variables from a PowerShell window you used earlier. The configured JWT must be available to the task account without interactive steps.

### Verify or disable the task

```powershell
# Inspect basic task properties and recent execution outcome:
Get-ScheduledTask -TaskName 'Telmi Files Sync'
Get-ScheduledTaskInfo -TaskName 'Telmi Files Sync'

# Run once now:
Start-ScheduledTask -TaskName 'Telmi Files Sync'

# Disable future scheduled runs:
Disable-ScheduledTask -TaskName 'Telmi Files Sync'

# Re-enable when ready:
Enable-ScheduledTask -TaskName 'Telmi Files Sync'

# Read the client log:
Get-Content "$env:LOCALAPPDATA\TelmiFilesSync\Logs\telmi-files-sync.log" -Tail 80
```

Some organizations restrict Scheduled Tasks; use an approved configuration if your device is managed. The client will not run while the PC is powered off. Sleep/hibernation behavior depends on the task's conditions and settings; missed runs are not guaranteed to replay individually.

## 12. Troubleshooting

### `TELMI_GHOST_JWT not configured` / `TELMI_SPACE_ID missing/invalid`

Replace the placeholders in the script or provide the values to the *scheduled* process. A value exported only in your interactive PowerShell session may be missing from Task Scheduler.

### `remote inventory contains ZERO FILES`

The chosen Files space has no files visible to the Ghost. This is an **intentional hard stop**, even when folders exist. Verify the space, current permissions and whether at least one file is present.

### `hits non-paginated limit`

One folder has reached the listing limit. Move/organize the server content or implement real API pagination before syncing that folder. Do not increase the client limit above 500.

### `CONFLICT HTTP 409 during file_replace`

The server changed between listing and replacement. The client has not overwritten it. Re-run against the newest inventory; the server-wins rules then apply.

### `HTTP GET/POST ... failed`, 401, 403, 413, 415, 5xx, or timeouts

See the persistent log and validate the deployment URL, JWT, current space membership, file size/type permissions and network. HTTP 413 indicates size limits, 415 unsupported file type. The API may reject active web files. A POST timeout can be ambiguous: the server may already have committed the mutation; the client does not retry it blindly.

### `unsafe Windows ... name` or `reparse point/junction/symlink refused`

The server contains a filename Windows cannot represent safely, or the local tree contains a junction/symlink. The script aborts rather than silently renaming files or traversing outside the synchronized directory. Correct the offending entry or use an appropriate local folder.

### `another sync is running or lock unavailable`

Another run may be active. Check Task Scheduler and running `powershell.exe` processes. The lock is automatically released when the owner closes it; the visible `.lock` file is **not** proof of a stuck sync.

### The task ran but the files didn't change

Check `Last Run Result`, the log, the account used to run the task, JWT availability, actual script path, and whether `--dry-run` was accidentally left in the task's arguments. Confirm the local folder is reachable under that account.

### `Access is denied` / atomic replace fails

Check NTFS permissions, open documents holding an exclusive handle, ransomware protection/Controlled Folder Access, antivirus, sync folders, and whether `SYNC_DIR` is on a filesystem that supports atomic replacement. Stop before forcing permissions or disabling security protections.

### A previously deleted server file unexpectedly reappears

Check whether the state file was lost or whether a new client was paired to an existing local directory without the original history. **Do not delete state to troubleshoot this.** Remote deletion tracking is state-dependent.

## 13. Mantis Bat Files Sync guide

The no-auth Mantis Bat Files Sync page hosts this guide and the text-safe script download. Hades serves the guide; the client runs **locally on Windows** with the configured Ghost JWT. The page does not receive credentials, synchronize files on the web server, or allow a Ghost to run arbitrary Windows commands. An immediate remote trigger would require a separate authenticated local agent or outbound polling mechanism.

For reusable customer installations, treat every connection as a pairing of **deployment URL + Ghost credential + space ID + local directory + state + lock**. Do not embed customer/group/person names, internal project identifiers, or live tokens in distributed scripts or examples.

## 14. Compatibility and testing notes

This guide documents the Windows PowerShell client and the public Ghost API v2 Files operations available in November 2026. The implementation aims to match the macOS script's three-way sync and server-wins semantics, but it is a **separate Windows implementation**, not a line-for-line Bash translation.

It does not offer server-side pagination, atomic rollback of a whole run, atomic server move/delete preconditions, empty-folder deletion propagation, or remote PC execution.

**Validation status:** API routes and request formats were checked against the published OpenAPI schema. PowerShell parsing, native identity-helper C# compilation, mocked move/deletion planning, and filesystem-backed mocked transfers/state migration were checked using PowerShell 7 on macOS. These checks cover both deletion directions, archives and journal completion, dry-run/push/pull behavior, server renames, and the empty-server hard stop. Native Windows handle behavior still requires a Windows integration run. The script has not been executed against an actual Windows host or an authenticated customer Files space as part of this handoff. Verify its behavior with a dry run and disposable test files before enabling Task Scheduler for production data.
