<?php

declare(strict_types=1);

require __DIR__ . '/_runtime.php';

$config = $services['config'];
$security = $services['security'];
$storage = $services['storage'];
$runner = $services['runner'];
$installer = $services['installer'];

if (!$config->isInstalled()) {
    header('Location: install.php', true, 302);
    exit;
}
ghostEvalRequireAccess($services);
if (isset($_GET['key'])) {
    header('Location: index.php', true, 302);
    exit;
}

$notice = isset($_SESSION['ghost_eval_notice']) && is_string($_SESSION['ghost_eval_notice']) ? $_SESSION['ghost_eval_notice'] : '';
unset($_SESSION['ghost_eval_notice']);
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        ghostEvalCheckCsrf($services);
        $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
        if ($action === 'save_settings') {
            $all = $config->all();
            $all['evaluation']['use_rag'] = (string) ($_POST['use_rag'] ?? '') === '1';
            $all['evaluation']['use_history'] = (string) ($_POST['use_history'] ?? '') === '1';
            $all['evaluation']['judge_rubric'] = trim((string) ($_POST['judge_rubric'] ?? ''));
            if ($all['evaluation']['judge_rubric'] === '') throw new RuntimeException('Judge rubric cannot be empty.');
            $all['evaluation']['max_cases'] = min(40, max(1, (int) ($_POST['max_cases'] ?? 40)));
            if ((string) ($_POST['settings_section'] ?? '') === 'notifications') {
                $all['notifications'] = [
                    'enabled' => isset($_POST['notify_start']) || isset($_POST['notify_finish']) || isset($_POST['notify_errors']) || isset($_POST['notify_p0']),
                    'on_start' => isset($_POST['notify_start']),
                    'on_finish' => isset($_POST['notify_finish']),
                    'on_errors' => isset($_POST['notify_errors']),
                    'on_p0_failures' => isset($_POST['notify_p0']),
                ];
            }
            $config->write($all);
            $_SESSION['ghost_eval_notice'] = 'Settings saved. These options apply to the next run.';
            header('Location: index.php', true, 303);
            exit;
        }
        if ($action === 'save_schedule') {
            $all = $config->all();
            $schedule = is_array($all['schedule'] ?? null) ? $all['schedule'] : [];
            $enabled = isset($_POST['auto_enabled']);
            if ($enabled) {
                $fileId = isset($_POST['schedule_suite_file_id']) && is_string($_POST['schedule_suite_file_id'])
                    ? trim($_POST['schedule_suite_file_id'])
                    : '';
                $interval = isset($_POST['schedule_interval']) && is_string($_POST['schedule_interval'])
                    ? $_POST['schedule_interval']
                    : 'daily';
                $intervals = ['daily' => 86400, 'weekly' => 604800];
                if (!isset($intervals[$interval])) throw new RuntimeException('Choose a daily or weekly schedule.');
                if ($fileId === '') throw new RuntimeException('Choose a JSON evaluation set for the automatic schedule.');
                $availableFiles = $services['ghost']->listFiles((string) $config->get('files.space_id', ''), (string) $config->get('files.folder_id', ''));
                $selected = null;
                foreach ($availableFiles as $file) {
                    $id = (string) ($file['file_id'] ?? $file['id'] ?? '');
                    $name = (string) ($file['name'] ?? $file['filename'] ?? '');
                    if ($id === $fileId && strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'json') $selected = $file;
                }
                if (!is_array($selected)) throw new RuntimeException('Choose an available .json file from the configured group Files space.');
                $schedule = array_merge($schedule, [
                    'enabled' => true,
                    'suite_file_id' => $fileId,
                    'suite_file_name' => (string) ($selected['name'] ?? $selected['filename'] ?? 'evaluation-set.json'),
                    'interval' => $interval,
                    'interval_seconds' => $intervals[$interval],
                    'next_run_at' => time() + $intervals[$interval],
                    'last_attempt_at' => (int) ($schedule['last_attempt_at'] ?? 0),
                    'last_status' => 'Enabled; first run is scheduled after the selected interval.',
                    'last_error' => '',
                ]);
            } else {
                $schedule['enabled'] = false;
                $schedule['next_run_at'] = 0;
                $schedule['last_status'] = 'Disabled';
                $schedule['last_error'] = '';
            }
            $all['schedule'] = $schedule;
            $config->write($all);
            $_SESSION['ghost_eval_notice'] = $enabled
                ? 'Automatic evaluation enabled. The first run is due after the selected interval; Hades cron checks it every minute.'
                : 'Automatic evaluation disabled.';
            header('Location: index.php', true, 303);
            exit;
        }
        if ($action === 'start_run') {
            $fileId = isset($_POST['suite_file_id']) && is_string($_POST['suite_file_id']) ? trim($_POST['suite_file_id']) : '';
            if ($fileId === '') throw new RuntimeException('Choose an evaluation set from the selected group Files space.');
            if ($storage->activeRunExists()) {
                throw new RuntimeException('A run is already queued or running. Check Recent runs; the cron worker will continue it.');
            }
            $sessionSubject = hash('sha256', session_id());
            if ($storage->hitRateLimit('start_run_session', $sessionSubject, 1, 60)) {
                throw new RuntimeException('A queue attempt was made in this session within the last minute. Check Recent runs before retrying.');
            }
            $files = $services['ghost']->listFiles((string) $config->get('files.space_id', ''), (string) $config->get('files.folder_id', ''));
            $selected = null;
            foreach ($files as $file) {
                $id = (string) ($file['file_id'] ?? $file['id'] ?? '');
                if ($id === $fileId) $selected = $file;
            }
            if (!is_array($selected)) throw new RuntimeException('That suite file is not in the selected group Files folder. Refresh the page and choose again.');
            $fileName = (string) ($selected['name'] ?? $selected['filename'] ?? 'evaluation-set.json');
            $bytes = $services['ghost']->fileContent($fileId);
            $runId = $runner->makeSuiteFromFile($fileId, $fileName, $bytes);
            $_SESSION['ghost_eval_notice'] = 'Run ' . $runId . ' was queued. Cron processes one Ghost API call per tick.';
            header('Location: index.php', true, 303);
            exit;
        }
        throw new RuntimeException('Unknown dashboard action.');
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$files = [];
$filesError = '';
try {
    $files = $services['ghost']->listFiles((string) $config->get('files.space_id', ''), (string) $config->get('files.folder_id', ''));
    $files = array_values(array_filter($files, static fn(array $file): bool => strtolower(pathinfo((string) ($file['name'] ?? $file['filename'] ?? ''), PATHINFO_EXTENSION)) === 'json'));
} catch (Throwable $exception) {
    $filesError = $exception->getMessage();
}
$runs = $storage->listRuns(20);
$csrf = ghostEvalCsrf($services);
$notifications = $config->get('notifications', []);
$schedule = $config->get('schedule', []);
$schedule = is_array($schedule) ? $schedule : [];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ghost Eval | Mantis Bat</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Share+Tech+Mono&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/theme.css">
</head>
<body>
<main class="page-shell">
    <section class="hero">
        <div class="brand-row"><img src="assets/mantis-mini.svg" alt="Mantis Bat"><div><p class="eyebrow">Standalone Mantis Bat Tool</p><h1><span class="gradient-text">Ghost Eval</span></h1></div></div>
        <div class="hero-grid"><div class="copy"><p>Challenge the configured Ghost with evaluation cases from its group Files space. The target answers in realtime; the same Ghost judges each answer with RAG and history disabled.</p><p><strong>Group Files:</strong> <?= ghostEvalH((string) $config->get('files.group_label', 'Group')) ?> · <code><?= ghostEvalH((string) $config->get('ghost.group_id', '')) ?></code></p></div><div class="hero-shot"><img src="assets/telmi-os-desktop.png" alt="telmi OS desktop"></div></div>
    </section>

    <?php if ($notice !== ''): ?><section class="card notice-card"><strong><?= ghostEvalH($notice) ?></strong></section><?php endif; ?>
    <?php if ($error !== ''): ?><section class="card alert-card"><strong>Could not complete that action</strong><p><?= ghostEvalH($error) ?></p></section><?php endif; ?>

    <div class="dashboard-grid">
        <section class="card">
            <h2><span class="gradient-text">Start a run</span></h2>
            <p class="copy">The tool fetches the selected JSON file from Files when you queue a run. It records a SHA-256 snapshot so edits to the Files copy do not change an active run.</p>
            <?php if ($filesError !== ''): ?><p class="status-bad"><?= ghostEvalH($filesError) ?></p><?php elseif ($files === []): ?><p class="field-help">No JSON suite files found in the selected Files folder.</p><?php else: ?>
                <form method="post"><input type="hidden" name="csrf_token" value="<?= ghostEvalH($csrf) ?>"><input type="hidden" name="action" value="start_run">
                    <label>Evaluation set from group Files<select name="suite_file_id" required><option value="">Choose a JSON suite</option><?php foreach ($files as $file): ?><option value="<?= ghostEvalH((string) ($file['file_id'] ?? $file['id'] ?? '')) ?>"><?= ghostEvalH((string) ($file['name'] ?? $file['filename'] ?? 'Unnamed JSON')) ?></option><?php endforeach; ?></select></label>
                    <button type="submit">Queue evaluation</button>
                </form>
            <?php endif; ?>
            <p class="field-help"><strong>Queue evaluation</strong> creates one run now. Configure the Hades cron worker to process queued runs. It advances one Ghost or Files API request per tick.</p>
        </section>

        <section class="card">
            <h2><span class="gradient-text">Challenge options</span></h2>
            <form method="post"><input type="hidden" name="csrf_token" value="<?= ghostEvalH($csrf) ?>"><input type="hidden" name="action" value="save_settings"><input type="hidden" name="settings_section" value="challenge">
                <label class="check-row"><input type="checkbox" name="use_rag" value="1" <?= $config->get('evaluation.use_rag', true) ? 'checked' : '' ?>> Use this Ghost’s RAG for challenge answers</label>
                <label class="check-row"><input type="checkbox" name="use_history" value="1" <?= $config->get('evaluation.use_history', false) ? 'checked' : '' ?>> Use this Ghost’s chat history for challenge answers</label>
                <label>Maximum cases per run<input type="number" name="max_cases" min="1" max="40" value="<?= (int) $config->get('evaluation.max_cases', 40) ?>"><span class="field-help">A hard cap keeps runs bounded on Hades.</span></label>
                <label>Default judge rubric<textarea name="judge_rubric" rows="4" required><?= ghostEvalH((string) $config->get('evaluation.judge_rubric', '')) ?></textarea><span class="field-help">A case may provide its own <code>grading_rubric</code>.</span></label>
                <button type="submit" class="button-secondary">Save options</button>
            </form>
        </section>

        <section class="card">
            <h2><span class="gradient-text">Group notifications</span></h2>
            <p class="copy">Notifications use a normal realtime instruction to tell the configured group. They do not use RAG or history.</p>
            <form method="post"><input type="hidden" name="csrf_token" value="<?= ghostEvalH($csrf) ?>"><input type="hidden" name="action" value="save_settings"><input type="hidden" name="settings_section" value="notifications">
                <input type="hidden" name="use_rag" value="<?= $config->get('evaluation.use_rag', true) ? '1' : '0' ?>">
                <input type="hidden" name="use_history" value="<?= $config->get('evaluation.use_history', false) ? '1' : '0' ?>">
                <input type="hidden" name="max_cases" value="<?= (int) $config->get('evaluation.max_cases', 40) ?>">
                <input type="hidden" name="judge_rubric" value="<?= ghostEvalH((string) $config->get('evaluation.judge_rubric', '')) ?>">
                <label class="check-row"><input type="checkbox" name="notify_start" value="1" <?= ($notifications['on_start'] ?? false) ? 'checked' : '' ?>> Tell the group when a run starts</label>
                <label class="check-row"><input type="checkbox" name="notify_finish" value="1" <?= ($notifications['on_finish'] ?? true) ? 'checked' : '' ?>> Tell the group when a run finishes</label>
                <label class="check-row"><input type="checkbox" name="notify_errors" value="1" <?= ($notifications['on_errors'] ?? true) ? 'checked' : '' ?>> Tell the group when a run has errors</label>
                <label class="check-row"><input type="checkbox" name="notify_p0" value="1" <?= ($notifications['on_p0_failures'] ?? true) ? 'checked' : '' ?>> Tell the group when a P0 case fails</label>
                <p class="agentic-note"><strong>Before enabling messages:</strong> turn on the Ghost’s <strong>Agentic</strong> capability in its telmi OS Ghost settings. Notifications use normal “Tell <?= ghostEvalH((string) $config->get('ghost.group_name', 'GROUPNAME')) ?> …” chat. Autonomous Mode and Action tools are not required.</p>
                <button type="submit" class="button-secondary">Save notification settings</button>
            </form>
        </section>

        <section class="card">
            <h2><span class="gradient-text">Automatic evaluation</span></h2>
            <p class="copy">Hades cron checks this schedule once per minute. When a run is due and no other run is active, the latest copy of the selected suite is fetched from Files and queued.</p>
            <form method="post"><input type="hidden" name="csrf_token" value="<?= ghostEvalH($csrf) ?>"><input type="hidden" name="action" value="save_schedule">
                <label class="check-row"><input type="checkbox" name="auto_enabled" value="1" <?= ($schedule['enabled'] ?? false) ? 'checked' : '' ?>> Enable recurring evaluation</label>
                <?php if ($filesError !== ''): ?><p class="status-bad"><?= ghostEvalH($filesError) ?></p><?php endif; ?>
                <label>Suite to rerun from group Files<select name="schedule_suite_file_id" <?= $files === [] ? 'disabled' : '' ?>><option value="">Choose a JSON suite</option><?php if (($schedule['suite_file_id'] ?? '') !== '' && !array_filter($files, static fn(array $file): bool => (string) ($file['file_id'] ?? $file['id'] ?? '') === (string) $schedule['suite_file_id'])): ?><option value="<?= ghostEvalH((string) $schedule['suite_file_id']) ?>" selected><?= ghostEvalH((string) ($schedule['suite_file_name'] ?? 'Previously selected suite')) ?> (not currently listed)</option><?php endif; ?><?php foreach ($files as $file): $scheduleFileId = (string) ($file['file_id'] ?? $file['id'] ?? ''); ?><option value="<?= ghostEvalH($scheduleFileId) ?>" <?= $scheduleFileId === (string) ($schedule['suite_file_id'] ?? '') ? 'selected' : '' ?>><?= ghostEvalH((string) ($file['name'] ?? $file['filename'] ?? 'Unnamed JSON')) ?></option><?php endforeach; ?></select></label>
                <label>Repeat<select name="schedule_interval"><option value="daily" <?= ($schedule['interval'] ?? 'daily') === 'daily' ? 'selected' : '' ?>>Daily</option><option value="weekly" <?= ($schedule['interval'] ?? '') === 'weekly' ? 'selected' : '' ?>>Weekly</option></select><span class="field-help">The first automatic run is due one interval after you save.</span></label>
                <?php if (($schedule['enabled'] ?? false) && (int) ($schedule['next_run_at'] ?? 0) > 0): ?><p class="field-help">Next scheduled run: <strong><?= ghostEvalH(date(DATE_ATOM, (int) $schedule['next_run_at'])) ?></strong></p><?php endif; ?>
                <?php if (($schedule['last_status'] ?? '') !== ''): ?><p class="field-help">Schedule status: <?= ghostEvalH((string) $schedule['last_status']) ?></p><?php endif; ?>
                <?php if (($schedule['last_error'] ?? '') !== ''): ?><p class="status-bad"><?= ghostEvalH((string) $schedule['last_error']) ?></p><?php endif; ?>
                <button type="submit" class="button-secondary">Save automatic schedule</button>
            </form>
        </section>

        <section class="card run-history">
            <h2><span class="gradient-text">Recent runs</span></h2>
            <?php if ($runs === []): ?><p class="field-help">No runs yet.</p><?php else: ?>
                <div class="table-scroll"><table><thead><tr><th>Started</th><th>Suite</th><th>State</th><th>Results</th><th>Report</th></tr></thead><tbody>
                    <?php foreach ($runs as $run): $caseResults = $run['results']['cases'] ?? []; $pass = count(array_filter($caseResults, static fn(array $r): bool => ($r['verdict'] ?? '') === 'pass')); $fail = count(array_filter($caseResults, static fn(array $r): bool => ($r['verdict'] ?? '') === 'fail')); $unclear = count(array_filter($caseResults, static fn(array $r): bool => ($r['verdict'] ?? '') === 'unclear')); ?>
                        <tr><td><?= ghostEvalH((string) $run['created_at']) ?></td><td><?= ghostEvalH((string) $run['suite_name']) ?><br><small><?= ghostEvalH(substr((string) $run['id'], 0, 8)) ?></small></td><td><span class="state-pill"><?= ghostEvalH((string) $run['status']) ?></span></td><td><?= $pass ?> pass · <?= $fail ?> fail · <?= $unclear ?> unclear</td><td><?= ghostEvalH((string) ($run['report_name'] ?: 'Pending')) ?><?= $run['report_uploaded'] ? ' · in Files' : '' ?></td></tr>
                    <?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
        </section>
    </div>
    <footer class="footer-note">Reports and suite snapshots can contain sensitive Ghost answers and memory excerpts. The report is written to this group’s Files space.</footer>
</main>
</body>
</html>
