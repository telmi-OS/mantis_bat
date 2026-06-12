<p><img src="../assets/logo/mantis-mini.svg" width="56" alt="Mantis Bat"></p>

# Cron

Mantis Bat uses cron for proactive Ghost inbox delivery.

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
- acknowledge inbox items only after successful Telegram delivery
