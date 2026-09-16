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

## Google Cloud OAuth setup

The connector uses Google's server-side OAuth 2.0 flow. It requests these read-only scopes:

- `https://www.googleapis.com/auth/calendar.readonly` to read calendar events
- `https://www.googleapis.com/auth/calendar.calendarlist.readonly` to list calendars for the one-calendar selection

It never writes to Google Calendar. Keep the client secret private and do not commit it to the repository.

### 1. Create a Google Cloud project and enable Calendar API

1. Open the [Google Cloud Console](https://console.cloud.google.com/).
2. Create a project or select the project that will own this connector's OAuth client.
3. Open **APIs & Services → Library**, find **Google Calendar API**, and click **Enable**. Google also documents this in its [Calendar API quickstart](https://developers.google.com/workspace/calendar/api/quickstart/js#enable_the_api).

### 2. Configure the OAuth consent screen

Open **Google Auth platform** in the Cloud Console and complete **Branding**. Enter an app name, a support email, and a developer contact email. Google's [OAuth consent configuration guide](https://developers.google.com/workspace/guides/configure-oauth-consent) describes the current screens.

Choose the audience that matches the Google accounts that will use this installation:

- **Internal** is only available for Google Workspace or Cloud Identity accounts in the same organization as the project.
- **External** is required for personal Google accounts or accounts outside that organization.

For an **External** app that is still in **Testing**, open **Audience → Test users → Add users** and add every Google account that will connect a calendar. Google shows an unverified-app warning in this mode, and test-user authorizations (including offline refresh tokens) expire after seven days. For unattended cron operation beyond testing, publish the app to **In production** and follow Google's verification requirements where applicable. See [Google's audience and publishing guidance](https://support.google.com/cloud/answer/15549945).

Under **Data Access**, add the two exact scopes listed above. Requesting the narrower read-only scopes keeps the connector's access limited; see Google's [Calendar API scope reference](https://developers.google.com/workspace/calendar/api/auth).

### 3. Create the web application client

1. In **Google Auth platform → Clients**, click **Create client**.
2. Select **Web application**.
3. Give the client a recognizable name, such as `Mantis Bat Google Calendar`.
4. Under **Authorized redirect URIs**, add the callback URL that the installer displays or that you derive from the configured base URL:

   ```text
   https://calendar.example.com/oauth_callback.php
   ```

   For a subdirectory installation, include the complete path:

   ```text
   https://example.com/connectors/google-calendar/public/oauth_callback.php
   ```

   The value must match exactly, including `https`, hostname, port, path, and trailing slash. Do not register `install.php`, `oauth_start.php`, or the application root. Authorized JavaScript origins are not needed for this server-side flow.

5. Click **Create** and copy the generated **Client ID** and **Client secret**.

### 4. Connect the calendar in Mantis Bat

1. Open the connector's `public/install.php` page.
2. Set **Application base URL** to the public URL of the connector, without a trailing slash. The callback is then `<application-base-url>/oauth_callback.php`.
3. Paste the Google **Client ID** and **Client secret** into the installer, finish the other settings, and install.
4. Log in to the dashboard and click **Connect Google**.
5. Sign in with the Google account you added as a test user (when applicable), review the requested read-only permissions, and approve them.
6. Select exactly one calendar from the list and save it.

### Troubleshooting OAuth setup

- **`redirect_uri_mismatch`**: compare the URL in the error with the client's Authorized redirect URI. They must be identical.
- **`access_denied`** or an app-not-available message: check the OAuth audience and, for an External Testing app, confirm that the Google account is listed under Test users.
- **Calendar API not enabled**: enable Google Calendar API in the same Cloud project that owns the OAuth client.
- **`invalid_grant`** or a later cron authentication error: the user may have revoked access or a testing-mode refresh token may have expired. Reconnect Google; for long-running use, publish the OAuth app.
- **No calendars are listed**: confirm that the authorized Google account can open the intended calendar and that the Calendar API scope was granted.

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
