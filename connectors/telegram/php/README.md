<p><img src="../../../assets/logo/mantis-mini.svg" width="64" alt="Mantis Bat"></p>

# Mantis Bat Telegram PHP Connector

The Telegram PHP connector is the first public connector module in the official Teleport AI `Mantis Bat` framework for `telmi OS`.

It connects a user-owned Telegram bot to a user-owned Ghost through Ghost API v2.

## Requirements

- PHP 8.1+
- cURL
- SQLite
- HTTPS
- Telegram bot token
- Ghost JWT

## What It Does

- receives Telegram webhooks
- forwards normal chat to Ghost API `POST /chat` in queued mode
- enables chat history in the request payload
- supports private owner pairing through `/start CODE`
- provides pairing recovery through `pairing.php`
- provides protected maintenance actions through `maintenance.php`
- uploads memory through `bat_memory_up:`
- reports connector state through `bat_status`
- reports command usage through `bat_help`
- polls both `/inbox` and `/inbox_groups` through cron and forwards Ghost replies, group inbox rows, and proactive messages to Telegram

## Install

1. Upload the module
2. Open `public/install.php`
3. Paste Telegram and Ghost credentials
4. Register webhook
5. Pair the owner account
6. Configure cron

Normal chat replies are not returned directly from the webhook request. They come back later through inbox polling, so cron is required for normal reply delivery.

If a live Ghost runtime returns a usable inline reply while still reporting queued mode, the connector forwards that reply immediately as a fallback.

Normal Ghost replies are sent to Telegram without a `Ghost Inbox` header. Labeled `System` messages are reserved for acknowledgements or system-style notices.

The installer also generates:

- a cron secret
- a status secret
- a health secret
- a maintenance URL
- a pairing recovery URL
- an installer lock

Treat those URLs and secrets as private operational credentials.

## Config Reality In v0.1.0

- the running connector reads `storage/config.php`
- the installer creates that file
- `.env.example` is a reference sheet only
- the connector does not load `.env` directly at runtime in `v0.1.0`

## Connector Path

The current repository path for this connector is:

```text
connectors/telegram/php/
```

## Commands

```text
bat_memory_up: text to remember
bat_status
bat_help
```

## Security

- keep bot and Ghost tokens private
- expose only `public/`
- protect `storage/`
- use webhook secret validation
- use cron secret for HTTP cron mode
- keep `status.php?key=...` and `health.php?key=...` private
- keep `pairing.php?key=...` and `maintenance.php?key=...` private
- keep the installer unlock secret private
