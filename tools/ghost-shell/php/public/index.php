<?php

declare(strict_types=1);

require __DIR__ . '/_runtime.php';

$config = $services['config'];
$title = (string) $config->get('app.title', 'Ghost Shell');
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
    <meta name="description" content="Install Ghost Shell on a Linux system and deliberately enable shell access for your Ghost.">
    <title><?= ghostShellH($title) ?> | Mantis Bat</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Share+Tech+Mono&display=swap">
    <link rel="stylesheet" href="assets/theme.css?v=<?= ghostShellH($build) ?>">
    <script src="assets/guide.js?v=<?= ghostShellH($build) ?>" defer></script>
</head>
<body>
<header class="topbar">
    <a class="brand" href="index.php" aria-label="Ghost Shell guide home">
        <img src="assets/mantis-mini.svg" alt="">
        <span><small>Standalone Mantis Bat tool</small><strong><?= ghostShellH($title) ?></strong></span>
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
            <img class="cover-image" src="<?= ghostShellH($startImage) ?>" alt="" fetchpriority="high">
        <?php endif; ?>
        <div class="cover-wash" aria-hidden="true"></div>
        <div class="slide-inner cover-inner">
            <div class="cover-copy">
                <div class="eyebrow"><span class="eyebrow-spark"></span> Your server, within reach</div>
                <p class="assistant-greeting"><img src="assets/mantis-mini.svg" alt=""><span><strong>Mantis guide</strong><br>Hi! I’ll walk you through installing Ghost Shell on a Linux system you control.</span></p>
                <h1 id="welcome-title">Give your Ghost<br><span class="gradient-text">a Linux shell.</span></h1>
                <p class="cover-lead">Ghost Shell installs a small API on your server so your Ghost can run shell commands there. You choose when to unlock it.</p>
                <div class="cover-actions">
                    <button class="button button-primary" type="button" data-next>Read the safety warning <span aria-hidden="true">→</span></button>
                    <span class="quiet-note">The API runs on your Linux host, not on this guide page.</span>
                </div>
                <div class="cover-pills"><span>Ubuntu 24.04</span><span>HTTPS API</span><span>Locked by default</span></div>
            </div>
            <div class="cover-visual" aria-hidden="true">
                <div class="orbit orbit-one"></div><div class="orbit orbit-two"></div>
                <div class="sync-core"><span class="core-cloud">✦</span><span class="core-arrow">↔</span><span class="core-folder">⌘</span></div>
                <div class="float-card cloud-card"><i>GHOST</i><strong>Authorized agent</strong><small>Only after you unlock</small></div>
                <div class="float-card local-card"><i>YOUR LINUX HOST</i><strong>Shell API</strong><small>Managed by you</small></div>
            </div>
        </div>
        <div class="cover-footer"><span>Scroll · swipe · arrow keys</span><span>Works on desktop and mobile</span></div>
    </section>

    <section class="slide" data-screen="warning" data-label="Read this first" aria-labelledby="warning-title">
        <div class="slide-inner">
            <p class="eyebrow">Important · read before installing</p>
            <h2 id="warning-title">Your Ghost gets<br>full shell access.</h2>
            <p class="lead">After you unlock Ghost Shell, the API accepts shell commands from your Ghost. Those commands run as a service account with passwordless sudo, which can modify or delete data, install software, change system settings, expose services, or damage this VM.</p>
            <div class="warning-panel">
                <strong>Use a dedicated, disposable Linux VM. Keep backups of anything you care about.</strong>
                <p>Teleport AI does not manage your server and is not responsible for data loss or damage caused by granting an AI access to your VM. Install and use Ghost Shell at your own risk.</p>
            </div>
            <div class="check-grid">
                <div><span class="checkmark">✓</span><span>You control the Linux system and have authority to grant this access.</span></div>
                <div><span class="checkmark">✓</span><span>You understand shell access can cause irreversible changes.</span></div>
                <div><span class="checkmark">✓</span><span>You will manually unlock the API on the server after setup.</span></div>
            </div>
            <p class="small-note">The activation pin adds a deliberate setup step. Once enabled, it does not restrict what shell commands Ghost can run.</p>
            <div class="slide-actions"><button class="button button-primary" type="button" data-next>Continue to requirements <span aria-hidden="true">→</span></button></div>
        </div>
    </section>

    <section class="slide" data-screen="requirements" data-label="Before you begin" aria-labelledby="requirements-title">
        <div class="slide-inner">
            <p class="eyebrow">Before you begin</p>
            <h2 id="requirements-title">Prepare a server<br>you can replace.</h2>
            <div class="prep-grid">
                <article class="info-card"><span class="number-badge">01</span><div><h3>Dedicated Ubuntu VM</h3><p>The installer supports Ubuntu 24.04 LTS with systemd. Use a fresh VM reserved for this purpose.</p></div></article>
                <article class="info-card"><span class="number-badge">02</span><div><h3>Domain and HTTPS</h3><p>Point a public DNS name at the VM. Inbound TCP ports 80 and 443 must be allowed so Caddy can obtain a TLS certificate.</p></div></article>
                <article class="info-card"><span class="number-badge">03</span><div><h3>Ghost caller IP</h3><p>Know the public outbound IP address used by the machine that will call this API. The installer restricts requests to that address or CIDR.</p></div></article>
                <article class="info-card"><span class="number-badge">04</span><div><h3>Server access</h3><p>You need administrative access to install packages, configure the service and edit the activation file later.</p></div></article>
            </div>
            <aside class="notice-card"><span class="notice-icon">!</span><p>If the Ghost caller uses changing or unknown outbound IPs, the allowlist may block it. Don’t remove the IP restriction as a workaround.</p></aside>
            <div class="slide-actions"><button class="button button-primary" type="button" data-next>Next: review the installer <span aria-hidden="true">→</span></button></div>
        </div>
    </section>

    <section class="slide" data-screen="download" data-label="Download and inspect" aria-labelledby="download-title">
        <div class="slide-inner">
            <p class="eyebrow">Step 1 · installer</p>
            <h2 id="download-title">Inspect the script<br>before running it.</h2>
            <p class="lead">The installer adds a service account, a FastAPI application, a systemd service, Caddy and firewall rules. It also installs a passwordless sudo rule for the service account.</p>
            <div class="download-panel">
                <div class="file-icon shell-file"><span>{ }</span></div>
                <div class="download-copy"><strong>install-ghost-shell.sh</strong><span>Ubuntu 24.04 installer</span><small>Plain text source. Review it before running as root.</small></div>
                <a class="button button-primary download-button" href="downloads/install-ghost-shell.sh.txt" download>Download installer <span aria-hidden="true">↓</span></a>
            </div>
            <div class="command-card"><div class="command-head"><span>Review on the server</span><button type="button" class="copy-button" data-copy="review-script">Copy</button></div><pre id="review-script"><code>less install-ghost-shell.sh.txt</code></pre></div>
            <aside class="notice-card"><span class="notice-icon">!</span><p>The installer changes system configuration. If it finds an existing <code>/etc/caddy/Caddyfile</code>, it warns you and asks you to type <code>REPLACE</code> before proceeding. Confirming backs up and replaces that file; other sites configured there may stop working.</p></aside>
            <div class="slide-actions"><button class="button button-primary" type="button" data-next>Next: install the API <span aria-hidden="true">→</span></button></div>
        </div>
    </section>

    <section class="slide" data-screen="install" data-label="Install on Linux" aria-labelledby="install-title">
        <div class="slide-inner">
            <p class="eyebrow">Step 2 · on your Linux VM</p>
            <h2 id="install-title">Install the API<br>and keep it locked.</h2>
            <ol class="step-list">
                <li><strong>Copy the downloaded script to the VM.</strong><span>Use your normal secure file transfer method.</span></li>
                <li><strong>Run it as root.</strong><span>The installer prompts for the public hostname and the Ghost caller IP/CIDR, then asks you to confirm installation.</span></li>
                <li><strong>Review any Caddyfile warning.</strong><span>If you type <code>REPLACE</code>, the existing file is backed up and replaced with the Ghost Shell configuration.</span></li>
                <li><strong>Save the bearer token securely.</strong><span>The installer prints the token once. You will add it to the Ghost HTTP tool configuration.</span></li>
                <li><strong>Check the health URL.</strong><span>Use the HTTPS URL printed by the installer. The health response confirms the service is running; it does not unlock command execution.</span></li>
            </ol>
            <div class="command-card"><div class="command-head"><span>Example install command</span><button type="button" class="copy-button" data-copy="install-command">Copy</button></div><pre id="install-command"><code>sudo bash install-ghost-shell.sh.txt</code></pre></div>
            <p class="small-note">The API listens on loopback port 8000 behind Caddy. Do not open port 8000 in the cloud firewall. The installer may allow ports 80 and 443 through UFW if it is active.</p>
            <div class="slide-actions"><button class="button button-primary" type="button" data-next>Next: unlock only when ready <span aria-hidden="true">→</span></button></div>
        </div>
    </section>

    <section class="slide" data-screen="activation" data-label="Unlock access" aria-labelledby="activation-title">
        <div class="slide-inner">
            <p class="eyebrow">Step 3 · deliberate activation</p>
            <h2 id="activation-title">Unlock it on<br>the server yourself.</h2>
            <p class="lead">The API is installed in a locked state. Connect to the VM and replace the contents of the activation file with the exact sentence below. Until then, execution and job polling return a locked response.</p>
            <div class="command-card command-card-large"><div class="command-head"><span>Activation file</span><button type="button" class="copy-button" data-copy="activation-file">Copy</button></div><pre id="activation-file"><code>sudo nano /etc/ghost-shell/activation.txt</code></pre></div>
            <div class="warning-panel activation-phrase"><p>Write this exact sentence in the file, replacing <code>LOCKED</code>:</p><strong>I have understood the risk and therefore explicitly grant access to this VM to my Ghost via Ghost Shell.</strong></div>
            <div class="command-card"><div class="command-head"><span>Revoke access later</span><button type="button" class="copy-button" data-copy="revoke-access">Copy</button></div><pre id="revoke-access"><code>echo LOCKED | sudo tee /etc/ghost-shell/activation.txt</code></pre></div>
            <aside class="notice-card"><span class="notice-icon">!</span><p>This pin is an activation safeguard, not a sandbox. After activation, Ghost has the shell and sudo access described in the warning. It can change files and services on the VM.</p></aside>
            <div class="slide-actions"><button class="button button-primary" type="button" data-next>Next: connect your Ghost <span aria-hidden="true">→</span></button></div>
        </div>
    </section>

    <section class="slide" data-screen="connect" data-label="Connect your Ghost" aria-labelledby="connect-title">
        <div class="slide-inner">
            <p class="eyebrow">Step 4 · Ghost HTTP tools</p>
            <h2 id="connect-title">Add two HTTP<br>tools to your Ghost.</h2>
            <p class="lead">Use the API URL printed by the installer. Both tools need the bearer token and must be called from an allowlisted source IP.</p>
            <div class="safety-grid">
                <article><span class="mini-icon">↗</span><h3>Submit a command</h3><p><strong>POST</strong> <code>https://YOUR_HOST/exec</code><br>Header: <code>Authorization: Bearer YOUR_TOKEN</code></p><pre><code>{"command":"uname -a","timeout":60,"wait":false}</code></pre><p>The response contains a <code>job_id</code>.</p></article>
                <article><span class="mini-icon">⌕</span><h3>Get its result</h3><p><strong>GET</strong> <code>https://YOUR_HOST/jobs</code><br>Headers: bearer token and <code>X-Job-Id: JOB_ID</code></p><p>Poll the same job until its status is <code>done</code>, <code>timeout</code> or <code>error</code>.</p></article>
            </div>
            <aside class="notice-card"><span class="notice-icon">!</span><p>Keep the token private. Anyone who has the token and can call from an allowlisted IP can submit shell commands with passwordless sudo on this VM.</p></aside>
            <div class="slide-actions"><button class="button button-primary" type="button" data-next>Next: operate carefully <span aria-hidden="true">→</span></button></div>
        </div>
    </section>

    <section class="slide" data-screen="operate" data-label="Operate and remove" aria-labelledby="operate-title">
        <div class="slide-inner">
            <p class="eyebrow">Ongoing operation</p>
            <h2 id="operate-title">Keep the host<br>isolated and backed up.</h2>
            <div class="safety-grid">
                <article><span class="mini-icon">⌑</span><h3>Protect the VM</h3><p>Use a dedicated instance, restrict cloud firewall ingress, keep the operating system updated, and maintain backups outside the VM.</p></article>
                <article><span class="mini-icon">◈</span><h3>Protect the token</h3><p>Do not place the token in source code, public chat or logs. Rotate it if exposed.</p></article>
                <article><span class="mini-icon">⏻</span><h3>Stop Ghost access</h3><p>Change the activation file back to <code>LOCKED</code> or remove the activation sentence. Revoke the token and stop the service if you no longer need it.</p></article>
                <article><span class="mini-icon">⌫</span><h3>Retire the VM</h3><p>When finished, remove the VM if it was created for this purpose. Check cloud snapshots and backups separately.</p></article>
            </div>
            <p class="small-note">The job list is held in memory and is lost if the service restarts. The installer currently does not impose limits on command timeout, concurrent jobs or retained output.</p>
            <div class="slide-actions"><button class="button button-primary" type="button" data-next>Finish <span aria-hidden="true">→</span></button></div>
        </div>
    </section>

    <section class="slide finale-slide" data-screen="finish" data-label="You're ready" aria-labelledby="finish-title">
        <?php if ($endImage !== null): ?>
            <img class="cover-image finale-image" src="<?= ghostShellH($endImage) ?>" alt="">
        <?php endif; ?>
        <div class="cover-wash" aria-hidden="true"></div>
        <div class="slide-inner finale-inner">
            <p class="eyebrow">Ghost Shell</p>
            <h2 id="finish-title">You decide when<br>the shell is open.</h2>
            <p class="lead">Review the installer, secure the server and token, and keep the activation file locked until you are ready to grant access.</p>
            <a class="button button-primary" href="downloads/install-ghost-shell.sh.txt" download>Download the installer <span aria-hidden="true">↓</span></a>
        </div>
    </section>
</main>
<footer class="site-footer"><span>Ghost Shell · Mantis Bat</span><span>Build <?= ghostShellH($build) ?></span></footer>
</body>
</html>
