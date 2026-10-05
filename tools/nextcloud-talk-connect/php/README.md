<p><img src="../../../assets/logo/mantis-mini.svg" width="64" alt="Mantis Bat"></p>

# Mantis Bat Nextcloud Talk Connect PHP Tool

Nextcloud Talk Connect creates public Nextcloud Talk meeting rooms and returns their URLs through a small authenticated GET endpoint for telmi OS Ghost presets.

## Requirements

- PHP 8.1+
- cURL
- PDO SQLite
- mbstring
- HTTPS
- a Nextcloud service account with Talk access and an app password

## What It Does

- provides a protected browser dashboard and installer
- validates Nextcloud credentials during setup
- generates a random X-Auth key for Ghost requests
- creates public Talk rooms through the OCS Talk API using Basic Auth
- returns the exact room token and a URL built as &lt;Nextcloud base URL&gt;/call/&lt;token&gt;
- reuses the result for case-insensitive matching names for five minutes
- exposes private status, health, and maintenance pages

The Ghost never receives the Nextcloud username or app password. Those credentials remain in the private server-side config and are sent only to Nextcloud over verified HTTPS.

## Install On Hades

This tool follows the standalone PHP tool layout used by Ghost Eval: its README and public entrypoints are staged into the Hades PHP instance. The public/install.php file is the entrypoint.

1. Stage the tool using the same standalone PHP tool layout as Ghost Eval.
2. Create a Hades PHP instance and open its URL.
3. Enter the canonical HTTPS URL for this app's public directory, the Nextcloud base URL, service account username, app password, and default meeting name.
4. Save the protected app URL, GET endpoint URL, generated Ghost X-Auth key, status, health, maintenance, and installer unlock URLs shown after setup.

There is no cron worker. The SQLite database and lock files are kept under private storage.

The runtime has no dependency on Composer execution, shell commands, external binaries, or OS package installation. Each Nextcloud request has a five-second connection timeout and a ten-second total timeout.

## Install On A Separate PHP Host

Expose only the public directory through the web server, keep src and storage private and writable by PHP, then open public/install.php over HTTPS. The clean /api/meet/create route uses the Apache rewrite when available and also has a directory-index fallback for hosts that ignore per-directory rewrite rules. The explicit /api/meet/create.php path is available as a fallback.

## Ghost Preset

The protected dashboard provides copy-ready values. The preset is:

**URL**

<pre>https://&lt;mantis-host&gt;/api/meet/create.php</pre>

**Method**

<pre>GET</pre>

**Headers JSON**

<pre>{
  "X-Auth": "&lt;generated-key&gt;",
  "X-Meeting-Name": "{{meeting_name}}"
}</pre>

**Description**

<pre>Create a new Nextcloud Talk meeting link. Use this when the user asks to create, start, or generate a meeting or call link. Set meeting_name to a short descriptive name based on the user request; Amygdala sends it in the X-Meeting-Name header. The endpoint returns JSON containing meeting_url. Return the meeting_url to the user.</pre>

The Mantis Bat endpoint reads X-Auth and X-Meeting-Name. Amygdala substitutes the prompt-derived meeting name into the header value at runtime. Keep the generated X-Auth key private. No JSON template or URL parameter is used.

## API

GET /api/meet/create.php returns the following response. Supply the meeting name in the X-Meeting-Name request header; the endpoint does not read a URL parameter or request body. The extensionless /api/meet/create route is also supported when the web server applies the included rewrite or directory-index fallback:

<pre>{
  "success": true,
  "meeting_name": "Architecture Discussion",
  "meeting_url": "https://meet.example.com/call/abcDEF123",
  "token": "abcDEF123"
}</pre>

The name parameter is optional. The service trims it, removes control characters, collapses whitespace, shortens it to 100 Unicode characters, and uses the configured default when the result is empty. Deduplication uses a Unicode case-insensitive key of that normalized name. The first normalized spelling is retained for the displayed room name. Requests for an existing name return the same token and URL for five minutes, measured from the first create attempt. A per-name file lock makes concurrent requests wait for and share the result.

To ensure one upstream creation attempt per name and five-minute window, failed attempts are cached as failures for that window. If PHP stops while an attempt is pending, later requests receive meeting_creation_in_progress until the reservation expires rather than risking a duplicate room.

The endpoint is rate limited to 30 new room creation attempts per minute. Requests served from the five-minute deduplication cache do not count against this limit.

## Nextcloud API

The installer uses the configured service account to check the OCS capabilities endpoint. Room creation uses:

<pre>POST &lt;Nextcloud base URL&gt;/ocs/v2.php/apps/spreed/api/v4/room?format=json</pre>

with OCS-APIRequest: true, JSON accept headers, HTTP Basic Auth, and URL-encoded roomType=3 and roomName form fields. TLS verification is enabled; the connection timeout is 5 seconds and the total timeout is 10 seconds.

The returned OCS response must have ocs.meta.status equal to ok and a non-empty ocs.data.token. Credentials, authorization headers, full upstream response bodies, and generated tokens are not logged.

## Security

- expose only public; keep src and storage private
- keep the X-Auth key and all installer/dashboard/operational URLs private
- use a dedicated Nextcloud account and app password
- do not disable TLS verification
- the API returns generic JSON errors and does not expose upstream response bodies
- the installer unlock secret is stored as a password hash
- configuration and SQLite files are written with private file permissions

## Operational Pages

- public/install.php: setup and private installer unlock flow
- public/index.php?key=...: protected dashboard and ready-to-copy Ghost preset
- public/api/meet/create.php and public/api/meet/create/index.php: GET endpoint and clean-route fallback
- public/status.php?key=...: masked runtime config and active dedupe count
- public/health.php?key=...: private JSON health information
- public/maintenance.php?key=...: clear local dedupe records or factory reset
