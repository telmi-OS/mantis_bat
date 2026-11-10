<p><img src="../assets/logo/mantis-mini.svg" width="56" alt="Mantis Bat"></p>

# Ghost Shell Tool

Ghost Shell is a guided installer for a shell API running on a user-managed Ubuntu 24.04 VM. Its PHP page follows the Mantis Bat guide pattern: it provides instructions and a downloadable installer, while the API runs on the user's own Linux system.

The installer creates a service account with passwordless sudo. After activation, an authorized Ghost can run arbitrary shell commands with root-capable privileges on that VM. Use a dedicated, disposable host and keep backups of anything important. Teleport AI does not manage the host and is not responsible for loss or damage caused by granting an AI access to it. Install and use the tool at your own risk.

The API is locked by default. To activate it, the user must connect to the server and replace `LOCKED` in `/etc/ghost-shell/activation.txt` with the phrase:

> I have understood the risk and therefore explicitly grant access to this VM to my Ghost via Ghost Shell.

The pin adds an explicit activation step, but it does not restrict shell access after activation.

## Files

- [Installer download](../tools/ghost-shell/php/public/downloads/install-ghost-shell.sh.txt)
- [Mantis Bat module README](../tools/ghost-shell/php/README.md)

## Requirements

- Ubuntu 24.04 LTS VM with systemd
- Public DNS name pointing to the VM
- Inbound TCP 80 and 443 for Caddy and HTTPS
- Stable public egress IP for the Ghost caller, to add to the allowlist
- Administrative access to install and activate the service

The installer configures Caddy and the API. If `/etc/caddy/Caddyfile` already exists, it warns that the file will be backed up and replaced, and requires the operator to type `REPLACE` before continuing.

See the module README and the guided page for install, activation, API configuration, and operating instructions.
