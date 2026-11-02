<?php

declare(strict_types=1);

require __DIR__ . '/_runtime.php';

$config = $services['config'];
$title = (string) $config->get('app.title', 'Files Sync AWS S3');
$startImage = $config->publicAsset('guide.start_image', __DIR__);
$endImage = $config->publicAsset('guide.end_image', __DIR__);
$build = $config->buildFingerprint();
?>
<!doctype html>
<html lang="en" class="no-js">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#08090c">
    <meta name="description" content="A guided setup for syncing a Telmi OS Files space with Amazon S3 using AWS Lambda.">
    <title><?= filesSyncS3H($title) ?> | Mantis Bat</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Share+Tech+Mono&display=swap">
    <link rel="stylesheet" href="assets/theme.css">
    <script src="assets/guide.js" defer></script>
</head>
<body>
<header class="topbar">
    <a class="brand" href="index.php" aria-label="Files Sync AWS S3 home">
        <img src="assets/mantis-mini.svg" alt="">
        <span><small>Standalone Mantis Bat tool</small><strong><?= filesSyncS3H($title) ?></strong></span>
    </a>
    <div class="topbar-tools">
        <div class="mode-switch" role="group" aria-label="Guide view">
            <button type="button" class="mode-button is-active" data-mode-button="guide" aria-pressed="true">Guided</button>
            <button type="button" class="mode-button" data-mode-button="manual" aria-pressed="false">Read all</button>
        </div>
        <div class="step-count" id="step-count" aria-live="polite">Start here</div>
    </div>
</header>

<nav class="slide-nav" id="slide-nav" aria-label="Guide steps"></nav>
<div class="scroll-hint" id="scroll-hint">Scroll or use arrow keys</div>

<main class="deck" id="deck" data-mode="guide">
    <section class="slide cover-slide active" data-screen="intro" data-label="Welcome" aria-labelledby="welcome-title">
        <?php if ($startImage !== null): ?>
            <img class="cover-image" src="<?= filesSyncS3H($startImage) ?>" alt="" fetchpriority="high">
        <?php endif; ?>
        <div class="cover-wash" aria-hidden="true"></div>
        <div class="slide-inner cover-inner">
            <div class="cover-copy">
                <div class="eyebrow"><span class="eyebrow-spark"></span> Your Files, connected</div>
                <p class="assistant-greeting"><img src="assets/mantis-mini.svg" alt=""><span><strong>Mantis guide</strong><br>Hi! Let’s connect one Telmi OS Files space to an Amazon S3 prefix.</span></p>
                <h1 id="welcome-title">Your Files space,<br><span class="gradient-text">synced to S3.</span></h1>
                <p class="cover-lead">This guide helps you deploy a small AWS Lambda sync client. It runs in your AWS account, uses Secrets Manager for the Ghost JWT, and can run on a schedule.</p>
                <div class="cover-actions">
                    <button class="button button-primary" type="button" data-next>Let’s set it up <span aria-hidden="true">→</span></button>
                    <span class="quiet-note">No sign-in or AWS setup form on this page.</span>
                </div>
                <div class="cover-pills"><span>Telmi Files ↔ S3</span><span>Lambda scheduled</span><span>Preview first</span></div>
            </div>
            <div class="cover-visual" aria-hidden="true">
                <div class="orbit orbit-one"></div><div class="orbit orbit-two"></div>
                <div class="sync-core"><span class="core-cloud">☁</span><span class="core-arrow">⇄</span><span class="core-folder">▰</span></div>
                <div class="float-card cloud-card"><i>TELMI OS</i><strong>Files space</strong><small>One selected space</small></div>
                <div class="float-card local-card"><i>AMAZON S3</i><strong>Bucket prefix</strong><small>Synced by Lambda</small></div>
            </div>
        </div>
        <div class="cover-footer"><span>Scroll · swipe · arrow keys</span><span>Works on desktop and mobile</span></div>
    </section>

    <section class="slide" data-screen="requirements" data-label="Before you begin" aria-labelledby="requirements-title">
        <div class="slide-inner">
            <p class="eyebrow">Before you begin</p>
            <h2 id="requirements-title">Have these<br>details ready.</h2>
            <p class="lead">The page only provides instructions and downloads. You’ll configure AWS in its console and keep all credentials in AWS.</p>
            <div class="prep-grid">
                <article class="info-card"><span class="number-badge">01</span><div><h3>AWS access</h3><p>Permission to create or update an S3 bucket, Secrets Manager secret, IAM role, Lambda function, and EventBridge schedule.</p></div></article>
                <article class="info-card"><span class="number-badge">02</span><div><h3>Telmi Files access</h3><p>A Files space ID and a Ghost JWT that can read and write that space.</p></div></article>
                <article class="info-card"><span class="number-badge">03</span><div><h3>Telmi HTTPS address</h3><p>The deployment origin, such as <code>https://your-telmi-host.example</code>.</p></div></article>
                <article class="info-card"><span class="number-badge">04</span><div><h3>Small or medium data set</h3><p>The Lambda scans and hashes the full S3 prefix each run. Each file defaults to a 50 MiB limit.</p></div></article>
            </div>
            <aside class="notice-card"><span class="notice-icon">!</span><p><strong>Start with at least one file in the Telmi space.</strong> The client hard-stops on an empty or potentially incomplete remote inventory. Enable S3 Versioning before the first transfer.</p></aside>
            <div class="slide-actions"><button class="button button-primary" type="button" data-next>Next: protect the Ghost credential <span aria-hidden="true">→</span></button></div>
        </div>
    </section>

    <section class="slide" data-screen="secret" data-label="Store the Ghost credential" aria-labelledby="secret-title">
        <div class="slide-inner">
            <p class="eyebrow">Step 1 · Secrets Manager</p>
            <h2 id="secret-title">Keep the JWT<br>in AWS Secrets.</h2>
            <p class="lead">Create a Secrets Manager secret for the Ghost JWT. Lambda reads it at invocation time using its execution role.</p>
            <div class="safety-grid">
                <article><span class="mini-icon">◇</span><h3>Store either format</h3><p>A plain JWT string or a JSON secret with a <code>jwt</code> property is supported.</p></article>
                <article><span class="mini-icon">⌑</span><h3>Use least privilege</h3><p>Grant this Lambda role <code>secretsmanager:GetSecretValue</code> for this secret only.</p></article>
            </div>
            <div class="command-card"><div class="command-head"><span>Optional JSON secret value</span><button type="button" class="copy-button" data-copy="secret-json">Copy</button></div><pre id="secret-json"><code>{
  "jwt": "PASTE_GHOST_JWT_HERE"
}</code></pre></div>
            <aside class="notice-card"><span class="notice-icon">!</span><p>Keep the secret ARN or name for <code>TELMI_SECRET_ID</code>. Never put the JWT in Lambda environment variables, test-event JSON, this page, or CloudWatch logs.</p></aside>
            <div class="slide-actions"><button class="button button-primary" type="button" data-next>Next: prepare the S3 prefix <span aria-hidden="true">→</span></button></div>
        </div>
    </section>

    <section class="slide" data-screen="bucket" data-label="Prepare the S3 bucket" aria-labelledby="bucket-title">
        <div class="slide-inner">
            <p class="eyebrow">Step 2 · Amazon S3</p>
            <h2 id="bucket-title">Give sync its<br>own S3 prefix.</h2>
            <p class="lead">Use a general-purpose bucket, turn on Versioning, and choose a dedicated non-empty prefix for mirrored files. Do not sync the bucket root.</p>
            <div class="command-card"><div class="command-head"><span>Example key layout</span><button type="button" class="copy-button" data-copy="s3-layout">Copy</button></div><pre id="s3-layout"><code>s3://YOUR_BUCKET/synced-files/          # mirrored objects
s3://YOUR_BUCKET/_telmi_sync/state.json # keep this sync state
s3://YOUR_BUCKET/_telmi_sync/conflicts/ # S3 copies kept on conflict
s3://YOUR_BUCKET/_telmi_sync/deleted/   # Telmi bytes archived before delete</code></pre></div>
            <div class="check-grid"><div><span class="checkmark">✓</span><span>Enable S3 Versioning for recovery.</span></div><div><span class="checkmark">✓</span><span>Keep state and archive prefixes outside the mirrored prefix.</span></div><div><span class="checkmark">✓</span><span>Do not lifecycle-delete state or archives unexpectedly.</span></div></div>
            <aside class="notice-card"><span class="notice-icon">!</span><p>Do not delete or reset <code>_telmi_sync/state.json</code> after syncing begins. It records file identities, prior hashes, moves and incomplete operations.</p></aside>
            <div class="slide-actions"><button class="button button-primary" type="button" data-next>Next: download the Lambda files <span aria-hidden="true">→</span></button></div>
        </div>
    </section>

    <section class="slide" data-screen="downloads" data-label="Download the package" aria-labelledby="downloads-title">
        <div class="slide-inner">
            <p class="eyebrow">Step 3 · Deployment files</p>
            <h2 id="downloads-title">Get the code<br>and policy template.</h2>
            <p class="lead">The deployment ZIP is ready for Lambda’s upload flow. The IAM JSON is a template; replace every placeholder for your bucket and secret.</p>
            <div class="download-panel">
                <div class="file-icon powershell-file"><span>λ</span></div>
                <div class="download-copy"><strong>telmi-s3-lambda-deploy.zip</strong><span>AWS Lambda deployment bundle</span><small>Contains <code>lambda_function.py</code> at the ZIP root.</small></div>
                <a class="button button-primary download-button" href="downloads/telmi-s3-lambda-deploy.zip" download>Download ZIP <span aria-hidden="true">↓</span></a>
            </div>
            <div class="download-panel">
                <div class="file-icon shell-file"><span>{ }</span></div>
                <div class="download-copy"><strong>iam-policy-example.json</strong><span>Execution role permissions template</span><small>Scope resources to your chosen bucket prefix and secret.</small></div>
                <a class="button button-secondary download-button" href="downloads/iam-policy-example.json" download>Download policy <span aria-hidden="true">↓</span></a>
            </div>
            <div class="safety-grid">
                <a class="text-link" href="downloads/lambda_function.py.txt" download>Inspect the Lambda source <span aria-hidden="true">↓</span></a>
                <a class="text-link" href="downloads/aws-lambda-guide.md" download>Download the full AWS guide <span aria-hidden="true">↓</span></a>
            </div>
            <p class="small-note">The Python source is also kept privately in this module with the supplied offline test file. Tests are not included in the Lambda runtime ZIP.</p>
            <div class="slide-actions"><button class="button button-primary" type="button" data-next>Next: create the Lambda <span aria-hidden="true">→</span></button></div>
        </div>
    </section>

    <section class="slide" data-screen="deploy" data-label="Create the Lambda" aria-labelledby="deploy-title">
        <div class="slide-inner">
            <p class="eyebrow">Step 4 · AWS Lambda</p>
            <h2 id="deploy-title">Deploy the sync<br>function in AWS.</h2>
            <div class="prep-grid">
                <article class="info-card"><span class="number-badge">01</span><div><h3>Create the function</h3><p>Choose Python 3.12 or newer and x86_64 or arm64. Set handler to <code>lambda_function.lambda_handler</code>.</p></div></article>
                <article class="info-card"><span class="number-badge">02</span><div><h3>Upload the ZIP</h3><p>Use the downloaded deployment bundle. It places <code>lambda_function.py</code> at the archive root.</p></div></article>
                <article class="info-card"><span class="number-badge">03</span><div><h3>Attach the role</h3><p>Add <code>AWSLambdaBasicExecutionRole</code> for logs and the example policy, scoped to your S3 resources and secret.</p></div></article>
                <article class="info-card"><span class="number-badge">04</span><div><h3>Set runtime resources</h3><p>Timeout up to 900 seconds, at least 512 MiB memory and 512 MiB temporary storage. Reserved concurrency must be 1.</p></div></article>
            </div>
            <aside class="notice-card"><span class="notice-icon">!</span><p>Keep Lambda outside a VPC unless needed. A private subnet needs working outbound HTTPS to Telmi and access to AWS service endpoints. The function checks that its AWS SDK supports conditional S3 writes and deletes.</p></aside>
            <div class="slide-actions"><button class="button button-primary" type="button" data-next>Next: set environment variables <span aria-hidden="true">→</span></button></div>
        </div>
    </section>

    <section class="slide" data-screen="configure" data-label="Configure Lambda" aria-labelledby="configure-title">
        <div class="slide-inner">
            <p class="eyebrow">Step 5 · Lambda configuration</p>
            <h2 id="configure-title">Pair one space<br>with one prefix.</h2>
            <p class="lead">Set these required Lambda environment variables. Use the HTTPS origin only for Telmi; do not add an API path.</p>
            <div class="command-card"><div class="command-head"><span>Required environment variables</span><button type="button" class="copy-button" data-copy="required-env">Copy</button></div><pre id="required-env"><code>S3_BUCKET=YOUR_BUCKET
S3_PREFIX=synced-files/
TELMI_BASE_URL=https://YOUR_TELMI_HOST
TELMI_SPACE_ID=YOUR_FILE_SPACE_ID
TELMI_SECRET_ID=YOUR_SECRET_ARN</code></pre></div>
            <div class="config-grid">
                <article class="config-card"><span class="config-label">Sync direction</span><code>RUN_MODE=both</code><p>Modes are <code>both</code>, <code>pull</code> (Telmi → S3), and <code>push</code> (S3 → Telmi).</p></article>
                <article class="config-card"><span class="config-label">Safe first run</span><code>DRY_RUN=true</code><p>Keep dry-run enabled while setting up. A test event can override this value.</p></article>
                <article class="config-card"><span class="config-label">Persistent state</span><code>STATE_KEY</code><p>Default: <code>_telmi_sync/state.json</code>, outside the data prefix.</p></article>
                <article class="config-card"><span class="config-label">Archives</span><code>CONFLICT_PREFIX · DELETED_PREFIX</code><p>Keep both outside the synced prefix and preserve their contents.</p></article>
            </div>
            <p class="small-note">The JWT value itself belongs in Secrets Manager only. Review every placeholder in the IAM policy and grant <code>s3:DeleteObject</code> only on the mirrored data prefix.</p>
            <div class="slide-actions"><button class="button button-primary" type="button" data-next>Next: run a dry-run test <span aria-hidden="true">→</span></button></div>
        </div>
    </section>

    <section class="slide" data-screen="dry-run" data-label="Preview the plan" aria-labelledby="dry-run-title">
        <div class="slide-inner">
            <p class="eyebrow">Step 6 · Read-only preview</p>
            <h2 id="dry-run-title">See the plan<br>before it changes data.</h2>
            <p class="lead">In Lambda’s Test tab, create an event that explicitly enables dry-run and verbose logs.</p>
            <div class="command-card command-card-large"><div class="command-head"><span>Lambda test event</span><button type="button" class="copy-button" data-copy="dry-run-event">Copy</button></div><pre id="dry-run-event"><code>{
  "dry_run": true,
  "mode": "both",
  "verbose": true
}</code></pre></div>
            <div class="check-grid"><div><span class="checkmark">✓</span><span>Confirm the correct bucket, prefix and Files space are logged.</span></div><div><span class="checkmark">✓</span><span>Review every planned upload, download, conflict, move or deletion.</span></div><div><span class="checkmark">✓</span><span>Verify the inventory is complete and contains Telmi files.</span></div></div>
            <aside class="notice-card"><span class="notice-icon">!</span><p><code>dry_run=true</code> reads and hashes S3 and Telmi, but performs no writes, state changes, archives, or deletions. Inspect the full CloudWatch log. Empty server spaces and per-folder listings at the 500-item limit hard-stop.</p></aside>
            <div class="slide-actions"><button class="button button-primary" type="button" data-next>Plan looks right · try a pull <span aria-hidden="true">→</span></button></div>
        </div>
    </section>

    <section class="slide" data-screen="pull" data-label="Run the first transfer" aria-labelledby="pull-title">
        <div class="slide-inner">
            <p class="eyebrow">Step 7 · First transfer</p>
            <h2 id="pull-title">Start with Telmi<br>to S3 if needed.</h2>
            <p class="lead">For a new, empty S3 data prefix, use <code>pull</code> to populate it from Telmi without uploading S3 files.</p>
            <div class="command-card command-card-large"><div class="command-head"><span>Lambda test event</span><button type="button" class="copy-button" data-copy="pull-event">Copy</button></div><pre id="pull-event"><code>{
  "dry_run": false,
  "mode": "pull",
  "verbose": true
}</code></pre></div>
            <div class="safety-grid">
                <article><span class="mini-icon">↓</span><h3>Telmi wins conflicts</h3><p>If a path already exists on S3 with different content, the Telmi bytes become the active S3 object and the previous S3 bytes go to the conflict archive.</p></article>
                <article><span class="mini-icon">◷</span><h3>Keep the state object</h3><p>The S3 manifest is essential to identify moves, changed files, and which deletions are safe to propagate.</p></article>
            </div>
            <aside class="notice-card"><span class="notice-icon">!</span><p>In <code>both</code> or <code>pull</code>, a previously tracked Telmi deletion removes the matching S3 object directly, without a separate archive. In <code>push</code>, that removal is deferred. S3 Versioning is why it was enabled first. Test with disposable files before using existing data.</p></aside>
            <div class="slide-actions"><button class="button button-primary" type="button" data-next>Next: review two-way behavior <span aria-hidden="true">→</span></button></div>
        </div>
    </section>

    <section class="slide" data-screen="behavior" data-label="Understand changes and deletes" aria-labelledby="behavior-title">
        <div class="slide-inner">
            <p class="eyebrow">Before two-way sync</p>
            <h2 id="behavior-title">Know what each<br>side can remove.</h2>
            <div class="safety-grid">
                <article><span class="mini-icon">⇄</span><h3>S3 deletion</h3><p>The Lambda archives and verifies the current Telmi bytes in <code>DELETED_PREFIX</code>, writes a journal, then deletes the remote file. If Telmi changed, Telmi wins; restoration is deferred in <code>push</code> mode.</p></article>
                <article><span class="mini-icon">⌫</span><h3>Telmi deletion</h3><p>The tracked S3 object is deleted directly without an extra archive. S3 Versioning can preserve earlier object versions.</p></article>
                <article><span class="mini-icon">✧</span><h3>Content conflict</h3><p>Telmi wins. Divergent S3 bytes are saved under <code>CONFLICT_PREFIX</code> before S3 is replaced.</p></article>
                <article><span class="mini-icon">↗</span><h3>S3 moves</h3><p>S3 has keys, not file identities. A unique unchanged-content match can preserve the Telmi ID; ambiguous moves hard-stop.</p></article>
            </div>
            <p class="small-note">Empty folder deletion is not propagated. S3 folder markers may remain. Deletion archives and conflicts are not cleaned automatically; apply deliberate retention rules only after protecting state.</p>
            <div class="slide-actions"><button class="button button-primary" type="button" data-next>Next: test two-way sync <span aria-hidden="true">→</span></button></div>
        </div>
    </section>

    <section class="slide" data-screen="both" data-label="Test two-way sync" aria-labelledby="both-title">
        <div class="slide-inner">
            <p class="eyebrow">Step 8 · Two-way test</p>
            <h2 id="both-title">Run two-way<br>on test files first.</h2>
            <p class="lead">After a successful pull, run another dry-run in <code>both</code> mode. Then use disposable files to exercise uploads, replacements, conflicts, moves and both deletion directions.</p>
            <div class="command-card command-card-large"><div class="command-head"><span>Full bidirectional Lambda test</span><button type="button" class="copy-button" data-copy="both-event">Copy</button></div><pre id="both-event"><code>{
  "dry_run": false,
  "mode": "both",
  "verbose": true
}</code></pre></div>
            <div class="check-grid"><div><span class="checkmark">✓</span><span>Test new and changed objects on both sides.</span></div><div><span class="checkmark">✓</span><span>Verify an S3 conflict copy is retained in its archive.</span></div><div><span class="checkmark">✓</span><span>Test a deletion and check CloudWatch before proceeding.</span></div></div>
            <aside class="notice-card"><span class="notice-icon">!</span><p>This is not an atomic transaction. A later error does not roll back earlier successful changes. If a run fails after an uncertain write, inspect S3, Telmi and the pending journal before retrying.</p></aside>
            <div class="slide-actions"><button class="button button-primary" type="button" data-next>Tests look good · schedule it <span aria-hidden="true">→</span></button></div>
        </div>
    </section>

    <section class="slide" data-screen="schedule" data-label="Schedule Lambda" aria-labelledby="schedule-title">
        <div class="slide-inner">
            <p class="eyebrow">Step 9 · EventBridge Scheduler</p>
            <h2 id="schedule-title">Let Lambda keep<br>the two sides aligned.</h2>
            <div class="final-grid">
                <div class="final-copy">
                    <p class="lead">Once manual tests pass, create a recurring EventBridge Scheduler rule, for example every 15 minutes, targeting this Lambda.</p>
                    <div class="safety-grid">
                        <article><span class="mini-icon">◷</span><h3>Set live defaults</h3><p>Use <code>RUN_MODE=both</code> and change <code>DRY_RUN=false</code> only after you have reviewed and tested real transfers.</p></article>
                        <article><span class="mini-icon">1</span><h3>Keep concurrency at one</h3><p>Use reserved concurrency <code>1</code>; do not point a second schedule or function at the same state object.</p></article>
                    </div>
                    <a class="text-link" href="downloads/aws-lambda-guide.md" download>Download the complete AWS operations guide <span aria-hidden="true">↓</span></a>
                </div>
                <div class="recap-card"><span class="recap-kicker">Before enabling the schedule</span><ul><li>Enable S3 Versioning</li><li>Protect the S3 state object</li><li>Review a complete dry-run</li><li>Test both deletion directions</li><li>Monitor CloudWatch failures</li></ul></div>
            </div>
            <div class="slide-actions"><button class="button button-secondary" type="button" data-go="intro">Start over</button><button class="button button-primary" type="button" data-next>Finish <span aria-hidden="true">→</span></button></div>
        </div>
    </section>

    <section class="slide finale-slide" data-screen="finish" data-label="You're ready" aria-labelledby="finish-title">
        <?php if ($endImage !== null): ?>
            <img class="cover-image" src="<?= filesSyncS3H($endImage) ?>" alt="" loading="lazy">
        <?php endif; ?>
        <div class="cover-wash finale-wash" aria-hidden="true"></div>
        <div class="slide-inner finale-inner">
            <div class="finale-mark"><img src="assets/mantis-mini.svg" alt=""></div>
            <p class="eyebrow">You’re all set</p>
            <h2 id="finish-title">Your Files are<br><span class="gradient-text">on their way.</span></h2>
            <p class="lead">AWS now has the Lambda package and a path to sync the selected Files space with your S3 prefix. Keep the secret, state object, and archives protected.</p>
            <div class="finish-summary"><span class="finish-check">✓</span><span><strong>Files Sync AWS guide complete</strong><small>Review CloudWatch and protect S3 Versioning and sync state.</small></span></div>
            <div class="slide-actions"><button class="button button-primary" type="button" data-go="intro">Start over</button><a class="button button-secondary" href="downloads/aws-lambda-guide.md" download>Full guide</a></div>
        </div>
    </section>
</main>

<footer class="page-footer"><span>Files Sync · AWS S3 · Mantis Bat</span><span>Build <?= filesSyncS3H($build) ?></span></footer>
</body>
</html>
