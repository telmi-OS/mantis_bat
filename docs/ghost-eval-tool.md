<p><img src="../assets/logo/mantis-mini.svg" width="56" alt="Mantis Bat"></p>

# Ghost Eval Tool

Ghost Eval is a standalone PHP utility in `tools/ghost-eval/php/`. It evaluates a configured telmi OS Ghost through Ghost API v2 realtime chat. It has its own setup, dashboard, SQLite state, cron worker, and runtime files. It does not import or call the RAG Prep tool.

## What It Does

1. Finds writable group Files spaces for the configured Ghost JWT.
2. Pulls a selected JSON evaluation set from the chosen group Files space at run start.
3. Records the exact suite snapshot and SHA-256 in the local run record.
4. Asks the configured Ghost each question through realtime `/chat`, with configurable `use_rag` and `use_history` options.
5. Asks the same Ghost to judge each answer through realtime `/chat` with RAG and history both disabled.
6. Writes a dated Markdown report and uploads it to the selected group Files space.

The module does not call `/rag_search` or any other RAG API. The challenged Ghost uses its own RAG through the `use_rag` chat option when enabled. The `memory_extract` in a test case is reference evidence supplied to the judge; it is not fetched from the Ghost's memory.

The judge is the same Ghost as the challenged assistant. The report calls this out because the judge's own personality and instructions can affect its verdict. Treat the verdict as a review signal, not independent ground truth.

## Requirements

- PHP 8.1+
- cURL
- PDO SQLite
- HTTPS
- a Ghost API v2 JWT
- File Explorer enabled for the owner's subscription
- owner or write access to a group Files space

## Install

1. Upload `tools/ghost-eval/php/` to a PHP host.
2. Expose only `public/` through the web server. Keep `storage/`, `src/`, and `examples/` private.
3. Open `public/install.php` over HTTPS.
4. Enter the app base URL, timezone, Ghost API base, and Ghost JWT.
5. Choose a writable group Files space returned by the Ghost API.
6. Save the private dashboard, cron, status, health, maintenance, and installer unlock URLs.

The live `storage/config.php` is created by the installer and is intentionally not tracked or included in release archives. `storage/config.example.php` is a reference template only; it contains no live credentials.

The installer checks `/settings` and `/files?action=spaces`. It only offers group spaces where the Ghost has owner or write access. The selected group supplies the default chat context, Files space, and notification destination.

The dashboard URL is a generated bearer URL such as `index.php?key=...`. Opening it creates a protected PHP session and redirects to a clean URL. Keep it private. The Ghost JWT stays in the server-side runtime config and is never sent to browser JavaScript.

## Evaluation Set

Upload JSON files into the selected group Files space. The dashboard lists `.json` files at the space root. Each run downloads the file again, validates it, and saves an immutable local snapshot before queueing work.

Example:

```json
{
  "title": "Support Ghost baseline",
  "cases": [
    {
      "id": "memory-recall-01",
      "category": "Memory recall",
      "priority": "P1",
      "question": "When does the Showcase standup happen?",
      "memory_extract": "The Showcase standup happens daily at 15:20.",
      "grading_rubric": "Pass if the answer says daily at 15:20. Fail if it gives another time or claims not to know.",
      "why_included": "Checks recall from the configured group memory.",
      "what_not_covered": "Does not test Board schedule updates.",
      "out_of_scope": false
    }
  ]
}
```

`id`, `question`, and `memory_extract` are required. `grading_rubric` can be omitted when the dashboard's default rubric is suitable. A case can use `priority: "P0"` for high-priority failure notifications and `out_of_scope: true` when a safe refusal should pass.

Each suite is capped at 1 MiB and 40 cases. Questions, reference excerpts, and rubrics have per-field length limits. Files-space group members with read access can see the suites and uploaded reports, so choose that group with the report contents in mind.

## Challenge And Judge Options

Challenge settings are saved in the dashboard:

- `use_rag`: whether the challenged Ghost uses its own RAG for each answer
- `use_history`: whether the challenged Ghost uses chat history for each answer
- chat mode: always realtime

The evaluator sends the selected group ID with each call. The judge always receives `use_rag: false` and `use_history: false`; its prompt includes the case question, reference memory extract, challenged answer, and rubric. The judge is instructed to treat the case data as quoted evidence, not as instructions.

## Rate And Runtime Limits

- One run may be queued or active at a time.
- A run is capped at 40 cases.
- One worker tick makes at most one Ghost or Files API request.
- Ghost API calls use a 5-second connection timeout and a 20-second total timeout.
- HTTP cron requests are limited to one per 50 seconds per source IP; a one-minute schedule is suitable.
- Chat calls are not automatically replayed after transport errors, because a timed-out request may already have reached the Ghost.

The dashboard only queues a run. Configure cron to call `public/cron.php?key=...` once per minute, or run it from CLI:

```text
* * * * * php /path/to/tools/ghost-eval/php/public/cron.php
```

Challenge, judge, report upload, and optional group notification steps resume from SQLite state. No loop sleeps or makes multiple model calls in one worker request.

## Group Notifications

Notifications are optional and can be enabled for run start, run finish, operational errors, and P0 case failures. The evaluator sends a normal realtime chat instruction such as `Tell Showcase Ghost Eval run ...`; notifications use RAG and history disabled.

> Turn on the Ghost's **Agentic** capability in its telmi OS Ghost settings before enabling group notifications. Notifications use normal “Tell GROUPNAME …” chat. Autonomous Mode and Action tools are not required.

## Reports

Reports use a UTC timestamp and unique run ID, for example:

```text
ghost-eval-2026-09-25-142500-a1b2c3d4.md
```

Each report includes the suite hash, chat settings, questions, reference excerpts, challenged answers, judge verdicts and rationales, and request errors. The local copy is stored in the private `storage/reports/` directory. A Files API upload error leaves the local report and run status available in the dashboard.

## Operational Pages

- `public/index.php`: private dashboard, settings, suite selection, run history
- `public/cron.php`: bounded worker; private URL key required outside CLI
- `public/status.php`: masked runtime configuration and run counts
- `public/health.php`: private JSON health check
- `public/maintenance.php`: clear local run history or factory reset

Factory reset removes local config, database, logs, and cached reports. It does not delete evaluation sets or reports already stored in the group Files space.

## API Reference

Ghost Eval uses `POST /chat` and the authenticated `/files` operations described in the [telmi OS Ghost API v2 guide](https://teleport-ai.com/resources.html#api-docs) and [interactive API reference](https://idsfu8yxg1.apidog.io/).
