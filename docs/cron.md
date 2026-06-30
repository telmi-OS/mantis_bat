<p><img src="../assets/logo/mantis-mini.svg" width="56" alt="Mantis Bat"></p>

# Cron

Mantis Bat uses cron for Ghost inbox delivery.

In the current Telegram connector, that inbox flow is responsible for:

- normal Ghost chat replies
- proactive Ghost messages
- active-group inbox delivery through `/inbox_groups`

## CLI Cron

```text
* * * * * php /path/to/mantis-bat/public/cron.php
```

## URL Cron

```text
https://example.com/mantis-bat/public/cron.php?key=CRON_SECRET
```

## Runtime Rules

- allow CLI execution without key
- require key for HTTP execution
- prevent overlapping runs with a lock file
- skip delivery when no paired owner exists
- poll personal `/inbox` and merged `/inbox_groups`
- keep a local SQLite inbox backend for dedupe and ordering
- seed the backend on first poll so old history is not replayed into Telegram
- keep delivery state entirely in the connector runtime

Without a working cron, Telegram users can send messages to Ghost, but they will not receive the later queued reply.

Exception:

- some live Ghost runtimes may return a usable reply inline even when queued mode is requested
- in that case the connector forwards the inline reply immediately
- inbox polling is still required for proactive messages and for queued runtimes that follow the documented inbox flow

Current cron JSON includes counters such as:

- `fetched`
- `fetched_groups`
- `seeded`
- `ingested`
- `delivered`

That makes it easier to tell whether the connector only initialized its baseline, discovered new inbox rows, or actually flushed messages to Telegram.
