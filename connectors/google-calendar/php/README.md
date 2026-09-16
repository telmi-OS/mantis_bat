# Mantis Bat Google Calendar PHP Connector

This is a standalone connector for connecting one user's selected Google Calendar to one telmi OS Ghost. It shares no runtime resources with the Telegram connector or RAG prep tool.

## Requirements

- PHP 8.1+
- cURL
- PDO SQLite
- HTTPS
- a Google OAuth web-application client
- a Ghost JWT

No Sodium, OpenSSL, SQLCipher, Composer, or Google PHP client module is required. Encryption and Google OAuth requests use code shipped in this connector.

## Behavior

- one selected Google Calendar per installation
- rolling next 28-day window
- recurring events expanded into individual occurrences
- only title and date/time are sent to the Ghost
- cancelled events are sent as cancellation instructions
- all pending changes are sent in one natural-language prompt
- Board schedules and group notifications are handled by the Ghost
- configured group name is preserved exactly as entered
- sync intervals: 15 minutes, 30 minutes, 1 hour, 3 hours, or 12 hours

## Install

Expose only `public/` through the web server and keep `storage/` private. Open `public/install.php` and enter:

- application base URL and password
- Google OAuth client ID and secret
- Ghost API base URL and JWT
- target group name

Register `<base-url>/oauth_callback.php` as an authorized redirect URI in Google Cloud. After installation, log in, connect Google, and select exactly one calendar.

## Cron

The installer displays the authenticated `public/cron.php?key=...` URL. Schedule it every 15 minutes or less frequently. A filesystem lock prevents overlapping runs. The private `storage/cron-unlock.key` allows unattended cron decryption.

The same cron key protects `public/status.php` and `public/health.php` for operational checks.

For CLI cron, use:

```text
*/15 * * * * php /path/to/connectors/google-calendar/php/public/cron.php
```

## Prompt

The default prompt is `templates/sync-prompt.txt`. The dashboard stores an encrypted copy and lets the operator edit it as a text field. Placeholders:

`{{group_name}}`, `{{submission_id}}`, `{{from}}`, `{{to}}`, `{{items}}`

Board matching intentionally uses only title and date/time. Two Google events with an identical title and identical date/time are therefore indistinguishable to the Ghost.

## Security

The browser is protected by the installation password, sessions, CSRF tokens, and login throttling-ready storage. Sensitive settings, event payloads, prompts, and sync errors are encrypted in SQLite with the bundled pure-PHP authenticated encryption helper. The stock SQLite file itself is not page-encrypted.
