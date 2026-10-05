<p><img src="../assets/logo/mantis-mini.svg" width="56" alt="Mantis Bat"></p>

# Nextcloud Talk Connect

Nextcloud Talk Connect is a standalone PHP tool that lets a telmi OS Ghost create public Nextcloud Talk rooms through a small GET API. The Ghost sends a Mantis Bat X-Auth key and a meeting name; the adapter handles Nextcloud Basic Auth and Talk API details.

## Setup

The browser installer collects the app's canonical HTTPS base URL, Nextcloud base URL, service-account username, app password, and default room name. It validates the configured Nextcloud credentials and generates the Ghost X-Auth key. Setup displays the protected dashboard URL, API endpoint, key, status, health, maintenance, and installer unlock URLs. Save these values securely.

See [the PHP tool README](../tools/nextcloud-talk-connect/php/README.md) for install steps and requirements.

## Ghost Preset

Open the private dashboard URL to copy the fixed endpoint URL, GET method, X-Auth and X-Meeting-Name header JSON, and description into a Ghost preset.

The protected dashboard also shows successful meeting creations from the last seven days, newest first, with clickable direct-call links and UTC timestamps. Deduplicated requests reuse the original result and do not add another entry. The maintenance page's local-record clearing action removes this history too.

The endpoint is:

    GET /api/meet/create.php

The explicit PHP path is the preset URL for Hades, which serves PHP files directly. The extensionless /api/meet/create route is also supported when the web server applies the included rewrite or directory-index fallback.

It returns meeting_url and the Talk room token. The Ghost should return the meeting URL to the user.

Amygdala fills the prompt-derived meeting name into the X-Meeting-Name header at runtime. The preset does not use a JSON template or a URL parameter.

## Deduplication

Names are trimmed, whitespace is collapsed, control characters are removed, and long names are shortened. Matching is Unicode case-insensitive. Concurrent requests for the same normalized name share one upstream create attempt. Successful results and failed attempts are held for five minutes from the first attempt. A pending reservation after an interrupted PHP request returns a retryable meeting_creation_in_progress error until expiry, preventing duplicate room creation.

## Runtime and Security

- PHP 8.1+, cURL, PDO SQLite, and mbstring
- HTTPS with certificate verification enabled
- no cron worker
- private SQLite and config files under storage/
- Nextcloud app password never reaches Ghost or the browser dashboard after install
- the public endpoint is rate limited to 30 new room creation attempts per minute
