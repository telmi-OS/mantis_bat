<p><img src="../../../assets/logo/mantis-mini.svg" width="64" alt="Mantis Bat"></p>

# Mantis Bat Ghost Shell Guide (PHP)

Ghost Shell is a guided PHP page for installing a shell-execution API on a user-managed Ubuntu 24.04 VM. The API accepts commands from a configured Ghost HTTP tool after the user explicitly unlocks it on the server.

The PHP module only serves the guide and installer download. It does not receive credentials, install the API, or execute commands on the guide host.

## Contents

- `public/index.php` — Guided and Read all install guide
- `public/downloads/install-ghost-shell.sh.txt` — text-safe Ubuntu 24.04 installer
- `public/assets/images/ghost-shell-start.webp` — optional intro image
- `public/assets/images/ghost-shell-end.webp` — optional final image
- `src/Config.php` and `storage/config.example.php` — optional title, timezone and local image settings

The guide works without images or a live configuration file. Add the two images at the paths above to use custom artwork.

## Important capability

The installer gives the API service account passwordless sudo. Once activated, an authorized request can execute shell commands with root-capable privileges on the VM. Use a dedicated system, keep backups, protect the bearer token, and restrict inbound network access.

The activation pin is initially locked. The user must connect to the VM and replace `LOCKED` in `/etc/ghost-shell/activation.txt` with the exact phrase shown in the guide. The API checks the file before accepting command execution or job polling. This is a deliberate activation step; it is not a sandbox after access is enabled.

## Existing Caddy configuration

If `/etc/caddy/Caddyfile` exists, the installer warns that continuing will back it up and replace it. The operator must type `REPLACE` to proceed. Any other response cancels before system changes begin.

## Hosting layout

The Mantis Bat catalog entrypoint is `index.php`. The deployment keeps `src/` and `storage/` outside the web root and serves the module's `public/` directory. For a separate PHP host, expose only `public/` as the document root.

## Packaging

From the repository root:

```bash
tools/ghost-shell/php/scripts/package-release.sh 1.0.0
```

The archive contains the guide, its assets, optional configuration example and text-safe installer download. It does not include a live `storage/config.php`.
