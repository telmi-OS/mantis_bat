<p><img src="../assets/logo/mantis-mini.svg" width="56" alt="Mantis Bat"></p>

# Mantis Bat Overview

Mantis Bat is the official Teleport AI edge framework for `telmi OS`.

Its job is clear: connect external channels and utility workflows to the `telmi OS` runtime without stripping away the identity, memory, governance, and execution model that makes telmi OS useful in the first place.

## Product Boundary

Mantis Bat handles the edge runtime:

- inbound channel events
- outbound channel delivery
- webhook and cron runtime concerns
- pairing and connector-level access control
- channel-specific command routing
- utility-tool upload and processing flows

`telmi OS` handles the actual operating layer:

- Ghost chat
- governed memory
- group-aware context
- action permissions
- autonomy registration
- approval boundaries
- Ghost execution logic

## Current Public Release

The current shipped modules are:

- one Telegram bot connector for PHP in `connectors/telegram/php/`
- one RAG prep utility tool for PHP in `tools/rag-prep/php/`

The repository is shaped as a connector framework, and this is what is public today:

- one Telegram PHP connector module in `connectors/telegram/php/`
- one RAG prep PHP tool in `tools/rag-prep/php/`
- one private paired Telegram owner
- Ghost chat submission through `POST /chat` in queued mode
- Ghost chat history enabled in the request payload
- Ghost inbox polling through cron for replies, proactive delivery, and active-group inbox fan-out
- local SQLite inbox buffering for dedupe, baseline seeding, and cross-feed ordering
- memory upload through `bat_memory_up:`
- pairing recovery through `pairing.php`
- protected maintenance actions through `maintenance.php`
- TXT, PDF, and DOCX intake for Ghost-driven telmi OS artifact generation

Core flows:

1. Telegram message enters webhook
2. Authorized user message is routed to Ghost API v2 in queued mode
3. `/chat` returns an acknowledgement instead of the final assistant reply
4. Ghost inbox polling sends the later assistant reply back to Telegram
5. The same cron loop also polls `/inbox_groups` for current active group inbox rows
6. Both feeds are merged into the connector-local inbox backend and sorted before delivery
7. System notices can be surfaced to Telegram as labeled system messages

Current command support:

```text
bat_memory_up: <text>
bat_status
bat_help
```

`bat_memory_up:` sends structured memory data to Ghost API v2 instead of normal chat.

The RAG prep tool uses a different flow:

1. user uploads one or more source documents
2. cron extracts and normalizes the text locally
3. the tool sends one full normalized source document to Ghost API v2
4. Ghost returns one plain-text telmi OS-ready artifact
5. the user downloads the final `.txt` file

## Why Teleport AI Publishes This

Teleport AI publishes `Mantis Bat` because `telmi OS` is designed as an operating environment, not a prompt toy. Connectors are part of that story. They let developers and community contributors bring real channels into the telmi OS runtime while keeping deployment options flexible.

That gives the connector user access to a Ghost that can already sit on:

- persistent identity
- governed memory
- scoped group context
- Action Mode and approved tools
- human approval rules
- Ghost Cockpit visibility on the telmi side

## What Ships Out Of The Box

When Telegram is connected to a properly configured Ghost, the connector can use capabilities that already exist in telmi OS:

- queued conversational submission through `/chat`
- memory upload through `/memory/upsert`
- Ghost reply delivery through `/inbox`
- group reply delivery through `/inbox_groups`
- proactive updates through `/inbox`
- connector-local dedupe and ordering across both inbox feeds
- group-aware prompts when `group_id` is allowed by token
- action-capable Ghost behavior only if the Ghost itself is configured for that on the telmi OS side

Mantis Bat does not recreate those systems. It exposes them through a connector layer that belongs to the larger `telmi OS` product family.
