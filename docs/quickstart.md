<p><img src="../assets/logo/mantis-mini.svg" width="56" alt="Mantis Bat"></p>

# Quickstart

This guide is the full beginner path for the first shipping Mantis Bat module:

- channel: Telegram
- hosting model: self-hosted PHP
- runtime: Telmi OS Ghost API v2

If you have never deployed a PHP tool before, follow the steps in order and do not skip the security notes.

## Super Short Version

If you want the whole process in one glance, this is it:

1. Create a Ghost in telmi OS
2. Create a Ghost JWT
3. Create a Telegram bot in BotFather
4. Upload `connectors/telegram/php/` to your PHP host
5. Open `public/install.php`
6. Fill the installer form
7. Save the secret URLs shown by the installer
8. Open the Telegram pairing link
9. Press `Start`
10. Set up cron
11. Save the maintenance and pairing recovery URLs
12. Send `hello`
13. Send `bat_memory_up: something to remember`

Everything below explains those steps slowly and exactly.

## Before You Start

You need five things:

1. A Telmi Ghost
2. A Ghost API v2 JWT for that Ghost
3. A Telegram bot token
4. A web host with PHP 8.1+, cURL, SQLite, and HTTPS
5. A cron option

## What You Will End Up With

When setup is finished, you will have:

- your own Telegram bot
- your own public connector URL
- your own private Ghost JWT
- one paired Telegram owner account
- one private cron URL
- one private status URL
- one private health URL
- one private maintenance URL
- one private pairing recovery URL

## What You Must Keep Private

Before doing anything else, understand this:

- your Telegram bot token is private
- your Ghost JWT is private
- your Telegram webhook secret is private
- your cron URL is private
- your status URL is private
- your health URL is private
- your maintenance URL is private
- your pairing recovery URL is private
- your installer unlock secret is private
- `storage/config.php` is private

If any of those are posted publicly, treat that as a credential leak.

### What Mantis Bat Does

Mantis Bat is not the AI itself.

It sits between:

- your Telegram bot
- your Telmi Ghost JWT
- the Ghost API v2 endpoints

That means:

- Telegram messages go to your Ghost through your connector in queued mode
- Telegram chat requests include history context
- Ghost replies come back through inbox polling
- active group inbox rows can also come back through cron polling
- proactive Ghost inbox messages can also come back to Telegram
- you stay in control of the bot token and hosting path

## Step 1: Create Or Choose Your Ghost

In telmi OS:

1. Create a Ghost or select an existing one
2. Confirm it is the Ghost you want Telegram to talk to
3. If you plan to use group context, note the relevant `group_id`

If you are unsure whether you need a group ID, leave it empty at first. The connector can still work in Ghost-default context.

### What You Should Have After Step 1

- one Ghost selected in telmi OS
- optionally one `group_id` written down somewhere safe

## Step 2: Create A Ghost JWT

In the Ghost credentials area:

1. Create a Ghost JWT
2. Copy it immediately
3. Store it somewhere private temporarily

Important:

- this token is effectively the key to your Ghost API access
- do not paste it into public screenshots
- do not commit it into git
- do not send it through public chat or social posts

### What You Should Have After Step 2

- one Ghost JWT copied and stored safely

Example shape:

```text
eyJhbGciOi...
```

Do not worry if yours is much longer. That is normal.

## Step 3: Create Your Telegram Bot

In Telegram:

1. Search for `@BotFather`
2. Open the chat
3. Send `/newbot`
4. Enter a display name for the bot
5. Enter a username ending in `bot`
6. Copy the bot token

Keep both:

- bot token
- bot username

The token is needed by the installer. The username is used for the pairing link.

### What You Should Have After Step 3

- bot token
- bot username

Example:

```text
Bot username: my_private_ghost_bot
Bot token: 123456789:AAExampleExampleExample
```

Your real values will be different.

## Step 4: Prepare Hosting

Your host needs:

- PHP `8.1+`
- `cURL`
- `PDO_SQLite`
- HTTPS
- a writable `storage/` directory

### Public Folder Layout

Expose only this folder to the web:

- `connectors/telegram/php/public/`

Keep the rest of the connector private on the server, especially:

- `storage/`
- `src/`
- `templates/`

### Beginner-Friendly Hosting Check

If you do not know whether your host supports the connector, ask your hosting provider these exact questions:

1. Do I have PHP 8.1 or newer?
2. Is cURL enabled?
3. Is SQLite or PDO_SQLite enabled?
4. Can I run a cron job or a web cron?
5. Is HTTPS available on my domain?

If the answer to any of those is no, stop there and fix that first.

## Step 5: Upload The Connector

Upload the folder:

```text
connectors/telegram/php/
```

Example public install URL:

```text
https://example.com/mantis-bat/public/install.php
```

Before opening the installer, verify:

- files are present on the server
- `storage/` is writable
- the URL opens over `https://`

### What You Should See

When you open:

```text
https://example.com/mantis-bat/public/install.php
```

you should see:

- a Mantis Bat installer page
- a requirements section
- a configuration form

If you see `404`, fix the upload path first.

If you see a PHP error page, your hosting setup is not ready yet.

## Step 6: Run The Installer

Open:

```text
https://example.com/mantis-bat/public/install.php
```

The installer checks:

- PHP version
- cURL extension
- SQLite extension
- storage writability

### Before You Fill The Form

Open this file from the repository if you want a checklist of all variables and secrets before you start:

```text
connectors/telegram/php/.env.example
```

Important:

- the connector does not load `.env` files at runtime in `v0.1.0`
- this file is only a reference sheet
- the installer writes the real live config into `connectors/telegram/php/storage/config.php`
- never commit or publish `storage/config.php`

### Exact Meaning Of The Reference File

The `.env.example` file is there so you can preview the names of the values:

- `APP_BASE_URL`
- `APP_TIMEZONE`
- `APP_CRON_SECRET`
- `APP_STATUS_SECRET`
- `APP_HEALTH_SECRET`
- `TELEGRAM_BOT_TOKEN`
- `TELEGRAM_WEBHOOK_SECRET`
- `GHOST_API_BASE`
- `GHOST_API_TOKEN`
- `GHOST_GROUP_ID`

In `v0.1.0`, you do not create a real `.env` file for the running connector.

### Fields You Must Fill

#### Base URL

This is the public URL to the connector `public/` directory.

Example:

```text
https://example.com/mantis-bat/public
```

Do not add `install.php` at the end.

Example:

```text
Correct: https://example.com/mantis-bat/public
Wrong:   https://example.com/mantis-bat/public/install.php
```

#### Timezone

Use your deployment timezone.

Example:

```text
Europe/Berlin
```

If you do not know your timezone string, use the timezone of the place where the connector should behave as “local time”.

#### Cron Secret

This protects HTTP cron access.

You can keep the generated value unless you have your own secret scheme.

Save it after install. It becomes part of your private cron URL.

Example:

```text
f4a7d8c9e1b2
```

#### Status Secret

You do not type this manually in the installer.

The installer generates it automatically and uses it to protect:

```text
status.php?key=...
```

Without the correct key, the status page should return `404`.

#### Health Secret

You do not type this manually in the installer.

The installer generates it automatically and uses it to protect:

```text
health.php?key=...
```

Without the correct key, the health endpoint should return `404`.

#### Installer Unlock Secret

Choose a private secret phrase or token.

You need this only if you later want to unlock and overwrite the installed configuration. Store it privately.

Good example:

```text
mantis-bat-reinstall-2026-private
```

Bad example:

```text
1234
```

#### Telegram Bot Token

Paste the token from BotFather.

Example:

```text
123456789:AAExampleExampleExample
```

#### Telegram Webhook Secret

Keep the generated value unless you have a reason to replace it. Telegram sends this back in the webhook header and the connector validates it.

This is one of the most important security values in the connector. Do not share it.

Example:

```text
9f2c4d88a1b4
```

#### Ghost API Base

Default:

```text
https://dev.telmi-ai.com/api/ghost/v2
```

Leave this unless Teleport AI changes the public Ghost API base.

#### Ghost JWT

Paste the Ghost API v2 token you created earlier.

This is also called the Ghost API token in some places. In practice for this connector, it is the bearer token sent to Ghost API v2.

#### Default Group ID

Optional.

Use it only if this connector should operate in a specific allowed group context by default.

Example:

```text
group_78476c1c09b1
```

If you do not have one, leave the field empty.

### Example Installer Form

This is an example only:

```text
Base URL:                https://example.com/mantis-bat/public
Timezone:                Europe/Berlin
Cron Secret:             f4a7d8c9e1b2
Installer Unlock Secret: mantis-bat-reinstall-2026-private
Telegram Bot Token:      123456789:AAExampleExampleExample
Telegram Webhook Secret: 9f2c4d88a1b4
Ghost API Base:          https://dev.telmi-ai.com/api/ghost/v2
Ghost JWT:               eyJhbGciOi...
Default Group ID:        group_78476c1c09b1
```

## Step 7: Finish Install And Save The Private URLs

When install succeeds, the installer:

- validates Telegram through `getMe`
- validates Ghost API through `GET /settings`
- writes private config into `storage/config.php`
- creates the SQLite database
- registers the Telegram webhook
- creates a pairing code
- locks the installer
- generates private operational URLs

Save these immediately:

- pairing deep link
- cron URL with secret
- status URL with secret
- health URL with secret
- maintenance URL with secret
- pairing recovery URL with secret
- your installer unlock secret

These are the private operational secrets and private operational URLs for the connector.

Treat those URLs like credentials. Do not publish them.

### What The New Private URLs Are For

- `pairing.php?key=...` creates a fresh single-use pairing code without reinstalling
- `maintenance.php?key=...` lets you unpair, switch Ghost, delete webhook, or factory-reset the connector

### Save Them Somewhere Safe

Good places:

- a password manager
- a private secure note
- a private team vault

Bad places:

- a public issue
- a public Notion page
- a social media draft
- a screenshot posted in chat

### What The Installer Writes

After install, the live connector configuration is stored in:

```text
connectors/telegram/php/storage/config.php
```

That file contains secrets such as:

- Telegram bot token
- Telegram webhook secret
- Ghost JWT
- cron secret
- status secret
- health secret

That file must stay private.

### What You Should See On Success

After a successful install, you should see:

- a success status message
- a pairing code
- a Telegram pairing link
- a cron URL
- a status URL
- a health URL
- a maintenance URL
- a pairing recovery URL

If you do not see those, the install did not finish correctly.

## Step 8: Pair Your Telegram Account

Open the deep link shown by the installer:

```text
https://t.me/<bot_username>?start=<PAIRING_CODE>
```

Then:

1. Telegram opens your bot
2. Press `Start`
3. The bot sends `/start CODE` to your connector
4. The connector stores your Telegram user ID and chat ID
5. The pairing code becomes invalid after use
6. If pairing fails, use the pairing recovery URL to mint a fresh code

Expected success message:

```text
Mantis Bat connected. Your Telegram bot now talks to your Telmi Ghost.
```

### Important Pairing Rule

Only the paired Telegram user is allowed to use the connector in `v0.1.0`.

If another user discovers the bot and messages it, they should get:

```text
This Mantis Bat connector is private.
```

## Step 9: Configure Cron

This is required for Ghost inbox polling.

It is also required for normal Ghost chat replies in the current Telegram connector, because chat uses queued mode.

Cron also polls:

- the Ghost personal/default inbox through `/inbox`
- the active-group inbox stream through `/inbox_groups`

### Preferred: CLI Cron

Example:

```text
* * * * * php /home/your-user/public_html/mantis-bat/public/cron.php
```

### Fallback: URL Cron

Use the private URL generated by the installer:

```text
https://example.com/mantis-bat/public/cron.php?key=YOUR_SECRET
```

If your hosting panel supports “web cron”, use that exact URL.

Do not post this URL publicly. Anyone with the full URL can trigger your cron endpoint.

### Which Cron Option Should You Choose?

Choose CLI cron if your host allows it.

Choose URL cron only if:

- your host does not offer CLI cron
- or you cannot run `php /path/to/cron.php`

CLI cron is generally better because it does not expose a callable web URL.

## Step 10: Test Normal Chat

In Telegram, send:

```text
hello
```

Expected behavior:

- Telegram sends the webhook to your connector
- your connector validates the webhook secret
- your connector verifies you are the paired owner
- your connector calls `POST /chat` in queued mode
- your connector sends history enabled
- Ghost API acknowledges the request
- the later Ghost reply is delivered back to Telegram through cron inbox polling

If the reply is very long, the connector splits it into smaller Telegram-safe messages when the inbox poller delivers it.

Runtime note:

- the ideal Ghost API v2 queued flow is later inbox delivery
- some live runtimes may still return the answer inline even with `mode: queued`
- the connector now forwards that inline reply as a fallback
- plain Ghost replies are sent to Telegram without a `Ghost Inbox` label
- labeled `System` messages are reserved for system-style notices
- personal inbox labels render as `👤 FROM: Name` when `from_display_name` or fallback sender ID is available
- group inbox labels render as `👥 For Group Name` when `group_display_name` or fallback group identity is available

### What To Do If Nothing Comes Back

Check:

- did pairing succeed?
- is the webhook URL correct?
- is the Telegram webhook secret correct?
- is the Ghost JWT valid?
- is cron running?
- does `health.php?key=...` show `installed: true`?

## Step 11: Test Memory Upload

In Telegram, send:

```text
bat_memory_up: Thomas prefers raw markdown in code blocks.
```

Expected behavior:

- the connector detects the command before normal chat
- it sends a memory upsert payload to `POST /memory/upsert`
- it does not send this text to `/chat`
- Telegram replies with a success or safe error message

Expected success style:

```text
Memory uploaded to your Ghost.
```

## Step 12: Check Private Health And Status URLs

Use the private URLs generated by the installer.

### Health

Returns a small JSON status payload.

### Status

Returns a masked runtime status page.

Both endpoints are secret-protected. If you open them without the correct `?key=...`, they should return `404`.

### Health Tells You

- whether the connector is installed
- whether Telegram is configured
- whether Ghost is configured
- whether pairing exists
- the current connector version

### Status Tells You

- masked runtime settings
- masked token presence
- current app and connector settings

Status is for private diagnostics only.

### Maintenance Gives You

The private `maintenance.php?key=...` page can:

- unpair the current Telegram owner
- generate a new pairing code
- switch Ghost API base, JWT, or default group
- delete the Telegram webhook
- reset only the connector-local inbox backend
- factory-reset the whole connector

Use `Reset Local Inbox Backend` when telmi OS remains the source of truth and you want the connector to restart inbox tracking without reinstalling or re-pairing.

## What Stays Private

Never publish:

- Telegram bot token
- Ghost JWT
- Telegram webhook secret
- cron secret
- status secret
- health secret
- installer unlock secret
- cron URL
- status URL
- health URL
- `storage/config.php`
- SQLite database files

## Exact Files You Will Touch

Most beginners only need to interact with these files and URLs:

- `connectors/telegram/php/public/install.php`
- `connectors/telegram/php/public/webhook.php`
- `connectors/telegram/php/public/cron.php`
- `connectors/telegram/php/public/status.php?key=...`
- `connectors/telegram/php/public/health.php?key=...`
- `connectors/telegram/php/storage/config.php`
- `connectors/telegram/php/.env.example`

## Final Checklist

Before calling the setup finished, make sure all of these are true:

- installer opened without PHP errors
- Telegram token validated
- Ghost JWT validated
- webhook registered
- pairing link worked
- `hello` gets a Ghost reply through inbox polling
- `bat_memory_up: ...` succeeds
- cron is configured
- private URLs were saved
- no secret values were posted publicly

## If You Need To Reinstall

The installer locks after setup.

To overwrite config intentionally:

1. open `install.php?unlock=YOUR_INSTALLER_UNLOCK_SECRET`
2. confirm you are on the correct deployment
3. submit the form again

Do not do this casually on a live deployment without understanding the impact.

## Current Limits

The first release is intentionally narrow:

- single paired Telegram owner for `v0.1.0`
- text-first interaction
- Telegram is the first shipping module, not the only planned connector
- some advanced Ghost features still depend on your Ghost-side token scope, tool settings, and approvals
