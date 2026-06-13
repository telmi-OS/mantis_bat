<p><img src="../assets/logo/mantis-mini.svg" width="56" alt="Mantis Bat"></p>

# Security

Mantis Bat is a private connector. Security is not an optional hardening pass after features.

## Security Model

The connector is designed to expose a private Ghost through a user-owned channel without making the channel public.

Baseline controls:

- Telegram webhook secret validation
- one-time pairing code flow
- authorized Telegram user check
- installer CSRF token
- installer rate limiting
- webhook and HTTP cron rate limiting
- secret-bearing config outside the public web root where possible
- lock file and overlap protection for cron
- installer lock after setup
- secret-protected status and health endpoints
- secret-protected pairing recovery and maintenance endpoints
- secret redaction in logs

## Secrets

Never commit:

- Telegram bot token
- Ghost JWT
- runtime `config.php`
- SQLite database files
- logs with sensitive payloads

## Deployment Shape

The intended deployment shape is simple:

- expose `public/`
- keep `storage/`, `src/`, and `templates/` private
- keep tokens and secret URLs private
- use the maintenance page instead of manual file deletion when possible

## Private Operational URLs

After install, these endpoints should be treated like credentials:

- `cron.php?key=...`
- `status.php?key=...`
- `health.php?key=...`
- `pairing.php?key=...`
- `maintenance.php?key=...`

Do not post them in screenshots, GitHub issues, social media, or public support threads.

## Pairing

Even if the bot belongs to the installer, pairing is still required.

Why:

- random Telegram users can discover a bot
- channel ownership does not equal user authorization
- `v0.1.0` is private and single-owner by default

## telmi OS Security Context

Public Teleport AI materials stress ownership, governed memory, boundaries, approvals, and no surveillance-style business logic.

The connector should reflect that in practical ways:

- do not over-collect message data
- do not expose raw upstream responses to end users
- do not imply unrestricted Ghost action capability
- keep approval-sensitive action semantics on the telmi side
