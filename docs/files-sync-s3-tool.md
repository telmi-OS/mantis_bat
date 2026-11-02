<p><img src="../assets/logo/mantis-mini.svg" width="56" alt="Mantis Bat"></p>

# Files Sync AWS S3 Tool

This standalone Mantis Bat guide helps deploy the supplied Python Lambda client to sync one Telmi OS Files space with a dedicated Amazon S3 prefix. The guide offers Guided and Read all views and downloads the ready-to-upload Lambda ZIP, source, IAM policy example and full AWS operations guide.

The PHP page only serves the guide and downloads. It does not configure AWS, invoke Lambda, receive AWS credentials or a Ghost JWT, or sync files on Hades. AWS Lambda reads the Ghost JWT from Secrets Manager and accesses S3 through its execution role.

## What it does

- Recursively transfers files between a Telmi Files space and a non-empty S3 prefix.
- Uses S3 SHA-256 reads and conditional ETag writes; ETag is not treated as a content hash.
- Preserves Telmi file and folder IDs when it can identify an unambiguous move.
- Uses server-wins conflicts and saves divergent S3 bytes under the S3 conflict archive.
- Archives and verifies Telmi bytes before a tracked S3 deletion is propagated to Telmi.
- Removes a tracked S3 object directly when its Telmi file is deleted; S3 Versioning can retain earlier versions.
- Keeps a versioned state manifest and recovery journals in S3.
- Stops on incomplete inventories, empty Telmi spaces, ambiguous moves, size limits, SDK limitations, and API/S3 precondition errors.

This is not a cross-service transaction or a snapshot backup. Each invocation scans and hashes the configured S3 prefix. Use reserved Lambda concurrency `1`, enable S3 Versioning, and keep the state and archive keys outside the mirrored prefix.

## Files

- [Lambda deployment ZIP](../tools/files-sync-s3/php/public/downloads/telmi-s3-lambda-deploy.zip) — upload to Lambda; contains `lambda_function.py` at the ZIP root.
- [IAM policy template](../tools/files-sync-s3/php/public/downloads/iam-policy-example.json) — replace every bucket and secret placeholder and scope deletion to the data prefix.
- [Lambda source](../tools/files-sync-s3/php/public/downloads/lambda_function.py.txt) — text-safe source copy.
- [Full AWS guide](../tools/files-sync-s3/php/public/downloads/aws-lambda-guide.md) — deployment, environment variables, safety semantics, scheduling and troubleshooting.
- [Mantis Bat module README](../tools/files-sync-s3/php/README.md) — PHP-public layout, configuration and release packaging.

The repository also keeps the supplied offline test file at `tools/files-sync-s3/php/resources/aws/test_lambda_function.py`; it is not part of the Lambda runtime ZIP.

## Deployment outline

1. Create a general-purpose S3 bucket, enable Versioning, and select a dedicated non-empty prefix such as `synced-files/`. Keep `_telmi_sync/state.json`, `_telmi_sync/conflicts/`, and `_telmi_sync/deleted/` outside that prefix.
2. Store the Ghost JWT in AWS Secrets Manager as a plain string or JSON containing `jwt`. The function must read it through `TELMI_SECRET_ID`; never put it in environment variables or test events.
3. Create the Lambda execution role. Attach `AWSLambdaBasicExecutionRole` and the scoped policy template. Add KMS access if the bucket or secret uses a customer-managed key.
4. Create a Python 3.12+ Lambda with handler `lambda_function.lambda_handler`, upload the ZIP, set up to 900 seconds timeout, at least 512 MiB memory and temporary storage, and reserved concurrency `1`.
5. Configure the required bucket, prefix, Telmi origin, Files-space ID and secret ID environment variables. Keep `DRY_RUN=true` during setup.
6. Run a Lambda test event with `{"dry_run": true, "mode": "both", "verbose": true}` and review CloudWatch logs.
7. Test a `pull` and then two-way `both` mode using disposable files. Review both deletion directions and archives before allowing real data or scheduling.
8. After successful tests, configure EventBridge Scheduler (the guide uses every 15 minutes), set `RUN_MODE=both` and `DRY_RUN=false`, then monitor CloudWatch and retry/DLQ behavior.

The full guide describes the behavior of `both`, `pull` and `push`, S3 move detection limits, deletion journals, migration, request limits and failure recovery. A failed invocation can leave earlier completed transfers in place. Do not blindly retry after an ambiguous write or erase the state object to troubleshoot.
