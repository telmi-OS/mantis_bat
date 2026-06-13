<p><img src="../assets/logo/mantis-mini.svg" width="56" alt="Mantis Bat"></p>

# Commands

The Telegram PHP connector supports normal chat plus connector commands.

## Normal Chat

Any plain message is forwarded to the Ghost chat endpoint in queued mode.

Example:

```text
What should I focus on today?
```

Behavior:

- the connector sends the message to `POST /chat`
- it sets queued mode
- it does not wait for the final assistant reply in the webhook request
- the later Ghost answer is delivered through inbox polling

## Memory Upload

Mandatory in `v0.1.0`:

```text
bat_memory_up: text to remember
```

Behavior:

- intercept before normal chat
- validate non-empty payload
- send memory payload to `POST /memory/upsert`
- reply with success or safe failure text

Suggested response:

```text
Memory uploaded to your Ghost.
```

## Status

```text
bat_status
```

`bat_status` reports connector state without secrets.

Current response includes:

- Telegram connected
- Ghost API configured
- chat mode queued
- paired owner label
- cron inbox availability
- memory command availability
- version

## Help

```text
bat_help
```

`bat_help` shows:

- normal chat
- memory upload
- memory search command name placeholder
- status command
- help command

## Planned But Not Enabled

These command names exist, but currently reply with a not-enabled message:

- `bat_memory_search:`
- `bat_memory_list`
- `bat_memory_delete:`
