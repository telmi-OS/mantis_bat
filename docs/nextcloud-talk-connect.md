<p><img src="../assets/logo/mantis-mini.svg" width="56" alt="Mantis Bat"></p>

# Nextcloud Talk Connect

Nextcloud Talk Connect is a standalone PHP tool that lets a telmi OS Ghost create public Nextcloud Talk rooms through a small GET API. The Ghost sends a Mantis Bat X-Auth key and a meeting name; the adapter handles Nextcloud Basic Auth and Talk API details.

## Setup

The browser installer collects the app's canonical HTTPS base URL, Nextcloud base URL, service-account username, app password, and default room name. It validates the configured Nextcloud credentials and generates the Ghost X-Auth key. Setup displays the protected dashboard URL, API endpoint, key, status, health, maintenance, and installer unlock URLs. Save these values securely.

See [the PHP tool README](../tools/nextcloud-talk-connect/php/README.md) for install steps and requirements.

## Ghost Preset

Open the private dashboard URL to copy the endpoint URL, GET method, X-Auth header JSON, JSON template, and description into a Ghost preset.

The endpoint is:

    GET /api/meet/create?name=<meeting name>

It returns meeting_url and the Talk room token. The Ghost should return the meeting URL to the user.

## Deduplication

Names are trimmed, whitespace is collapsed, control characters are removed, and long names are shortened. Matching is Unicode case-insensitive. Concurrent requests for the same normalized name share one upstream create attempt. Successful results and failed attempts are held for five minutes from the first attempt. A pending reservation after an interrupted PHP request returns a retryable meeting_creation_in_progress error until expiry, preventing duplicate room creation.

## Runtime and Security

- PHP 8.1+, cURL, PDO SQLite, and mbstring
- HTTPS with certificate verification enabled
- no cron worker
- private SQLite and config files under storage/
- Nextcloud app password never reaches Ghost or the browser dashboard after install
- the public endpoint is rate limited to 30 new room creation attempts per minute
