<p><img src="../../../assets/logo/mantis-mini.svg" width="64" alt="Mantis Bat"></p>

# Mantis Bat Ghost Eval PHP Tool

Ghost Eval is a standalone evaluation app for challenging a configured telmi OS Ghost through Ghost API v2 realtime chat. It is published as a Mantis Bat PHP tool and can run as a Hades PHP instance.

## Requirements

- PHP 8.1+
- cURL
- PDO SQLite
- HTTPS
- a Ghost API v2 JWT
- a writable group Files space for evaluation sets and reports

## What It Does

- loads a JSON evaluation set from the configured group Files space each time a run starts
- sends each question to the configured Ghost through realtime `/chat`
- lets you enable or disable the challenged Ghost's RAG and chat history
- asks the same Ghost to judge the answer with RAG and chat history disabled, using the case's reference memory extract and grading rubric
- saves a dated Markdown report locally and uploads it to the selected group Files space
- optionally sends bounded group notices for run start, completion, operational errors, and failed P0 cases

Ghost Eval does not depend on the RAG Prep tool and does not call the RAG API. Reference memory extracts are provided in the evaluation set; the tool does not fetch them from Ghost memory.

## Install On Hades

This module follows the Mantis Bat discovery layout `tools/ghost-eval/php/README.md` plus `tools/ghost-eval/php/public/`. Mantis Bat stages files outside `public/` in the private Hades instance directory and places `public/` contents in the instance's `www/` directory. The bootstrap resolves its private `src/` and `storage/` paths relative to that layout.

1. Sync the Mantis Bat module catalog and choose **Ghost Eval (PHP)**.
2. Create the Hades PHP instance. The module's `public/install.php` is its entrypoint.
3. Open the instance URL, enter the canonical HTTPS instance URL, timezone, Ghost API base, and Ghost JWT.
4. Choose a writable group Files space and save the generated private dashboard, cron, status, health, maintenance, and installer unlock URLs.
5. Configure the Hades cron scheduler with the generated cron URL (`<instance-url>/cron.php?key=...`) once per minute.

The app stores its SQLite database, configuration, lock, logs, and cached reports under the instance's private `storage/` directory. The Ghost JWT is not sent to browser JavaScript. The JSON suite and final Markdown report are stored in the selected group Files space.

## Install On A Separate PHP Host

Expose only `public/` through the web server, keep `src/` and `storage/` private and writable by PHP, then open `public/install.php` over HTTPS. Schedule the generated `public/cron.php?key=...` URL once per minute, or invoke it from a CLI cron job.

## Evaluation Set

Upload JSON files to the root of the selected group Files space. The dashboard lists `.json` files and fetches the selected file again when a run starts. Use [the example suite](examples/eval_set.example.json) as a starting point.

Each case requires `question` and `memory_extract`. It can specify `grading_rubric`; otherwise the saved default judge rubric is used. Optional fields include `id`, `category`, `priority` (`P0` enables high-priority failure notices), `why_included`, `what_not_covered`, and `out_of_scope`.

## Bounded Processing

- only one run can be queued or active at a time
- at most 40 cases per run
- one cron tick makes at most one Ghost or Files API request
- realtime Ghost calls use a 5-second connection timeout and 20-second total timeout
- chat requests are not automatically replayed after transport errors
- HTTP cron requests are rate-limited to one per 50 seconds per source IP

The target Ghost's RAG and history settings apply only to challenge answers. The judge always uses the same Ghost with RAG and history disabled. Since the challenged Ghost also acts as judge, the verdict is a review signal rather than independent ground truth.

## Group Notifications

Notifications use normal realtime chat instructions such as `Tell GROUPNAME ...`; no Action tool or Autonomous Mode is needed. Turn on the Ghost's **Agentic** capability in telmi OS Ghost settings before enabling messages. Notification chat uses RAG and history disabled. Notification failures do not discard the run or report.

## Security

- expose only the public app surface
- keep the dashboard, cron, status, health, maintenance, and installer unlock URLs private
- keep `storage/` private and writable by PHP
- select a group Files space whose members are allowed to see the test cases and generated answers
- installer-created `storage/config.php` is private, git-ignored, and omitted from release archives
