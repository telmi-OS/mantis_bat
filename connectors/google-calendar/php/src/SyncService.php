<?php

declare(strict_types=1);

namespace MantisBat\GoogleCalendar;

use RuntimeException;

final class SyncService
{
    public function __construct(private readonly Config $config, private readonly Storage $storage, private readonly GoogleClient $google, private readonly GhostClient $ghost, private readonly PromptBuilder $prompts)
    {
    }

    public function run(bool $force = false): array
    {
        if (!$force && $this->storage->get('paused', '0') === '1') return ['ok' => true, 'status' => 'paused'];
        $last = (int) $this->storage->get('last_sync_at', '0');
        $interval = max(15, (int) ($this->storage->get('interval_minutes', '15') ?: 15));
        if (!$force && $last > 0 && $last + ($interval * 60) > time()) return ['ok' => true, 'status' => 'not_due', 'next_at' => $last + ($interval * 60)];

        $submissionId = 'gcal-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
        try {
            $calendarId = (string) $this->storage->get('calendar_id', '');
            if ($calendarId === '') throw new RuntimeException('No Google Calendar has been selected.');
            $accessToken = $this->accessToken();
            $from = new \DateTimeImmutable('now', new \DateTimeZone((string) $this->config->get('app.timezone', 'UTC')));
            $windowDays = min(28, max(1, (int) $this->config->get('limits.window_days', 28)));
            $to = $from->modify('+' . $windowDays . ' days');
            $events = $this->google->listEvents($accessToken, $calendarId, $from, $to);
            foreach ($events as $event) $this->storage->upsertEvent($event, !empty($event['is_recurring']));
            $this->storage->set('last_sync_at', (string) time());

            $pending = $this->storage->pendingEvents();
            if ($pending === []) return ['ok' => true, 'status' => 'no_changes', 'fetched' => count($events)];
            $groupName = (string) $this->storage->get('group_name', '');
            if ($groupName === '') throw new RuntimeException('No target group name has been configured.');
            $template = (string) ($this->storage->get('prompt_template', '') ?: $this->prompts->defaultTemplate());
            $prompt = $this->prompts->render($template, $groupName, $submissionId, $pending, $from, $to);
            $maxChars = max(1000, (int) ($this->config->get('limits.max_prompt_chars', 120000)));
            if (strlen($prompt) > $maxChars) throw new RuntimeException('The single sync prompt is too large (' . strlen($prompt) . ' bytes; maximum ' . $maxChars . '). Pending items were retained.');
            $hashes = array_values(array_filter(array_map(static fn(array $event): string => (string) ($event['_hash'] ?? ''), $pending)));
            $this->storage->createRun($submissionId, 'sending', count($pending), $prompt, $hashes);
            $this->ghost->chat($prompt, $submissionId);
            $this->storage->markSubmitted($hashes, $submissionId);
            return ['ok' => true, 'status' => 'accepted', 'submission_id' => $submissionId, 'fetched' => count($events), 'submitted' => count($pending)];
        } catch (\Throwable $exception) {
            try { $this->storage->createRun($submissionId, 'failed', 0, '', []); } catch (\Throwable) { }
            try { $this->storage->updateRun($submissionId, 'failed', $exception->getMessage()); } catch (\Throwable) { }
            $this->notifyError($exception->getMessage());
            return ['ok' => false, 'status' => 'failed', 'submission_id' => $submissionId, 'error' => $exception->getMessage()];
        }
    }

    public function saveSettings(array $settings): void
    {
        if (array_key_exists('group_name', $settings) && (string) $settings['group_name'] === '') throw new RuntimeException('Target group name cannot be empty.');
        foreach (['group_name', 'calendar_id', 'calendar_name', 'google_client_id', 'google_client_secret', 'ghost_api_base', 'ghost_api_token'] as $key) {
            if (array_key_exists($key, $settings)) $this->storage->set($key, (string) $settings[$key]);
        }
        if (array_key_exists('interval_minutes', $settings)) $this->storage->set('interval_minutes', (string) min(720, max(15, (int) $settings['interval_minutes'])));
        if (array_key_exists('prompt_template', $settings)) $this->storage->set('prompt_template', (string) $settings['prompt_template']);
    }

    public function accessToken(): string
    {
        $access = (string) $this->storage->get('google_access_token', '');
        $expires = (int) $this->storage->get('google_access_expires_at', '0');
        if ($access !== '' && $expires > time() + 120) return $access;
        $refresh = (string) $this->storage->get('google_refresh_token', '');
        if ($refresh === '') throw new RuntimeException('Google authorization is required.');
        $token = $this->google->refresh($refresh);
        $access = (string) ($token['access_token'] ?? '');
        if ($access === '') throw new RuntimeException('Google did not return an access token.');
        $this->storage->set('google_access_token', $access);
        $this->storage->set('google_access_expires_at', (string) (time() + (int) ($token['expires_in'] ?? 3600)));
        if (!empty($token['refresh_token'])) $this->storage->set('google_refresh_token', (string) $token['refresh_token']);
        return $access;
    }

    public function notifyError(string $error): void
    {
        $group = (string) $this->storage->get('group_name', '');
        if ($group === '') return;
        $errorHash = hash('sha256', $error);
        if ($this->storage->get('last_error_hash', '') === $errorHash && (int) $this->storage->get('last_error_at', '0') > time() - 3600) return;
        try {
            $this->ghost->chat($this->prompts->errorPrompt($group, $error), 'error-' . bin2hex(random_bytes(4)));
            $this->storage->set('last_error_hash', $errorHash);
            $this->storage->set('last_error_at', (string) time());
        } catch (\Throwable) { }
    }
}
