<p><img src="../assets/logo/mantis-mini.svg" width="56" alt="Mantis Bat"></p>

# Cron

Mantis Bat uses cron for Ghost inbox delivery.

In the current Telegram connector, that inbox flow is responsible for:

- normal Ghost chat replies
- proactive Ghost messages

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
- acknowledge inbox items only after successful Telegram delivery

Without a working cron, Telegram users can send messages to Ghost, but they will not receive the later queued reply.
