<?php

declare(strict_types=1);

namespace MantisBat\GoogleCalendar;

final class PromptBuilder
{
    public function __construct(private readonly string $defaultTemplatePath)
    {
    }

    public function defaultTemplate(): string
    {
        $template = @file_get_contents($this->defaultTemplatePath);
        return $template !== false ? trim($template) : "Synchronize the following Google Calendar changes with the schedules on the Board. Google Calendar is the source of truth. Send questions to group \"{{group_name}}\".\n\n{{items}}";
    }

    public function render(string $template, string $groupName, string $submissionId, array $events, \DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        $lines = [];
        foreach ($events as $index => $event) {
            $status = ($event['status'] ?? 'confirmed') === 'cancelled' ? 'Cancelled calendar schedule' : 'Calendar schedule';
            $title = (string) ($event['title'] ?? '');
            $when = !empty($event['all_day'])
                ? 'all day from ' . (string) $event['start'] . ' through ' . (string) $event['end'] . ' (end date is exclusive)'
                : 'from ' . (string) $event['start'] . ' to ' . (string) $event['end'];
            $line = ($index + 1) . '. ' . $status . ': title "' . $title . '"; ' . $when . '.';
            if (($event['status'] ?? 'confirmed') !== 'cancelled' && is_array($event['_previous'] ?? null)) {
                $previous = $event['_previous'];
                $previousWhen = !empty($previous['all_day'])
                    ? 'all day from ' . (string) ($previous['start'] ?? '') . ' through ' . (string) ($previous['end'] ?? '')
                    : 'from ' . (string) ($previous['start'] ?? '') . ' to ' . (string) ($previous['end'] ?? '');
                $line .= ' This changed from title "' . (string) ($previous['title'] ?? '') . '"; ' . $previousWhen . '.';
            }
            $lines[] = $line;
        }
        $replacements = [
            '{{group_name}}' => $groupName,
            '{{submission_id}}' => $submissionId,
            '{{from}}' => $from->format(DATE_ATOM),
            '{{to}}' => $to->format(DATE_ATOM),
            '{{items}}' => implode("\n", $lines),
        ];
        return strtr($template, $replacements);
    }

    public function errorPrompt(string $groupName, string $error): string
    {
        return 'Send a message to group ' . $groupName . '. Google Calendar Sync App reported the following error: ' . $error;
    }
}
