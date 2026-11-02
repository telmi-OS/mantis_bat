<?php

declare(strict_types=1);

require __DIR__ . '/_runtime.php';

$config = $services['config'];
$title = (string) $config->get('app.title', 'Files Sync');
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
    <meta name="description" content="A guided setup for syncing a Telmi OS Files space with a Mac or Windows computer.">
    <title><?= filesSyncH($title) ?> | Mantis Bat</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Share+Tech+Mono&display=swap">
    <link rel="stylesheet" href="assets/theme.css">
    <script src="assets/guide.js" defer></script>
</head>
<body>
<header class="topbar">
    <a class="brand" href="index.php" aria-label="Files Sync home">
        <img src="assets/mantis-mini.svg" alt="">
        <span><small>Standalone Mantis Bat tool</small><strong><?= filesSyncH($title) ?></strong></span>
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

<main class="deck" id="deck" data-mode="guide" data-platform="">
    <section class="slide cover-slide active" data-screen="intro" data-label="Welcome" aria-labelledby="welcome-title">
        <?php if ($startImage !== null): ?>
            <img class="cover-image" src="<?= filesSyncH($startImage) ?>" alt="" fetchpriority="high">
        <?php endif; ?>
        <div class="cover-wash" aria-hidden="true"></div>
        <div class="slide-inner cover-inner">
            <div class="cover-copy">
                <div class="eyebrow"><span class="eyebrow-spark"></span> Your Files, within reach</div>
                <p class="assistant-greeting"><img src="assets/mantis-mini.svg" alt=""><span><strong>Mantis guide</strong><br>Hi! Let’s connect one Telmi OS Files space to a folder on your computer.</span></p>
                <h1 id="welcome-title">Your Files space,<br><span class="gradient-text">synced locally.</span></h1>
                <p class="cover-lead">Follow a short setup for Mac or Windows. The sync runs on your computer, and you choose exactly which Files space and local folder it uses.</p>
                <div class="cover-actions">
                    <button class="button button-primary" type="button" data-next>Let’s set it up <span aria-hidden="true">→</span></button>
                    <span class="quiet-note">No account or sign-in on this guide page.</span>
                </div>
                <div class="cover-pills"><span>Recursive folders</span><span>SHA-256 checked</span><span>Preview first</span></div>
            </div>
            <div class="cover-visual" aria-hidden="true">
                <div class="orbit orbit-one"></div><div class="orbit orbit-two"></div>
                <div class="sync-core"><span class="core-cloud">☁</span><span class="core-arrow">⇄</span><span class="core-folder">▰</span></div>
                <div class="float-card cloud-card"><i>telmi OS</i><strong>Files space</strong><small>Folders · documents</small></div>
                <div class="float-card local-card"><i>YOUR COMPUTER</i><strong>Local folder</strong><small>Available offline</small></div>
            </div>
        </div>
        <div class="cover-footer"><span>Scroll · swipe · arrow keys</span><span>Works on desktop and mobile</span></div>
    </section>

    <section class="slide" data-screen="platform" data-label="Choose your computer" aria-labelledby="platform-title">
        <div class="slide-inner">
            <p class="eyebrow">First, one quick question</p>
            <h2 id="platform-title">Which computer<br>are you setting up?</h2>
            <p class="lead">I’ll show the matching download and commands.</p>
            <div class="platform-grid">
                <button class="platform-card mac-card" type="button" data-platform-choice="mac" aria-label="Choose Mac">
                    <span class="platform-icon mac-icon" aria-hidden="true"><span></span></span>
                    <span class="platform-card-copy"><small>Apple</small><strong>Mac</strong><span>macOS · Bash and Python</span></span>
                    <span class="platform-arrow" aria-hidden="true">↗</span>
                </button>
                <button class="platform-card windows-card" type="button" data-platform-choice="windows" aria-label="Choose Windows">
                    <span class="platform-icon windows-icon" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
                    <span class="platform-card-copy"><small>Microsoft</small><strong>Windows PC</strong><span>PowerShell 5.1 · built in</span></span>
                    <span class="platform-arrow" aria-hidden="true">↗</span>
                </button>
            </div>
            <p class="assist-line"><span class="status-dot"></span> You can switch platforms at any time.</p>
        </div>
    </section>

    <section class="slide" data-screen="prepare" data-label="Before you begin" aria-labelledby="prepare-title">
        <div class="slide-inner">
            <p class="eyebrow">A few things to have ready</p>
            <h2 id="prepare-title">Let’s get the<br>right details.</h2>
            <p class="lead">You’ll add these to the script on your own computer. They are never sent to this guide page.</p>
            <div class="prep-grid">
                <article class="info-card"><span class="number-badge">01</span><div><h3>Files space ID</h3><p>Choose one Telmi OS Files space and copy its space ID.</p></div></article>
                <article class="info-card"><span class="number-badge">02</span><div><h3>Ghost JWT</h3><p>A Ghost credential that can read and write files in that space.</p></div></article>
                <article class="info-card"><span class="number-badge">03</span><div><h3>Telmi OS HTTPS address</h3><p>The deployment origin, such as <code>https://your-telmi-host.example</code>.</p></div></article>
                <article class="info-card"><span class="number-badge">04</span><div><h3>A private local folder</h3><p>Pick a folder outside public, shared, or automatically cloud-synced locations.</p></div></article>
            </div>
            <aside class="notice-card"><span class="notice-icon">!</span><p><strong>Start with a Files space that already has at least one file.</strong> The client stops if the remote inventory is empty or may be incomplete.</p></aside>
            <div class="slide-actions"><button class="button button-primary" type="button" data-next>Continue <span aria-hidden="true">→</span></button></div>
        </div>
    </section>

    <section class="slide" data-screen="download-mac" data-label="Get the Mac script" aria-labelledby="download-mac-title">
        <div class="slide-inner">
            <p class="eyebrow">Step 1 · Mac</p>
            <h2 id="download-mac-title">Get the Mac<br>sync script.</h2>
            <p class="lead">Download the text-safe file, then save it with its real <code>.sh</code> extension in a private folder.</p>
            <div class="download-panel">
                <div class="file-icon shell-file"><span>$_</span></div>
                <div class="download-copy"><strong>telmi-files-sync.sh.txt</strong><span>Bash script · HTTPS download</span><small>After downloading, rename it to <code>telmi-files-sync.sh</code>.</small></div>
                <a class="button button-primary download-button" href="downloads/telmi-files-sync.sh.txt" download="telmi-files-sync.sh.txt">Download script <span aria-hidden="true">↓</span></a>
            </div>
            <div class="command-card"><div class="command-head"><span>Save it and make it private</span><button type="button" class="copy-button" data-copy="mac-save">Copy</button></div><pre id="mac-save"><code>mkdir -p "$HOME/bin"
mv "$HOME/Downloads/telmi-files-sync.sh.txt" "$HOME/bin/telmi-files-sync.sh"
chmod 700 "$HOME/bin/telmi-files-sync.sh"</code></pre></div>
            <p class="small-note">If your browser saved the file somewhere else, update the source path in the <code>mv</code> command.</p>
            <div class="slide-actions"><button class="button button-primary" type="button" data-next>Next: configure it <span aria-hidden="true">→</span></button></div>
        </div>
    </section>

    <section class="slide" data-screen="download-windows" data-label="Get the Windows script" aria-labelledby="download-windows-title">
        <div class="slide-inner">
            <p class="eyebrow">Step 1 · Windows</p>
            <h2 id="download-windows-title">Get the Windows<br>sync script.</h2>
            <p class="lead">Download the text-safe file, then save it with its real <code>.ps1</code> extension in a private folder.</p>
            <div class="download-panel">
                <div class="file-icon powershell-file"><span>&gt;_</span></div>
                <div class="download-copy"><strong>telmi-files-sync-windows.ps1.txt</strong><span>PowerShell script · HTTPS download</span><small>After downloading, rename it to <code>telmi-files-sync-windows.ps1</code>.</small></div>
                <a class="button button-primary download-button" href="downloads/telmi-files-sync-windows.ps1.txt" download="telmi-files-sync-windows.ps1.txt">Download script <span aria-hidden="true">↓</span></a>
            </div>
            <div class="command-card"><div class="command-head"><span>Save it in your user folder</span><button type="button" class="copy-button" data-copy="windows-save">Copy</button></div><pre id="windows-save"><code>New-Item -ItemType Directory -Force "$env:USERPROFILE\bin"
Move-Item "$env:USERPROFILE\Downloads\telmi-files-sync-windows.ps1.txt" "$env:USERPROFILE\bin\telmi-files-sync-windows.ps1"</code></pre></div>
            <p class="small-note">You can also use File Explorer to move and rename it. Change the source path if your browser uses a different download folder.</p>
            <div class="slide-actions"><button class="button button-primary" type="button" data-next>Next: configure it <span aria-hidden="true">→</span></button></div>
        </div>
    </section>

    <section class="slide" data-screen="configure-mac" data-label="Configure Mac" aria-labelledby="configure-mac-title">
        <div class="slide-inner">
            <p class="eyebrow">Step 2 · Mac</p>
            <h2 id="configure-mac-title">Point it at<br>your Files space.</h2>
            <p class="lead">Open the script and replace the placeholders in its configuration block. Keep this file private because it contains your Ghost credential.</p>
            <div class="config-grid">
                <article class="config-card"><span class="config-label">Telmi OS deployment</span><code>TELMI_BASE_URL</code><p>Your HTTPS origin only. Leave off <code>/api/...</code> and the trailing slash.</p></article>
                <article class="config-card"><span class="config-label">Chosen Files space</span><code>TELMI_SPACE_ID</code><p>The ID of the one space to pair with this local folder.</p></article>
                <article class="config-card"><span class="config-label">Ghost credential</span><code>TELMI_GHOST_JWT</code><p>Use a dedicated JWT with the required Files-space access. Paste it locally; never send it in chat or commit it to Git.</p></article>
                <article class="config-card"><span class="config-label">Local destination</span><code>SYNC_DIR</code><p>Use a private folder, for example <code>$HOME/TelmiSync/TeamFiles</code>.</p></article>
            </div>
            <div class="command-card"><div class="command-head"><span>Check the tools, then open the script</span><button type="button" class="copy-button" data-copy="mac-config">Copy</button></div><pre id="mac-config"><code>command -v python3
python3 --version
command -v curl
nano "$HOME/bin/telmi-files-sync.sh"</code></pre></div>
            <div class="callout"><strong>macOS requirement</strong><span>System Bash 3.2 or newer, Python 3, and curl. No package installation or third-party Python packages.</span></div>
            <div class="slide-actions"><button class="button button-primary" type="button" data-next>Next: preview the plan <span aria-hidden="true">→</span></button></div>
        </div>
    </section>

    <section class="slide" data-screen="configure-windows" data-label="Configure Windows" aria-labelledby="configure-windows-title">
        <div class="slide-inner">
            <p class="eyebrow">Step 2 · Windows</p>
            <h2 id="configure-windows-title">Point it at<br>your Files space.</h2>
            <p class="lead">Open the script and replace the placeholders in its configuration block. Keep this file private because it contains your Ghost credential.</p>
            <div class="config-grid">
                <article class="config-card"><span class="config-label">Telmi OS deployment</span><code>TELMI_BASE_URL</code><p>Your HTTPS origin only. Leave off <code>/api/...</code> and the trailing slash.</p></article>
                <article class="config-card"><span class="config-label">Chosen Files space</span><code>TELMI_SPACE_ID</code><p>The ID of the one space to pair with this local folder.</p></article>
                <article class="config-card"><span class="config-label">Ghost credential</span><code>TELMI_GHOST_JWT</code><p>Use a dedicated JWT with the required Files-space access. Paste it locally; never send it in chat or commit it to Git.</p></article>
                <article class="config-card"><span class="config-label">Local destination</span><code>SYNC_DIR</code><p>Default: <code>%USERPROFILE%\TelmiSync\MySpace</code>. Change it in the config block.</p></article>
            </div>
            <div class="command-card"><div class="command-head"><span>Open the script in Notepad</span><button type="button" class="copy-button" data-copy="windows-config">Copy</button></div><pre id="windows-config"><code>notepad "$env:USERPROFILE\bin\telmi-files-sync-windows.ps1"</code></pre></div>
            <div class="callout"><strong>Windows requirement</strong><span>Windows PowerShell 5.1 is built in on Windows 10 and 11. No Python, curl, WSL, modules, or administrator access required.</span></div>
            <div class="slide-actions"><button class="button button-primary" type="button" data-next>Next: preview the plan <span aria-hidden="true">→</span></button></div>
        </div>
    </section>

    <section class="slide" data-screen="dry-run-mac" data-label="Preview the Mac plan" aria-labelledby="dry-run-mac-title">
        <div class="slide-inner">
            <p class="eyebrow">Step 3 · Mac</p>
            <h2 id="dry-run-mac-title">Preview before<br>anything syncs.</h2>
            <p class="lead">The dry run reads and validates the remote inventory, then prints the planned actions without changing files or sync state.</p>
            <div class="command-card command-card-large"><div class="command-head"><span>Run in Terminal</span><button type="button" class="copy-button" data-copy="mac-dry-run">Copy</button></div><pre id="mac-dry-run"><code>bash "$HOME/bin/telmi-files-sync.sh" --dry-run --verbose</code></pre></div>
            <div class="check-grid"><div><span class="checkmark">✓</span><span>See the expected nested folders and files.</span></div><div><span class="checkmark">✓</span><span>Check the proposed downloads, uploads, and replacements.</span></div><div><span class="checkmark">✓</span><span>Confirm the summary ends with <code>errors=0</code>.</span></div></div>
            <aside class="notice-card"><span class="notice-icon">!</span><p>It still makes API reads and uses a short-lived lock and temporary work folder. An empty space or a folder listing at its 500-item limit causes a hard stop.</p></aside>
            <div class="slide-actions"><button class="button button-primary" type="button" data-next>Looks good · do the first sync <span aria-hidden="true">→</span></button></div>
        </div>
    </section>

    <section class="slide" data-screen="dry-run-windows" data-label="Preview the Windows plan" aria-labelledby="dry-run-windows-title">
        <div class="slide-inner">
            <p class="eyebrow">Step 3 · Windows</p>
            <h2 id="dry-run-windows-title">Preview before<br>anything syncs.</h2>
            <p class="lead">The dry run reads and validates the remote inventory, then prints the planned actions without changing files or sync state.</p>
            <div class="command-card command-card-large"><div class="command-head"><span>Run in PowerShell</span><button type="button" class="copy-button" data-copy="windows-dry-run">Copy</button></div><pre id="windows-dry-run"><code>powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$env:USERPROFILE\bin\telmi-files-sync-windows.ps1" --dry-run --verbose</code></pre></div>
            <div class="check-grid"><div><span class="checkmark">✓</span><span>See the expected nested folders and files.</span></div><div><span class="checkmark">✓</span><span>Check the proposed downloads, uploads, and replacements.</span></div><div><span class="checkmark">✓</span><span>Confirm the summary ends with <code>errors=0</code>.</span></div></div>
            <aside class="notice-card"><span class="notice-icon">!</span><p>It still makes API reads, writes a diagnostic log, and uses a short-lived lock and temporary work folder. An empty space or a folder listing at its 500-item limit causes a hard stop.</p></aside>
            <div class="slide-actions"><button class="button button-primary" type="button" data-next>Looks good · do the first sync <span aria-hidden="true">→</span></button></div>
        </div>
    </section>

    <section class="slide" data-screen="first-mac" data-label="Start on Mac" aria-labelledby="first-mac-title">
        <div class="slide-inner">
            <p class="eyebrow">Step 4 · Mac</p>
            <h2 id="first-mac-title">Start by pulling<br>from the server.</h2>
            <p class="lead">The first pull downloads the current Files space to your local folder without uploading local files.</p>
            <div class="command-card command-card-large"><div class="command-head"><span>Run in Terminal</span><button type="button" class="copy-button" data-copy="mac-first-run">Copy</button></div><pre id="mac-first-run"><code>bash "$HOME/bin/telmi-files-sync.sh" --pull --verbose</code></pre></div>
            <div class="safety-grid"><article><span class="mini-icon">⇄</span><h3>Server wins conflicts</h3><p>If both sides changed, the server copy replaces the active local file and a timestamped local conflict copy is kept.</p></article><article><span class="mini-icon">◷</span><h3>Keep the state file</h3><p><code>.telmi-sync-state.json</code> remembers prior syncs. Keep it with this local folder.</p></article></div>
            <p class="small-note">Review the downloaded files before switching to full two-way sync. No file-delete API is used; remote deletions are not re-uploaded when recorded in the state.</p>
            <div class="slide-actions"><button class="button button-primary" type="button" data-next>Next: make it automatic <span aria-hidden="true">→</span></button></div>
        </div>
    </section>

    <section class="slide" data-screen="first-windows" data-label="Start on Windows" aria-labelledby="first-windows-title">
        <div class="slide-inner">
            <p class="eyebrow">Step 4 · Windows</p>
            <h2 id="first-windows-title">Start by pulling<br>from the server.</h2>
            <p class="lead">The first pull downloads the current Files space to your local folder without uploading local files.</p>
            <div class="command-card command-card-large"><div class="command-head"><span>Run in PowerShell</span><button type="button" class="copy-button" data-copy="windows-first-run">Copy</button></div><pre id="windows-first-run"><code>powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$env:USERPROFILE\bin\telmi-files-sync-windows.ps1" --pull --verbose</code></pre></div>
            <div class="safety-grid"><article><span class="mini-icon">⇄</span><h3>Server wins conflicts</h3><p>If both sides changed, the server copy replaces the active local file and a timestamped local conflict copy is kept.</p></article><article><span class="mini-icon">◷</span><h3>Keep the state file</h3><p><code>.telmi-sync-state.json</code> remembers prior syncs. Keep it with this local folder.</p></article></div>
            <p class="small-note">Review the downloaded files before switching to full two-way sync. No file-delete API is used; remote deletions are not re-uploaded when recorded in the state.</p>
            <div class="slide-actions"><button class="button button-primary" type="button" data-next>Next: make it automatic <span aria-hidden="true">→</span></button></div>
        </div>
    </section>

    <section class="slide" data-screen="schedule-mac" data-label="Finish Mac setup" aria-labelledby="schedule-mac-title">
        <div class="slide-inner">
            <p class="eyebrow">Step 5 · Mac</p>
            <h2 id="schedule-mac-title">Ready for two-way<br>sync when you are.</h2>
            <div class="final-grid">
                <div class="final-copy"><p class="lead">Run the script without an option for normal two-way sync. Test uploads and replacements with disposable files before scheduling it.</p>
                    <div class="command-card"><div class="command-head"><span>Full two-way sync</span><button type="button" class="copy-button" data-copy="mac-full-sync">Copy</button></div><pre id="mac-full-sync"><code>bash "$HOME/bin/telmi-files-sync.sh" --verbose</code></pre></div>
                    <a class="text-link" href="downloads/TELMI-FILES-SYNC-MACOS.md" download>Download the complete macOS guide <span aria-hidden="true">↓</span></a>
                    <p class="small-note">The full guide covers launchd, optional cron scheduling, logging, and troubleshooting. A scheduled run needs its own noninteractive credential configuration.</p>
                </div>
                <div class="recap-card"><span class="recap-kicker">Before unattended sync</span><ul><li>Review the dry-run plan</li><li>Complete one pull successfully</li><li>Test upload and replacement</li><li>Schedule only one sync job</li></ul></div>
            </div>
            <div class="slide-actions"><button class="button button-secondary" type="button" data-go="platform">Choose Windows instead</button><button class="button button-primary" type="button" data-next>Finish <span aria-hidden="true">→</span></button></div>
        </div>
    </section>

    <section class="slide" data-screen="schedule-windows" data-label="Finish Windows setup" aria-labelledby="schedule-windows-title">
        <div class="slide-inner">
            <p class="eyebrow">Step 5 · Windows</p>
            <h2 id="schedule-windows-title">Ready for two-way<br>sync when you are.</h2>
            <div class="final-grid">
                <div class="final-copy"><p class="lead">Run the script without an option for normal two-way sync. Test uploads and replacements with disposable files before scheduling it.</p>
                    <div class="command-card"><div class="command-head"><span>Full two-way sync</span><button type="button" class="copy-button" data-copy="windows-full-sync">Copy</button></div><pre id="windows-full-sync"><code>powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$env:USERPROFILE\bin\telmi-files-sync-windows.ps1" --verbose</code></pre></div>
                    <a class="text-link" href="downloads/telmi-files-sync-windows-guide.md" download>Download the complete Windows guide <span aria-hidden="true">↓</span></a>
                    <p class="small-note">The full guide covers Task Scheduler, logging, and troubleshooting. A scheduled task needs the credential available to its Windows account.</p>
                </div>
                <div class="recap-card"><span class="recap-kicker">Before unattended sync</span><ul><li>Review the dry-run plan</li><li>Complete one pull successfully</li><li>Test upload and replacement</li><li>Schedule only one sync task</li></ul></div>
            </div>
            <div class="slide-actions"><button class="button button-secondary" type="button" data-go="platform">Choose Mac instead</button><button class="button button-primary" type="button" data-next>Finish <span aria-hidden="true">→</span></button></div>
        </div>
    </section>

    <section class="slide finale-slide" data-screen="finish" data-label="You're ready" aria-labelledby="finish-title">
        <?php if ($endImage !== null): ?>
            <img class="cover-image" src="<?= filesSyncH($endImage) ?>" alt="" loading="lazy">
        <?php endif; ?>
        <div class="cover-wash finale-wash" aria-hidden="true"></div>
        <div class="slide-inner finale-inner">
            <div class="finale-mark"><img src="assets/mantis-mini.svg" alt=""></div>
            <p class="eyebrow">You’re all set</p>
            <h2 id="finish-title">Your Files are<br><span class="gradient-text">coming with you.</span></h2>
            <p class="lead">Your computer will sync the selected Files space using the credentials and schedule you configured locally.</p>
            <div class="finish-summary"><span class="finish-check">✓</span><span><strong id="finish-platform">Sync guide complete</strong><small>Keep the script, local folder, and state file private.</small></span></div>
            <div class="slide-actions"><button class="button button-primary" type="button" data-go="intro">Start over</button><button class="button button-secondary" type="button" data-go="platform">Change computer</button></div>
        </div>
    </section>
</main>

<footer class="page-footer"><span>Files Sync · Mantis Bat</span><span>Build <?= filesSyncH($build) ?></span></footer>
</body>
</html>

