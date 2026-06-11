<p><img src="../assets/logo/mantis-mini.svg" width="56" alt="Mantis Bat"></p>

# Troubleshooting

## Webhook Does Not Receive Messages

- confirm the bot token is valid
- confirm HTTPS is working
- confirm the webhook URL points to `public/webhook.php`
- confirm the webhook secret matches the configured value

## Bot Replies With Private Connector Message

- pair the owner account first through `/start CODE`
- confirm the paired Telegram user ID matches the sender

## Memory Upload Fails

- confirm Ghost JWT is valid
- confirm `POST /memory/upsert` is reachable
- confirm payload text is not empty

## Cron Does Nothing

- confirm `owner_chat_id` or paired owner exists
- confirm cron key for URL mode
- confirm inbox endpoint returns items

## Status Or Health Returns Not Found

- confirm you are using the full secret URL from the installer
- confirm the secret was copied completely
- if you lost it, unlock and reinstall or inspect your private config on the server
