<p><img src="../assets/logo/mantis-mini.svg" width="56" alt="Mantis Bat"></p>

# Cron

Mantis Bat uses cron for Ghost inbox delivery.

In the current Telegram connector, that inbox flow is responsible for:

- normal Ghost chat replies
- proactive Ghost messages
- active-group inbox delivery through `/inbox/groups`

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
- avoid duplicate delivery by tracking delivered inbox messages
- poll both personal Ghost inbox and active-group inbox
- acknowledge inbox items only after successful Telegram delivery

Without a working cron, Telegram users can send messages to Ghost, but they will not receive the later queued reply.

Exception:

- some live Ghost runtimes may return a usable reply inline even when queued mode is requested
- in that case the connector forwards the inline reply immediately
- inbox polling is still required for proactive messages and for queued runtimes that follow the documented inbox flow

Current cron JSON includes counters such as:

- `fetched`
- `fetched_groups`
- `delivered`
- `skipped`

That makes it easier to tell whether the inbox is empty or whether inbox items were returned but could not be delivered.
