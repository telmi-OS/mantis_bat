# Google Calendar PHP Connector

The standalone Google Calendar connector connects one user's selected Google Calendar to one telmi OS Ghost. It is independent of the Telegram connector and RAG prep tool; it has its own installer, database, frontend, cron endpoint, and bundled plain-PHP encryption.

## Behavior

- Google Calendar is the source of truth.
- The connector considers the rolling next 28 days.
- Timed events overlapping the window are included.
- All-day events are included when they overlap the future window; all-day events that ended before it are ignored.
- Recurring series are expanded into one event occurrence per calendar item.
- Each occurrence is retained locally, so a new occurrence becomes pending as it enters the rolling window.
- Only the exact title and date/time are sent to the Ghost. Descriptions, attachments, attendees, and links are intentionally omitted.
- Cancelled events are sent as cancelled schedules so the Ghost can remove them.
- Events that age out of the window are retained and are not treated as deletions.
- All pending changes are sent in one natural-language prompt per sync.
- The Ghost receives the configured group name exactly as entered and handles schedule work, conflicts, questions, and group messages.

The connector matches Board schedules by title and date/time. It does not inspect or evaluate Board state itself. This follows the public Ghost API v2 contract, where Board work is performed conversationally through POST /chat.

## Install

1. Upload `connectors/google-calendar/php/`.
2. Expose only its `public/` directory through the web server.
3. Keep `storage/` private and writable by PHP and cron.
4. Open `public/install.php`.
5. Set an application password of at least 12 characters.
6. Enter the Google OAuth web-application client ID and secret.
7. Enter the Ghost API base URL and JWT.
8. Enter the target group name exactly as telmi OS presents it.
9. Finish installation and open the dashboard.
10. Connect Google, then select exactly one calendar.
11. Configure the sync prompt and interval.

The Google OAuth callback is:

```text
<application-base-url>/oauth_callback.php
```

Register that exact URL in the Google Cloud OAuth client. The connector requests read-only Calendar access with offline access so cron can refresh the access token.

## Cron

The installer displays a private URL like:

```text
https://example.com/connectors/google-calendar/public/cron.php?key=...
```

Call it every 15 minutes or less frequently. The connector enforces the configured interval and prevents overlapping runs with a file lock. Available dashboard intervals are 15 minutes, 30 minutes, 1 hour, 3 hours, and 12 hours.

The cron unlock key is stored in `storage/cron-unlock.key`. Keep it outside the public directory with restrictive permissions. It allows unattended decryption for cron; the browser still requires the application password.

The same cron key protects `public/status.php` and `public/health.php` for operational checks.

For CLI cron, use:

```text
*/15 * * * * php /path/to/connectors/google-calendar/php/public/cron.php
```

## Encryption

Stock SQLite does not encrypt database pages. This connector encrypts sensitive values and event payloads before writing them to SQLite with a dependency-free, authenticated pure-PHP scheme. It requires no Sodium, OpenSSL, SQLCipher, Composer, or Google PHP client module.

The application password wraps the data key. A separate private cron unlock key wraps the same data key for unattended scheduled operation. Changing the application password rewraps the data key without rewriting calendar data.

## Prompt template

The default prompt is stored in:

```text
connectors/google-calendar/php/templates/sync-prompt.txt
```

The dashboard copies it into encrypted settings and provides a text area for maintenance. Supported placeholders are:

```text
{{group_name}}
{{submission_id}}
{{from}}
{{to}}
{{items}}
```

Board matching intentionally uses only title and date/time. Two Google events with an identical title and identical date/time are therefore indistinguishable to the Ghost.

## Error reporting

When a calendar, OAuth, prompt-size, or Ghost transport error occurs, the connector attempts one natural-language message:

```text
Send a message to group <configured group name>. Google Calendar Sync App reported the following error: <error>.
```

Errors are also retained in the local sync history. If the Ghost itself is unreachable, the dashboard and cron response remain the available diagnostics.

## Release parity

Hosted and self-hosted deployments use the same connector directory and release package. Environment-specific values are entered during installation and stored in the private installation state; there are no hosted-only code paths.
