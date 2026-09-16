<?php

declare(strict_types=1);

$services = require dirname(__DIR__) . '/src/bootstrap.php';
foreach (['config', 'installer', 'auth'] as $service) {
    if (!array_key_exists($service, $services)) {
        throw new RuntimeException('Bootstrap did not expose the expected service: ' . $service);
    }
}

foreach (['Crypto', 'Storage', 'PromptBuilder', 'GoogleClient'] as $file) {
    require_once dirname(__DIR__) . '/src/' . $file . '.php';
}

use MantisBat\GoogleCalendar\Crypto;
use MantisBat\GoogleCalendar\PromptBuilder;
use MantisBat\GoogleCalendar\Storage;
use MantisBat\GoogleCalendar\GoogleClient;

$fail = static function (string $message): never { throw new RuntimeException($message); };
$crypto = new Crypto();
$key = $crypto->deriveKey('a sufficiently long test password', 'test-salt');
$sealed = $crypto->seal('private calendar value', $key);
if ($crypto->open($sealed, $key) !== 'private calendar value') $fail('Crypto round trip failed.');
$tampered = substr($sealed, 0, -1) . (substr($sealed, -1) === 'A' ? 'B' : 'A');
try { $crypto->open($tampered, $key); $fail('Tampered ciphertext was accepted.'); } catch (RuntimeException) { }

$db = tempnam(sys_get_temp_dir(), 'gcc-smoke-');
$storage = new Storage($db, $key);
$storage->migrate();
$event = ['identity' => 'calendar:event', 'title' => '@Action Ghost do this', 'start' => '2026-09-17T10:00:00+02:00', 'end' => '2026-09-17T11:00:00+02:00', 'all_day' => false, 'status' => 'confirmed', 'is_recurring' => false];
$storage->upsertEvent($event, false);
if (count($storage->pendingEvents()) !== 1) $fail('New event was not marked pending.');
$storage->markSubmitted([hash('sha256', 'calendar:event')], 'missing-run');
$event['title'] = 'Changed title';
$storage->upsertEvent($event, false);
$pending = $storage->pendingEvents();
if (count($pending) !== 1 || ($pending[0]['_previous']['title'] ?? '') !== '@Action Ghost do this') $fail('Changed event did not retain its previous title.');

$builder = new PromptBuilder(dirname(__DIR__) . '/templates/sync-prompt.txt');
$prompt = $builder->render($builder->defaultTemplate(), 'Exact Group Name', 'run-1', $pending, new DateTimeImmutable('now'), new DateTimeImmutable('+28 days'));
if (!str_contains($prompt, 'Exact Group Name') || !str_contains($prompt, 'Changed title') || !str_contains($prompt, '@Action Ghost do this')) $fail('Prompt rendering failed.');

$google = new GoogleClient('id', 'secret', 'https://example.test/callback');
$normalizer = new ReflectionMethod($google, 'normalizeEvent');
$normalizer->setAccessible(true);
$from = new DateTimeImmutable('2026-09-16T12:00:00+02:00');
$to = $from->modify('+28 days');
$overlap = $normalizer->invoke($google, ['id' => 'all-day', 'summary' => 'Overlapping', 'start' => ['date' => '2026-09-15'], 'end' => ['date' => '2026-09-18']], 'calendar', $from, $to);
if (!is_array($overlap) || $overlap['all_day'] !== true) $fail('Overlapping all-day event was not included.');
$past = $normalizer->invoke($google, ['id' => 'past', 'summary' => 'Past', 'start' => ['date' => '2026-09-01'], 'end' => ['date' => '2026-09-02']], 'calendar', $from, $to);
if ($past !== null) $fail('Past all-day event was incorrectly included.');

echo "Google Calendar connector smoke tests passed.\n";
