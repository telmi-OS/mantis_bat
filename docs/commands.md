<p><img src="../assets/logo/mantis-mini.svg" width="56" alt="Mantis Bat"></p>

# Commands

The Telegram PHP connector supports normal chat plus three connector commands.

## Normal Chat

Any plain message is forwarded to the Ghost chat endpoint.

Example:

```text
What should I focus on today?
```

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

Current command:

```text
bat_status
```

`bat_status` should report connector state without secrets.

## Help

Current command:

```text
bat_help
```

`bat_help` shows:

- normal chat
- memory upload
- status command
- help command
