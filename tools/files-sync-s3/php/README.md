<p><img src="../../../assets/logo/mantis-mini.svg" width="64" alt="Mantis Bat"></p>

# Mantis Bat Files Sync for AWS S3 (PHP)

This standalone Mantis Bat tool is a no-auth, step-by-step guide for deploying the Telmi Files Sync Lambda that mirrors one Telmi OS Files space with an S3 bucket prefix. The page reuses the Files Sync guide images and visual style. AWS Lambda performs the sync on its schedule; this PHP module only serves instructions and downloads.

The page does not collect AWS credentials or a Ghost JWT, configure AWS, invoke Lambda, or sync files on Hades. Store the Ghost JWT in AWS Secrets Manager and let the Lambda execution role access the selected S3 prefix and secret.

## Module contents

- `public/index.php` — guided AWS deployment flow with **Guided** and **Read all** views
- `public/downloads/telmi-s3-lambda-deploy.zip` — ready-to-upload Lambda bundle (`lambda_function.py` at ZIP root)
- `public/downloads/lambda_function.py.txt` — text-safe copy of the Lambda source for inspection
- `public/downloads/iam-policy-example.json` — least-privilege policy template; replace all placeholders
- `public/downloads/aws-lambda-guide.md` — full operations, configuration, safety, scheduling and troubleshooting guide
- `resources/aws/lambda_function.py` — source mirrored from the deployment bundle
- `resources/aws/test_lambda_function.py` — supplied offline test file; not needed in the Lambda runtime
- `resources/aws/iam-policy-example.json` — source copy of the downloadable IAM template
- `src/Config.php` and `storage/config.example.php` — optional page title, timezone and image configuration only

The provided deployment ZIP contains only `lambda_function.py`; it matches the attached source. The runtime uses AWS's bundled `boto3`/`botocore` when they support conditional S3 writes and deletes. The function checks this at startup and stops if the SDK is too old.

## Requirements

- PHP 8.1+ and HTTPS to serve the guide on Hades or a separate PHP host
- An AWS account with permission to configure S3, Secrets Manager, IAM, Lambda and EventBridge Scheduler
- A versioned, general-purpose S3 bucket and a dedicated non-empty mirrored prefix
- A Telmi Files space with at least one file and a Ghost JWT with read/write access
- Lambda outbound HTTPS access to the Telmi host and AWS service endpoints

AWS runtime requirements and limits are covered in the [full guide](public/downloads/aws-lambda-guide.md). No install frontend, app database, cron worker or AWS credentials are required by this PHP page. The module works without `storage/config.php`.

## Hades and self-hosting layout

The Mantis Bat catalog entrypoint is `index.php`. Hades serves the module's `public/` directory and keeps `src/`, `storage/`, `resources/` and `scripts/` outside the web root. There is no application installer. For a separate PHP host, expose only `public/` as the document root.

The optional image paths are:

- `public/assets/images/files-sync-start.webp`
- `public/assets/images/files-sync-end.webp`

They reuse the same start and end artwork as the desktop Files Sync guide. The guide falls back to its CSS artwork when either image is absent.

## Synchronization behavior

The Lambda uses the Ghost API v2 Files endpoints and S3 conditional writes. Telmi wins content conflicts; divergent S3 bytes are copied to the S3 conflict archive before replacement. S3 deletions are propagated only for tracked files and archive verified Telmi bytes before `file_delete`. A Telmi deletion removes the tracked S3 object directly without a second archive; S3 Versioning is the recovery layer for earlier S3 versions. Empty-folder deletion is not propagated.

The client uses an S3 state object with a version 2 manifest, pending operation journals and conditional writes. Keep this state object. Configure Lambda **reserved concurrency = 1**. Start with a Lambda test event that sets `dry_run` to `true`; review CloudWatch logs before the first real invocation. Test both sync directions and deletions with disposable files before creating a schedule.

This is not a cross-service transaction. Each run hashes the full configured S3 prefix, so size and run time grow with the data set. S3 has no inode identity; only unambiguous unchanged-content moves can retain remote IDs. The full guide covers limits and failure recovery.

## Release packaging

From the repository root, create a Hades-ready module archive:

```bash
tools/files-sync-s3/php/scripts/package-release.sh 1.0.0
```

The archive includes the guide page, reused images, deployment ZIP and public reference downloads. The Lambda deployment ZIP is a separate artifact for upload in the AWS Lambda console.
