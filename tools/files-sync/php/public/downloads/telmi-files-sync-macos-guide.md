# Telmi Files Sync for macOS — identity-aware moves and archived deletions (v6)

This release is based on the **working v4/v3-named macOS script**, not the discarded v5 move implementation. It still runs on macOS with stock Bash 3.2, `python3`, and `curl`. No Python packages need to be installed.

**Important:** Deletions propagate in both directions for previously synchronized files. Local deletion archives the server bytes before deleting remotely. Remote deletion removes the local copy directly, **without an archive**, including locally edited copies. Test it against a disposable Files space before using it unattended.

## Behavior

| Event | Result |
|---|---|
| New local file | `upload`, create a new remote file ID |
| Local file content change | `file_replace`, retain remote `file_id`, SHA-256 precondition |
| Local file move/rename | `file_update`, retain original remote `file_id` |
| Local folder move/rename | `folder_update`, retain remote `folder_id` and children's IDs |
| Remote file/folder move | Move local file/folder to the new server location |
| Local file deleted | **Download + checksum-verify hidden archive, then `file_delete`** |
| Local deletion while server content changed | Server wins; download the current server version |
| Remote file deleted | Delete the tracked local copy directly, without an archive (default / `--pull`) |
| Both sides changed content | Server wins; preserve divergent local content in `.conflict-*` |
| Uncertain move vs deletion | Hard stop instead of inventing an identity |
| Empty remote inventory | Hard stop; at least one remote file required |
| HTTP/API/write errors | Hard stop with nonzero exit status and timestamped log |

**A move is not a delete-plus-upload.** It preserves file IDs and existing share references. Moving a file locally to a **new folder** creates that destination folder, then moves the existing file metadata to it. Moving an existing folder uses `folder_update` so child identities remain unchanged.

Remote file deletions delete the tracked local copy without an archive. Empty folder deletion is **not** propagated: deleting a server folder removes its tracked local files, but empty local directories can remain. Removing an entire local folder may delete its files individually after backup, but the server's empty folder can remain and be recreated locally.

## Download and configuration

The Mantis Bat download is named `telmi-files-sync.sh.txt`. Rename it to `telmi-files-sync.sh` and save it in a private location, for example `~/bin/telmi-files-sync.sh`. Back up the installed script and its existing `.telmi-sync-state.json` file first.

Configure the script's existing variables (which can also be set via environment):

```bash
TELMI_BASE_URL="https://YOUR-TELMI-HOST"
TELMI_SPACE_ID="YOUR_SPACE_ID"
TELMI_GHOST_JWT="YOUR_GHOST_JWT"
SYNC_DIR="$HOME/TelmiSync/MySpace"
```

The Mantis Bat copy uses generic URL, space ID and credential placeholders, with a neutral `~/TelmiSync/MySpace` destination. Existing installations should copy their current URL, space ID, credential and sync path into the updated script. Keep JWTs out of Git, logs and shared documentation.

Optional deletion guard settings:

```bash
MAX_LOCAL_DELETES_PER_RUN=10
MAX_LOCAL_DELETE_PERCENT=25
```

A batch of local deletions must satisfy **both** limits. The script refuses to delete the final remote file. You may adjust limits deliberately after reviewing what a dry run proposes.

## Commands — unchanged

```bash
bash telmi-files-sync.sh --dry-run --verbose
bash telmi-files-sync.sh --pull
bash telmi-files-sync.sh --push
bash telmi-files-sync.sh --verbose
```

- Default mode: bidirectional.
- `--dry-run`: plan-only, no synced-content, manifest, or API mutations. For a planned **whole-folder move**, it reports that move and stops further planning, because the remaining file plan depends on the move having happened.
- `--pull`: server-to-local only, including deletion of local copies whose server files disappeared; never invokes a remote mutation or deletion. Reverts a local path move to the server's path.
- `--push`: local-to-server changes, without pulling remote files or applying server deletions locally. Tracking of missing server IDs is retained so a later default / `--pull` run can delete those local copies. The server still wins conflicts: the script refuses stale/unsafe writes.
- `--verbose`: log individual folder listings and HTTP requests. JWTs are not printed.

## Manifest v2

The old manifest keyed by relative path and two SHA-256 strings could not distinguish **rename/move** from **delete + new upload**. The new manifest is keyed by stable *remote object IDs*, with local inode/device hints.

Illustrative layout (not a literal live manifest):

```json
{
  "version": 2,
  "scope": {
    "base_url": "https://YOUR-TELMI-HOST",
    "space_id": "YOUR_SPACE_ID"
  },
  "files": {
    "file_example_id": {
      "path": "Reports/summary.md",
      "sha256": "<64-hex-character-sha256>",
      "dev": 123,
      "ino": 456
    }
  },
  "folders": {
    "folder_example_id": {
      "path": "Reports",
      "dev": 123,
      "ino": 789
    }
  },
  "tombstones": {},
  "pending_deletes": {}
}
```

- `files`: `file_id` → most recently synchronized path, contents hash, and local filesystem identity.
- `folders`: `folder_id` → path and local filesystem identity.
- `tombstones`: paths previously deleted **locally** and archived, preventing accidental resurrection. Older `remote_deleted` entries are cleaned up by deleting their retained local copies when the server path and ID remain absent (default / `--pull`).
- `pending_deletes`: a durable journal saved **before** the remote `file_delete` request. Protects against retries after an uncertain HTTP outcome.
- `scope`: prevents accidentally using one local manifest with another Telmi instance or Files space.

Local inode/device information is reliable for ordinary renames/moves **within the same filesystem**. Copying and deleting a file, or moving it across filesystems, can change its inode. If such a change is indistinguishable from delete-and-create, the client aborts rather than silently deleting the original ID. To resolve it, perform the move within the same sync volume, or handle the exceptional move in Telmi and rerun.

Do **not delete or manually edit the manifest** to force synchronization. You can lose the history that distinguishes a deletion from a new file. Make backups instead.

## Hidden archive — local deletion

An eligible local deletion has the following sequence:

1. Verify the file was previously synchronized and its server `file_id`, original path and SHA-256 are known.
2. Confirm the file has not merely moved elsewhere on the local filesystem.
3. If the server contents changed, **restore the server version instead of deleting**.
4. Refresh the entire remote inventory; reject an empty or incomplete result and protect the final server file.
5. Download the server file to a unique hidden archive path under `.telmi-deleted/`, flush and verify the actual bytes against the expected SHA-256.
6. Check the server file identity/path/hash again.
7. Write a `pending_deletes` manifest entry **before** the destructive request.
8. Call `POST .../files.php?action=file_delete` with `file_id`.
9. Verify the old ID is absent, record a tombstone, and clear the pending entry.

Example (for an original `Reports/summary.md`):

```text
MySpace/
├── .telmi-sync-state.json
├── .telmi-deleted/
│   └── 2026-11-02/
│       └── <unique-operation-id>/
│           └── Reports/
│               └── summary.md
└── Reports/
```

The entire `.telmi-deleted/` subtree is **excluded recursively**, including its nested files and folders. The script never cleans up archived copies automatically. Archive contents may include confidential files; restrict disk access and backup policies accordingly.

If `file_delete` times out **after committing**, the next run sees the journal and remote ID absent and completes the manifest transaction without repeating the delete. If the ID still exists, the next run **hard-stops for manual investigation**, rather than automatically retrying a possibly destructive action. Verify the archive and actual server contents before resolving that journal entry. Do not clear it blindly.

## Remote deletion — no archive

After a complete recursive server inventory, a previously tracked `file_id` that is absent causes its identified local copy to be deleted directly. Local content changes do not prevent this deletion. No archive or conflict copy is made. `DELETE-LOCAL` messages and the `local_deletes` counter report these actions. `--dry-run` reports them without changing files or the manifest; `--push` defers them.

A server move retaining the same `file_id` is still a move: the local copy follows the new server location and updated server contents are downloaded as needed. If the server instead replaces the old ID with a new ID, the old tracked copy is deleted and the new server file is downloaded normally.

An inventory containing zero remote files causes a hard stop before local deletions, even if empty server folders remain. Invalid or incomplete inventory responses also stop the run before this deletion phase. Files never tracked by the manifest are not deleted because they are absent remotely; default / `--push` may upload them, while `--pull` leaves them alone. Existing archives remain excluded from sync.

### Backend concurrency limitation

The current Files API schema accepts `expected_sha256` for `file_replace` but **not** for `file_delete`, `file_update`, or `folder_update`. The client performs strong preflight and immediately-before-action checks, but these are not atomic with the metadata/delete mutation. A concurrent server edit in that narrow window is not completely preventable from the client alone. For a strict server-wins guarantee under simultaneous clients, extend the backend with a conditional hash/version parameter for these three endpoints.

## Migration from v4 / existing scheduled installation

1. **Disable** the existing `launchd` job (or prevent the scheduled script from running).
2. Copy the previous script and **back up `.telmi-sync-state.json`**. Do not delete it.
3. Ensure the local folder and server are synchronized using the current working version **before upgrading**. In particular, do not upgrade halfway through a local move.
4. Install v6 at the **same absolute script path** referenced in the LaunchAgent and copy your JWT/server/space/configuration settings into it. `chmod 700` it.
5. Run `--dry-run --verbose` and inspect the plan carefully. A dry run does **not** write manifest v2.
6. Run one non-dry-run `--verbose` sync with no deliberate local deletions. It migrates the old manifest to v2 and captures identity information. If a previously tracked file is already missing locally, migration **hard-stops** rather than interpreting it as a deletion.
7. Make a disposable file, sync it, then test one rename, one move into a folder, and one local deletion. Check the server `file_id` after a move and verify that `.telmi-deleted` contains the exact bytes before considering the deletion test successful.
8. Re-enable `launchd` only after successful verification.

**LaunchAgent:** existing `/bin/bash`, `ProgramArguments`, `StartInterval=900`, log paths, and `launchctl` configuration remain compatible **when the script path stays unchanged**. As before, variables exported only inside an interactive Terminal session will not automatically be present in a LaunchAgent; use script configuration or a suitable secure environment mechanism.

### Known safety restrictions

- A remote folder response at the API's 500-item file/folder limit is rejected: the API lacks pagination or a completeness signal.
- Empty remote space is rejected; at least one remote file is required. Deleting the last server file in response to a local deletion is also rejected.
- Symlinks inside the sync tree, reserved `.telmi-*` remote names, colliding case/Unicode paths, and missing/unreadable manifests hard-stop.
- Deletion requires existing manifest identity; a first run will download server-only files, not delete them.
- Local moves across filesystems may be ambiguous; no false promise of identity preservation for copy-and-delete operations.
- Remote folder deletions remove tracked local files without an archive; empty local directories may remain. Local empty folder deletions are not propagated.
- Preflight and archive checks mitigate races, but atomic conflict protection for move/delete requires a corresponding server-side precondition.

## Testing status

The current script was checked for Bash syntax, embedded Python syntax, and the empty-server hard stop. The temporary offline test suite was removed as requested.

It has **not** been executed against the real authenticated Telmi development API or on macOS in this environment. Treat a controlled disposable-space integration test as mandatory before production scheduling.
