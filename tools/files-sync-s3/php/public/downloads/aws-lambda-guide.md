# Telmi Files Sync v6 — AWS S3 ↔ Files Space (AWS Lambda)

A lightweight **bidirectional, recursive** synchronization client between an Amazon S3 bucket prefix and one Telmi OS Files space. Runs on **AWS Lambda with Python**, uses the AWS runtime's included `boto3`, and retrieves the Ghost JWT from **AWS Secrets Manager** on every invocation. No server, Docker image, access keys in code, or third-party Python packages are required.

> **Deployment status:** The provided ZIP contains `lambda_function.py` at its root for direct Lambda upload. This package has **not** been exercised against a live Telmi API and AWS account together. First deploy with `dry_run=true`, then test on disposable data before enabling a schedule.

## 1. Sync behavior

| Situation | Behavior |
|---|---|
| New file in Telmi | Download to S3 |
| New file in S3 | Upload to Telmi (`action=upload`) |
| S3 file changed since last successful sync | Replace existing Telmi bytes (`action=file_replace` with `file_id` and `expected_sha256`) |
| Telmi file changed since last successful sync | Download and conditionally update S3 |
| Both changed / first-run same-path conflict | **Telmi wins.** Save the divergent S3 bytes in a separate S3 conflict archive, then overwrite S3 conditionally |
| Previously synchronized file removed from S3 | Archive verified Telmi bytes in S3, save pending journal, then `file_delete`; `mode=pull` restores instead |
| S3 deletion while Telmi contents changed | Telmi wins: restore the server version (`push` defers downloading) |
| File removed from Telmi | Delete the tracked S3 copy **without an archive**, including locally edited copies; `push` defers this |
| S3 file moved / renamed | Unique unchanged-content match → `file_update`, retain file ID |
| S3 folder prefix moved | Unique complete unchanged-subtree match → `folder_update`, retain folder/child IDs |
| Telmi file / folder moved | Follow remote IDs to the new S3 keys, preserve divergent bytes for normal conflict handling |
| Ambiguous S3 move | Hard stop; do not guess identity |
| Empty Telmi inventory (zero files) | **Hard abort**, even when Telmi has folders |
| Incomplete listing, invalid SHA, HTTP 4xx/5xx, network error, S3 precondition failure | **Hard abort** and CloudWatch error; never silently treat failures as empty data |
| Telmi `file_replace` returns 409 | **Hard abort**: do not force an update or fall back to create-only upload |
| Empty remote folders | Create zero-byte S3 folder markers as needed |
| New S3 prefixes containing new files | Create corresponding Telmi folders on a write-enabled run; empty-only prefixes are not pushed |

**Deletion rules match macOS v6:** S3-side deletion archives first; Telmi-side deletion removes the S3 copy directly. Empty folder/prefix deletion is not propagated; old S3 folder markers can remain. Files in the state, conflict archive, and deletion archive are outside the mirrored prefix. Reserved `.telmi-*`, `.DS_Store`, and `.conflict-*` helper names are excluded locally and rejected remotely.

This is *not* a snapshot backup or an atomic transaction spanning AWS and Telmi. A failed invocation can leave a subset of successful file operations; it will fail, and the next invocation will reconcile the validated state. Versioning and a tested recovery process are strongly recommended.

## 2. Architecture

```text
  S3 bucket
  ├── synced-files/           <--- mirrored object prefix ---> Telmi Files space
  │   ├── notes.txt
  │   └── folder/report.pdf
  └── _telmi_sync/           (excluded from mirrored data)
      ├── state.json         sync checkpoints
      ├── conflicts/         preserved S3 copies when Telmi wins
      └── deleted/           verified Telmi bytes before S3-driven deletion

  EventBridge Scheduler (e.g. every 15 minutes)
                  |
             AWS Lambda
                  |
      +-----------+-----------+
      |                       |
  Secrets Manager        S3 + Ghost Files API
  (Ghost JWT)            (HTTPS)
```

- **State:** `STATE_KEY` is an S3 JSON object, not a Lambda-local file. Manifest v2 records remote file/folder IDs, paths, both sides' baseline SHA-256 values, scope (server + space + bucket + prefix), deletion tombstones, and pending delete/move journals. Updates use conditional S3 `PutObject`.
- **Deletion archive:** `DELETED_PREFIX` contains verified Telmi bytes saved before S3-side deletions are propagated. No automatic archive cleanup is performed. Telmi-side deletion creates no additional archive; ordinary S3 Versioning may retain earlier object versions.
- **Conflict archive:** `CONFLICT_PREFIX` stores copies of S3 data that would otherwise be overwritten during a server-wins conflict. Archive objects use unique names, and are never synchronized into Telmi.
- **File integrity:** Objects are streamed and SHA-256 checked, not compared using S3 ETag as a content hash. ETags are used as conditional-write preconditions only.
- **Concurrency:** Configure **Lambda reserved concurrency = 1** for this deployment. Conditional writes and deletes also protect S3 and `file_replace` protects Telmi replacements. Upload/create APIs cannot provide a perfect cross-system transaction.
- **Logging:** `START`, `LIST`, `INVENTORY`, `UPLOAD`, `REPLACE`, `DOWNLOAD`, `CONFLICT`, `MOVE-S3`, `MOVE-REMOTE-FILE`, `MOVE-REMOTE-FOLDER`, `ARCHIVE`, `DELETE-S3`, `ARCHIVE+DELETE-REMOTE`, `RECOVER-COMMITTED-DELETE`, `RECOVER-S3-MOVE`, `SKIP`, `DONE`, and `ERROR HARD STOP` are logged to CloudWatch.

## 3. Requirements

- AWS account, an **ordinary general-purpose S3 bucket** in a supported Region. Enable **S3 Versioning** before first use.
- A Telmi Files space with **at least one file** and a Ghost JWT with appropriate membership and read/write rights.
- AWS Lambda managed **Python 3.12 or newer** runtime and `boto3`/`botocore` supporting `IfMatch` on both `PutObject` and `DeleteObject`. The function checks SDK support at startup; if your runtime SDK is older, include current boto3/botocore in the deployment package.
- IAM execution role able to read the target S3 objects, save state/conflict/deletion archives, delete mirrored data objects, call Secrets Manager, and write CloudWatch Logs.
- Outbound HTTPS from Lambda to the Telmi host (if Lambda is attached to a private VPC subnet, provide NAT or another working outbound route). AWS service endpoint connectivity is also needed for Secrets Manager and S3.
- A dedicated, non-empty **S3 prefix**. Do **not** use the bucket root for synced files; it avoids the state and conflict archives being included in the inventory.

**Size and throughput:** Default `MAX_FILE_BYTES` is **50 MiB** for each file. `MAX_FILES` defaults to 5,000. The implementation reads/hashes all objects under the configured S3 prefix at the start of each invocation; a very large bucket can become slow and expensive. Lambda has a **15-minute maximum invocation**; for larger datasets, move to an incremental/event-driven design or orchestration rather than raising limits blindly.

**Telmi listing limitation:** Ghost API v2 `action=list` returns one folder per request and currently provides **no pagination cursor/offset**. The script traverses folders recursively but *hard-aborts* if any single folder returns **500 files or 500 subfolders** (or the configured lower `TELMI_LIST_LIMIT`), because completeness cannot be proven.

## 4. Create Secrets Manager secret

In **AWS Secrets Manager** → **Store a new secret**, choose Other type of secret. Store either a plain-text JWT string or JSON:

```json
{"jwt": "PASTE_GHOST_JWT_HERE"}
```

Record the secret ARN or name (used in `TELMI_SECRET_ID`). Avoid logging or committing its contents. Use AWS Secrets Manager rotation procedures when replacing the JWT; the function fetches it afresh each run.

## 5. Prepare the S3 bucket

Choose a dedicated bucket, or at minimum a dedicated mirrored prefix. For example:

```text
s3://YOUR_BUCKET/synced-files/          # mirrored data
s3://YOUR_BUCKET/_telmi_sync/state.json # sync state; do not delete
s3://YOUR_BUCKET/_telmi_sync/conflicts/ # archived divergent S3 bytes
s3://YOUR_BUCKET/_telmi_sync/deleted/   # Telmi bytes before an S3-driven remote delete
```

Enable **Bucket → Properties → Bucket Versioning**. Consider lifecycle/retention rules for the conflict archive and object versions. Do **not** configure lifecycle rules that unexpectedly erase the state file or conflict archive.

**Important:** Do not delete/reset the state JSON file after a successful sync. It distinguishes new objects, moves, and deletions, and preserves pending transaction recovery information. Restoring/reinitializing state requires an explicit migration plan.

## 6. IAM permissions

Attach the managed `AWSLambdaBasicExecutionRole` policy to the Lambda execution role for CloudWatch logging, then use the bundled `iam-policy-example.json` as a template for the remaining access. Replace **every** `YOUR_...` placeholder with your own values and use the correct S3 and Secrets Manager ARNs.

Minimum categories:

- `s3:ListBucket` on the chosen bucket.
- `s3:GetObject`, `s3:PutObject` on the mirrored prefix, state object, conflict prefix, and deletion-archive prefix.
- `s3:DeleteObject` **only on the mirrored data prefix** for server-driven deletion and S3 move cleanup. Archive/state deletion and permanent version deletion are not required.
- `secretsmanager:GetSecretValue` on the selected secret.
- For customer-managed KMS keys: appropriate `kms:Decrypt` and `kms:GenerateDataKey` grants as well.

An S3 bucket policy, VPC endpoint policy, or permissions boundary can impose additional restrictions even if the role policy looks correct.

## 7. Deploy the Lambda function

**Easy Console setup:**

1. AWS Console → **Lambda → Create function** (author from scratch), runtime **Python 3.12** or newer, architecture x86_64 or arm64.
2. Set handler to `lambda_function.lambda_handler`.
3. Upload the provided **`telmi-s3-lambda-deploy.zip`**, which has `lambda_function.py` at ZIP root. Alternatively paste `lambda_function.py` into the console editor.
4. Attach the IAM role from section 6.
5. Set **Timeout** to up to **900 seconds**, **Memory** to **512 MiB** or more, and **Ephemeral storage** to at least **512 MiB** (increase for larger files). Set **Reserved concurrency = 1** (not 0).
6. Add the environment variables below.
7. Keep the function **outside a VPC** unless VPC attachment is actually needed; by default Lambda can reach the public Telmi HTTPS endpoint. A Lambda in private subnets needs properly configured egress.
8. Save configuration and deploy the code before testing.

### Required environment variables

| Variable | Example (replace placeholders) | Purpose |
|---|---|---|
| `S3_BUCKET` | `YOUR_BUCKET` | Bucket name, without `s3://` |
| `S3_PREFIX` | `synced-files/` | **Non-empty** prefix containing mirrored objects |
| `TELMI_BASE_URL` | `https://YOUR_TELMI_HOST` | HTTPS origin only, no `/api/...` or trailing path |
| `TELMI_SPACE_ID` | `YOUR_FILE_SPACE_ID` | Target Files space ID |
| `TELMI_SECRET_ID` | `YOUR_SECRET_ARN` | Secrets Manager secret holding the Ghost JWT |

### Optional environment variables

| Variable | Default | Meaning |
|---|---|---|
| `STATE_KEY` | `_telmi_sync/state.json` | S3 state object; must be **outside** `S3_PREFIX` |
| `CONFLICT_PREFIX` | `_telmi_sync/conflicts/` | S3 server-wins conflict archive; outside data prefix |
| `DELETED_PREFIX` | `_telmi_sync/deleted/` | Verified Telmi-byte archive before S3-driven deletion; disjoint from data/state/conflicts |
| `MAX_LOCAL_DELETES_PER_RUN` | `10` | Maximum S3-driven Telmi deletions per run |
| `MAX_LOCAL_DELETE_PERCENT` | `25` | Percentage deletion limit (at least one permitted; final Telmi file always protected) |
| `RUN_MODE` | `both` | `both`, `pull` (Telmi → S3), or `push` (S3 → Telmi) |
| `DRY_RUN` | `false` | `true` calculates actions only, no writes |
| `VERBOSE` | `false` | `true` logs each checked path |
| `TELMI_LIST_LIMIT` | `500` | Per-folder files/folders response cap, max 500 |
| `MAX_FOLDER_DEPTH` | `32` | Recursion cap |
| `MAX_FILES` | `5000` | Inventory hard limit per side |
| `MAX_FILE_BYTES` | `52428800` | Per-file size cap in bytes (50 MiB default) |
| `HTTP_TIMEOUT` | `90` | HTTPS connection/read timeout in seconds |

The JWT is **never** supplied as an environment variable. Avoid putting Secrets Manager values in invocation payloads or logs.

## 8. First-run procedure

### Step A — Test inventory and planned changes (read-only)

In Lambda **Test**, create a JSON test event:

```json
{"dry_run": true, "mode": "both", "verbose": true}
```

Review CloudWatch. Expect a validated inventory, listing of nested folders, and planned `DOWNLOAD`, `UPLOAD`, `REPLACE`, or `CONFLICT-SERVER-WINS` operations. **At least one Telmi file** must be discovered.

`dry_run=true` performs API/S3 reads, hashing and planning, but creates **no S3 state, files, folders, archives, or Telmi mutations**.

### Step B — First transfer using disposable files

If you want to populate an empty S3 prefix from Telmi first:

```json
{"dry_run": false, "mode": "pull", "verbose": true}
```

Then run another dry-run in `both` mode. **Do not automatically run a full two-way sync until its plan matches your intent.** If both sides already contain a same-path file with different content, the first two-way run deliberately considers it a conflict: **Telmi wins**, and the S3 original is archived.

### Step C — Full bidirectional run

```json
{"dry_run": false, "mode": "both", "verbose": true}
```

Then retry a dry run and expect no transfers when nothing changed. Test separately with disposable files: new S3 file, modified S3 file, new Telmi file, modified Telmi file, simultaneous edits, and deletion on each side.

`mode` and `dry_run` may also be specified through environment variables for scheduled runs; an event property overrides its matching environment variable.

## 9. Schedule every 15 minutes

After all tests succeed:

1. AWS Console → **EventBridge Scheduler → Create schedule**.
2. Choose **Recurring** → schedule expression `rate(15 minutes)`.
3. Choose flexible time window **Off** (or configure one deliberately).
4. Target → **AWS Lambda Invoke** → select the Lambda function.
5. Input payload: `{}` (uses environment defaults); set `RUN_MODE=both` and `DRY_RUN=false` on the Lambda.
6. Create/choose the scheduler execution role with `lambda:InvokeFunction` permission on this Lambda.
7. Set a suitable retry policy / dead-letter queue, and monitor failures in CloudWatch.
8. Keep **Lambda reserved concurrency = 1**; do not create a second unsynchronized Lambda pointing at the same state key.

### Upgrading an existing deployment

Keep the existing S3 state JSON and make a backup outside the mirrored prefix. Pause the schedule, update the role using the revised IAM policy (including data-prefix `DeleteObject` and deletion-archive read/write), upload the rebuilt ZIP, and retain the existing environment settings. New deletion settings have defaults. Run a dry run, then a real default or pull invocation, and re-enable the schedule after checking its output. A preliminary pull is optional.

The old version-1 state migrates automatically to v2, binding it to the current Telmi URL. The migration run disables S3-to-Telmi deletion and stops if an actively tracked S3 object is already missing. A dry run does not persist migration. Older remembered server deletions are applied to S3 in default/pull mode. Do not erase the state to force migration.

A hard-stop invocation fails visibly (exception + CloudWatch error). It does **not** erase past state or suppress scheduled retry behavior. Do not retry blindly after an ambiguous HTTP write without checking the current server state.

## 10. API semantics and safety details

- `GET /api/ghost/v2/files.php?action=list&space_id=...&limit=500[&folder_id=...]` returns one folder's immediate `files[]` and `folders[]`; the script walks recursively.
- `GET ?action=content&file_id=...` streams bytes; downloaded content must match the listing's SHA-256 and size.
- `POST ?action=folder_create` uses JSON `space_id`, `name`, and optionally `parent_id`.
- `POST ?action=upload` uses multipart `space_id`, optional `folder_id`, and `file`. **It creates**, never replaces.
- `POST ?action=file_replace` uses multipart `file_id`, `expected_sha256`, and `file`. **It replaces** while preserving the remote ID and associated shares.
- `POST ?action=file_update` uses JSON `file_id`, `name`, nullable `folder_id`.
- `POST ?action=folder_update` uses JSON `folder_id`, `name`, nullable `parent_id`.
- `POST ?action=file_delete` uses JSON `file_id` after archive verification and a durable journal.
- `409 file_changed` means the optimistic concurrency condition failed. The Lambda logs a hard stop; a later run can resolve with Telmi winning.

### Deletions and uncertain outcomes

Before an S3-side deletion removes a Telmi file, the function refreshes the full nonempty inventory, checks path/ID/hash, rejects excessive batches and the final server file, confirms the S3 key is still absent, downloads Telmi bytes, writes a unique `DELETED_PREFIX/YYYY-MM-DD/<id>/<original-path>` archive, reads it back and verifies SHA-256, then rechecks the server and S3. It saves `pending_deletes` before sending `file_delete` and confirms the ID is absent before completing the checkpoint.

A later invocation verifies the archive and finishes a pending deletion if the ID is absent; if the ID still exists, it hard-stops for manual investigation without retrying the delete. Local-deletion tombstones prevent re-upload of a file recreated at that archived path. Do not clear journals blindly.

Telmi deletion directly removes the tracked S3 key with an ETag-conditioned `DeleteObject`, without a conflict/deletion archive. A changed S3 object after inventory causes a precondition failure. `mode=push` retains state so a later default/pull run applies the deletion. `dry_run=true` makes no writes/deletes. An empty Telmi space always aborts before any S3 deletion, even when folders remain.

### Moves and S3 identity limits

S3 has keys and prefixes, **no inode or true folder identity**. Ordinary S3 rename tools copy to a new key and delete the old key. The function recognizes a move only when the old key is absent, exactly one untracked key has its unchanged baseline SHA-256, and exactly one missing source matches. A complete unique subtree match can rename a remote folder using `folder_update`. Ambiguous duplicate-content matches stop. Empty-prefix renames cannot establish identity. For rename plus edit, sync the rename before editing; a changed-content copy-and-delete cannot reliably prove a move and may be treated as deletion plus a new upload. This is an inherent difference from Windows/macOS native file identities.

Remote moves follow stable file IDs. S3 move cleanup uses a durable `pending_moves` journal, create-only destination writes, read-back verification and conditional source deletion. Interrupted copies resume from the journal instead of re-uploading the old key as a new Telmi file. A changed source/destination requires manual investigation. Run a real default/pull invocation to recover a pending S3 move. Whole-folder dry runs report the folder move and defer remaining file planning.

Telmi provides no atomic hash/version precondition for `file_update`, `folder_update`, or `file_delete`. Immediate rechecks reduce races but cannot rule out a concurrent server edit between the final read and POST. Replacement still uses atomic `expected_sha256`.

### Why is the sync state in S3?

Lambda's local `/tmp` storage is ephemeral and not shared across invocations. The state object's conditional write (`IfMatch` / `IfNoneMatch`) helps catch concurrent state writers. Updating the state only **after** a verified transfer makes interrupted operations recoverable through the next inventory comparison. It is still not a distributed transaction.

### Why S3 ETag plus SHA-256?

S3 ETag is **not always an MD5 or SHA-256 content hash**, especially for multipart/encrypted uploads. Content equality uses SHA-256 read from actual bytes. S3 conditional `PutObject` and `DeleteObject` compare ETags to prevent clobbering newer objects. Enable S3 Versioning for recovery if a writer outside the Lambda also modifies the bucket.

### Limits and operational cautions

- Files prohibited by the Telmi API (for example active web formats), oversized files or exhausted quotas cause a logged hard stop. There is no automatic format conversion.
- A source object changing during a transfer is rejected. S3 writes are conditional; Telmi existing-file updates are SHA-256 conditional.
- **Telmi `upload` and `folder_create` have no atomic create-if-absent condition in this API.** The client rechecks immediately before creating, but cannot eliminate every race with other independent writers. The remote API would need an atomic create precondition for that guarantee.
- Files are processed sequentially for predictable fail-closed behavior. No parallel downloads or automatic unbounded HTTP retries.
- A Lambda timeout or failure **after a remote write but before its state checkpoint** is possible. On rerun, inventory is compared again; inspect CloudWatch if an ambiguous write occurred.
- Repeated full-bucket hashing consumes **S3 GET requests, transfer, time, and cost**. The script is intended for small/medium workspaces. For large buckets, replace full scans with event-driven changed-key queues and a durable index.
- State and archive keys are not copied into Telmi. Do not place synced data directly at bucket root.
- S3 folder markers represent empty folders; S3 has no true hierarchical directory structure.

## 11. Troubleshooting

| Symptom | What to check |
|---|---|
| `EMPTY TELMI SERVER` | Space ID, Ghost membership, list action, target has at least one real file; intentionally aborts |
| `ambiguous limit reached` | Folder contains 500 files or 500 immediate folders; API needs real pagination |
| `HTTP 401/403` | JWT/role/membership/permissions; refresh secret rather than hardcode token |
| `HTTP 409` on replace | File changed on Telmi since inventory; hard stop; rerun after checking changes |
| `S3 conditional write failed` | Concurrent S3 writer, wrong ETag/state race, S3 policy |
| Pending delete still exists / pending S3 move changed | Inspect actual Telmi/S3 data and verified archives; resolve the journal deliberately, never blindly retry/erase it |
| `ambiguous S3 ... move` | Multiple identical-content sources/destinations; make the move unambiguous and separate moves from edits |
| `AWS SDK lacks conditional writes/deletes` | Package current boto3/botocore or use a runtime with the required conditional API support |
| `state scope/schema invalid` | Wrong bucket/prefix/space combination, corrupted state; **do not delete state casually** |
| `AccessDenied` on state read | Ensure both `s3:GetObject` and `s3:ListBucket` grants |
| HTTPS timeout | Lambda egress, VPC/NAT/DNS, Telmi endpoint accessibility, timeout setting |
| Unsupported file format / HTTP 415 | Telmi Files API file-type policy; exclude/rename by deliberate user action |
| Function timed out | Reduce data set/file size or redesign incremental; Lambda maximum is 15 minutes |
| `Throttled` Lambda invocations | Reserved concurrency 1 blocks overlapping invocations; inspect schedule, duration, and retry/DLQ policies |

## Validation

The repository includes offline tests at `resources/aws/test_lambda_function.py`; they are not needed in the Lambda runtime ZIP. Live AWS/Telmi testing remains required before scheduling.

## 12. Mantis Bat Files Sync AWS guide

The no-auth Mantis Bat page hosts this guide and the deployment downloads. It does not deploy or operate the Lambda, read AWS credentials, or perform the sync itself. AWS runs the client using the configured Lambda execution role and Secrets Manager secret. A Ghost's existing access to its Files space does **not** grant it implicit AWS permissions; only the explicitly configured Lambda role can access S3.

## References

- [Telmi Ghost API v2 OpenAPI](https://teleport-ai.com/assets/api/ghost-api-v2.openapi.json)
- [AWS Lambda Python runtimes and included SDK](https://docs.aws.amazon.com/lambda/latest/dg/lambda-python.html)
- [AWS S3 DeleteObject, conditional headers and versioning](https://docs.aws.amazon.com/AmazonS3/latest/API/API_DeleteObject.html)
- [AWS S3 conditional writes](https://docs.aws.amazon.com/AmazonS3/latest/userguide/conditional-writes.html)
- [AWS Lambda concurrency configuration](https://docs.aws.amazon.com/lambda/latest/dg/lambda-concurrency.html)
- [EventBridge Scheduler recurring schedules](https://docs.aws.amazon.com/scheduler/latest/UserGuide/schedule-types.html)

---

**Release:** 1.0 (AWS Lambda, bidirectional, server-wins). Configuration contains only placeholders and generic names; no internal hostnames, real bucket names, credentials, or Files space IDs are hardcoded.
