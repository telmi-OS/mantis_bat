<p><img src="../assets/logo/mantis-mini.svg" width="56" alt="Mantis Bat"></p>

# Ghost API v2

The Telegram PHP connector uses the public `Ghost API v2` contract published by Teleport AI.

## Verified Base Contract

Public server:

```text
https://dev.telmi-ai.com/api/ghost/v2
```

Authentication:

```text
Authorization: Bearer <ghost-jwt>
```

The public contract does not require a `ghost_id` in path or body for the documented endpoints. The authenticated Ghost is implied by the JWT.

## Verified Public Paths

- `POST /chat`
- `GET /inbox`
- `POST /inbox/ack`
- `POST /memory/upsert`
- `GET /memory/list`
- `POST /memory/delete`
- `POST /memory/search`
- `GET /settings`
- `POST /settings`

This supersedes the earlier draft assumption that memory upsert lived at `/memory`.

## Chat Request Shape

Minimum request:

```json
{
  "message": "Hello"
}
```

Other optional fields exist in the public schema, but the connector does not need them for the current Telegram flow.

Current connector request shape:

```json
{
  "message": "Hello from Telegram",
  "mode": "queued",
  "meta": {
    "source": "mantis_bat",
    "channel": "telegram"
  },
  "options": {
    "mode": "queued"
  }
}
```

## Chat Response Shape

Expected success body:

```json
{
  "ok": true,
  "reply": "Hello. How can I help?",
  "data": {}
}
```

The connector should normalize `reply` first and treat missing `reply` as an upstream error.

## Queued Chat Mode

Ghost API v2 chat supports queued execution.

For queued use:

- send `mode: queued`
- or send `options.mode: queued`
- `/chat` returns an acknowledgement
- collect the later assistant response through `/inbox`

The Telegram PHP connector now uses queued mode for normal chat, so the webhook request does not wait for the final assistant reply.

Live runtime note:

- the published contract says queued mode should acknowledge `/chat` and deliver the later assistant response through `/inbox`
- some live Ghost runtimes may still return a usable assistant reply inline while reporting `status: queued`
- the Telegram connector now accepts that inline reply as a fallback when no queued `job_id` is present

## Memory Upsert Shape

Verified request shape:

```json
{
  "items": [
    {
      "text": "The Showcase standup happens daily at 15:20.",
      "metadata": {
        "source": "website-docs"
      }
    }
  ]
}
```

Optional:

- `group_id`

This means `bat_memory_up:` targets `POST /memory/upsert` with an `items` array.

## Inbox Shape

`GET /inbox` returns an object that may expose items in either:

- `data.items`
- top-level `items`

The connector normalizes both container positions and then checks likely reply-bearing fields such as:

- `text`
- `message`
- `content`
- `body`
- `reply`
- nested `data.*`
- nested `payload.*`

## Ack Shape

Verified ack body:

```json
{
  "message_id": "3921"
}
```

Optional:

- `group_id`

## Settings Notes

The public contract makes these distinctions explicit:

- `agentic_enabled` improves operational interpretation
- `agent_tools.enabled` is the API equivalent of Action Mode
- `agent_tools.enabled_tools` is the explicit tool allowlist
- `autonomy_enabled` does not bypass permissions or approvals

This matters because the connector should not claim that a Ghost can take action just because chat works.
