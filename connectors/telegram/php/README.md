<p><img src="../../../assets/logo/mantis-mini.svg" width="64" alt="Mantis Bat"></p>

# Mantis Bat Telegram PHP Connector

The Telegram PHP connector is the first public connector module in the official Teleport AI `Mantis Bat` framework for `telmi OS`.

It connects a user-owned Telegram bot to a user-owned Ghost through Ghost API v2 and is designed for cheap shared hosting.

## Requirements

- PHP 8.1+
- cURL
- SQLite
- HTTPS
- Telegram bot token
- Ghost JWT

## What It Does

- receives Telegram webhooks
- forwards normal chat to Ghost API `POST /chat`
- supports private owner pairing through `/start CODE`
- uploads memory through `bat_memory_up:`
- polls Ghost inbox through cron and forwards messages to Telegram

## Install

1. Upload the module
2. Open `public/install.php`
3. Paste Telegram and Ghost credentials
4. Register webhook
5. Pair the owner account
6. Configure cron

The installer also generates:

- a cron secret
- a status secret
- a health secret
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
- keep the installer unlock secret private
