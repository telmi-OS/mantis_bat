<p><img src="../../../assets/logo/mantis-mini.svg" width="64" alt="Mantis Bat"></p>

# Mantis Bat Private Mail PHP Connector

Private Mail is a standalone Mantis Bat connector for telmi OS Hades. It connects one user-owned mailbox through IMAP and SMTP, mirrors its messages and handling state in private SQLite storage, provides a protected mail app, and installs Ghost API v2 presets for private mailbox work.

## Requirements

- PHP 8.1+
- cURL
- PDO SQLite
- OpenSSL streams
- mbstring and iconv
- HTTPS for the app
- Outbound TLS access to the mailbox IMAP and SMTP servers, Ghost API v2, and Ghost Files API
- One mailbox account with IMAP and SMTP credentials
- A Ghost API v2 JWT with settings access and write access to one Files space

The connector uses PHP streams for IMAP and does not require `ext-imap`, Composer, an external mail client, or an operating-system package. TLS peer and host verification stay enabled.

## What It Does

- syncs server INBOX, Sent, and Ghost Deleted folders into SQLite, including read and replied flags
- removes local records when the corresponding server message disappears
- creates a quiet initial mailbox baseline without summaries, notifications, replies, drafts, or automatic actions
- applies exact sender-plus-subject rules before sender-only rules; defaults new senders to **summarize and notify**
- summarizes every non-ignored incoming message through realtime Ghost chat and stores the summary
- sends notifications through realtime Ghost chat to one configured owner or group
- queues draft and auto handling tasks through Ghost chat without polling the Ghost inbox
- provides a protected app for reading, searching, composing, replying, forwarding, deleting, rule management, and user-only restore or permanent deletion from Ghost Deleted
- provides an authenticated Ghost API for mail search, read, send, reply, forward, recoverable delete, attachment requests, recipient-rule checks, and rule management
- downloads requested attachments only from IMAP, uploads them to the configured Ghost Files space, and removes the temporary local copy
- keeps a private rolling diary with context from about the 50 most recent incoming and outgoing messages
- backs up existing Ghost settings privately before adding `private_mail_*` curl presets while preserving other settings and presets

## Install On Hades

Mantis Bat discovers this module at `connectors/private-mail/php/README.md` and stages `public/` as the web surface while keeping `src/` and `storage/` in private Hades instance storage. The catalog entrypoint is `install.php`.

1. Sync the Mantis Bat module catalog and choose **Private Mail (PHP)**.
2. Create the Hades PHP instance and open its installer.
3. Enter the public app URL, timezone, owner name and email, optional text signature, IMAP and SMTP credentials, Ghost API v2 base and JWT, and independent RAG and history choices.
4. Choose the server folders for INBOX and Sent, a Ghost Files space, and one notification destination: the Ghost owner or an available group. Private Mail creates the separate Ghost Deleted folder when needed.
5. Wait for the initial baseline sync and Ghost preset installation to finish. Save the protected App, Cron, Status, Health, and Installer unlock URLs.
6. Configure Hades cron to call the private Cron URL once per minute (`* * * * *`). If a sync is still running, the next call is skipped to prevent overlapping workers.

After installation, Hades **Open** opens the mailbox in the setup browser session. In a new session, enter the access token from the saved App URL (the value after `key=`), or open the complete App URL directly. The installer unlock URL remains separate for configuration changes.

The installer creates the initial baseline before enabling Ghost processing. Existing messages in the baseline do not trigger summaries, notifications, drafts, or replies. After that baseline, messages found in INBOX use the matching rule or the default summarize-and-notify rule.

The configuration, credentials, SQLite database, sync state, temporary attachment directory, logs, diary, and Ghost settings backup belong in private Hades storage outside the web root. Do not expose `src/` or `storage/`.

## Install On A Separate PHP Host

Expose only `public/` through the HTTPS web server. Keep `src/` and `storage/` private and writable by PHP. Open `public/install.php`, complete the connection and folder setup, and schedule the generated `public/cron.php?key=...` URL once per minute.

## Mailbox Synchronization

The cron worker synchronizes server INBOX, Sent, and Ghost Deleted by IMAP UID and UIDVALIDITY. It mirrors message fields, addresses, attachment metadata, read and replied flags, message additions, and server-side deletions. Provider folder names vary; select the correct INBOX and Sent roles during installation. Ghost Deleted is a separate server folder used for recoverable deletes.

Attachments stay on the server during normal synchronization. The connector records filenames, MIME types, sizes, part IDs, content IDs, and disposition. An explicit Ghost attachment request uses IMAP size metadata to calculate a conservative decoded-size bound before fetching the part. It then decodes the MIME transfer encoding, checks the 2 MiB limit, uploads the file to the configured Files space, and deletes the temporary copy. Parts known to exceed 2 MiB are never fetched; the API returns an explanatory response. Multiple parts may be requested independently.

The app can restore or permanently delete messages from Ghost Deleted. The Ghost API can move messages into Ghost Deleted, but cannot restore or permanently delete them. Safe permanent deletion requires IMAP UIDPLUS support; moving requires IMAP MOVE or UIDPLUS support.

## Handling Rules

Rules may be created before an address has received email. An incoming rule can target a sender address or that address plus an exact subject. The exact-subject rule takes precedence. Supported actions are `ignore`, `notify`, `summarize_notify`, `draft`, and `auto`.

For a new email, the Ghost checks rules for every resolved To, Cc, and Bcc recipient, including addresses found through memory or another tool, and includes the final subject. Any non-auto recipient or missing rule requires direct-chat approval. If recipients or subject change, the Ghost checks again during planning.

For incoming draft or auto handling, the connector resolves reply-all recipients and the reply subject and checks their rules before submitting one Ghost mission. Reply-all uses Reply-To or From plus the original To and Cc, excluding the owner and duplicates. The mission includes approval when the incoming rule is draft or any reply recipient is non-auto. It directs the Ghost to keep those recipients and not call the rule-check preset again for that reply.

The `private_mail_draft` preset calls the deterministic send endpoint. Submitting a complete message sends it; the endpoint does not save a draft or check approval. The Ghost follows the mission or preset instructions to obtain any required approval before submission. The backend trusts the Ghost to do so.

Forward operations include the source email fields and body, plus any added text. They do not attach the source message’s binary attachments.

## Ghost Processing

- Summaries use realtime Ghost chat and focus on the newest message in a thread. A thread remains one email body; quoted text is not parsed into separate addresses or messages.
- Notifications use `Tell {configured name} New mail from …` through realtime chat. The configured owner, Ghost, or group name is used directly.
- Drafts, replies, auto actions, and other complex mail work use queued Ghost chat and the same Ghost’s `private_mail_*` presets.
- There is no Ghost inbox poller. The queued task directs the Ghost to return completed mail directly through the authenticated API.
- The task includes separate From, To, Cc, Bcc, subject, body, timestamp, message ID, stored summary, applicable handling rule, configured destination, and recent diary context.
- Submitting the completed reply to the send endpoint is the final mission action.

RAG and history are configured independently in the installer. Each incoming email’s processing status, handling flag, summary, and processing errors are visible in the app and Status endpoint.

## Ghost API And Presets

All Ghost API operations use `X-Mail-Key`. The installer adds these private presets and preserves unrelated Ghost settings and presets:

After deploying an update, use **Update Ghost presets** in the protected app to back up current Ghost settings and refresh the preset instructions without reinstalling the connector.

- `private_mail_rules_list`, `private_mail_rules`, and `private_mail_rule_delete`
- `private_mail_recipient_rules`
- `private_mail_search` and `private_mail_read`
- `private_mail_draft`, `private_mail_reply`, and `private_mail_forward`
- `private_mail_delete` and `private_mail_attachment`

Search accepts sender, recipient, subject, body, summary, timestamp, folder, message ID, status, handling flag, and attachment filename, MIME type, content ID, or presence filters. Results return summaries and related metadata; retrieve a selected message by ID for its body. Each message exposes From, To, Cc, Bcc, subject, body, timestamp, folder, message ID, new/read/replied status, handling flag, summary, and attachment metadata separately.

For each send, provide a unique `idempotency_key` so a retry cannot send the same mail twice. If delivery or task submission cannot be confirmed, the connector records that uncertainty and does not blindly repeat the side effect.

## Security And Operations

- Keep IMAP and SMTP passwords, the Ghost JWT, app and cron URLs, API key, and installer unlock URL private.
- Expose only `public/`; keep `src/` and `storage/` outside the web root.
- Use TLS for IMAP, SMTP, Ghost API, and the app.
- Protect `X-Mail-Key`; the API accepts it in the request header and does not accept a query-string key.
- Ghost email content is treated as untrusted data in summary and task prompts.
- The app uses a private bearer URL to establish a session and CSRF tokens for state-changing browser actions.
- `public/status.php?key=...` reports masked connection details, folder counts, pending work, uncertain submissions, and settings backup filenames.
- The protected Status URL also shows the ten most recent Ghost API responses, including chat mode, HTTP status, response status, job ID, and any reply. Settings responses are omitted because they can contain preset credentials. Responses may contain private mail information; keep the Status URL private.
- `public/health.php?key=...` reports runtime requirements and local folder health.

## Release Packaging

From the repository root:

```bash
connectors/private-mail/php/scripts/package-release.sh 0.1.0
```

The archive contains the public app, private source, README, and safe config example. It excludes live configuration, SQLite files, logs, locks, temporary attachments, and Ghost settings backups.
