<?php

declare(strict_types=1);

$services = require dirname(__DIR__) . '/src/bootstrap.php';

/** @var MantisBat\Config $config */
$config = $services['config'];
/** @var MantisBat\Security $security */
$security = $services['security'];
/** @var MantisBat\Logger $logger */
$logger = $services['logger'];
/** @var MantisBat\TelegramClient $telegram */
$telegram = $services['telegram'];
/** @var MantisBat\Storage $storage */
$storage = $services['storage'];
/** @var MantisBat\CommandRouter $commandRouter */
$commandRouter = $services['command_router'];
/** @var MantisBat\ChatHandler $chatHandler */
$chatHandler = $services['chat_handler'];
/** @var MantisBat\MessageSplitter $splitter */
$splitter = $services['splitter'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method not allowed.';
    exit;
}

if (!$config->isInstalled()) {
    http_response_code(503);
    echo 'Not installed.';
    exit;
}

$remoteIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if ($storage->hitRateLimit('webhook_ip', $remoteIp, 180, 60)) {
    http_response_code(429);
    echo 'Too many requests.';
    exit;
}

$expectedSecret = (string) $config->get('telegram.webhook_secret', '');
$providedSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? null;
if ($expectedSecret !== '' && !$security->constantTimeEquals($expectedSecret, is_string($providedSecret) ? $providedSecret : null)) {
    http_response_code(403);
    echo 'Forbidden.';
    exit;
}

$rawBody = file_get_contents('php://input') ?: '';
if ($rawBody === '' || strlen($rawBody) > 262144) {
    http_response_code(400);
    echo 'Invalid request.';
    exit;
}

$update = $telegram->parseUpdate($rawBody);
if ($update === [] || ($update['unsupported'] ?? false) === true) {
    echo json_encode(['ok' => true, 'ignored' => true]);
    exit;
}

$chatId = (string) ($update['chat_id'] ?? '');
$telegramUserId = (string) ($update['from_id'] ?? '');
$text = trim((string) ($update['text'] ?? ''));

if ($telegramUserId !== '' && $storage->hitRateLimit('webhook_user', $telegramUserId, 40, 60)) {
    http_response_code(429);
    echo 'Too many requests.';
    exit;
}

try {
    if ($text === '') {
        $telegram->sendMessage($chatId, 'Mantis Bat currently supports text messages only.');
        echo json_encode(['ok' => true]);
        exit;
    }

    if (preg_match('/^\/start(?:\s+(.+))?$/i', $text, $matches) === 1) {
        $pairingCode = trim((string) ($matches[1] ?? ''));
        if ($pairingCode === '') {
            $telegram->sendMessage($chatId, 'This connector is not paired yet.');
            echo json_encode(['ok' => true]);
            exit;
        }

        $paired = $storage->consumePairingCode($pairingCode, [
            'user_id' => $telegramUserId,
            'chat_id' => $chatId,
            'username' => (string) ($update['username'] ?? ''),
            'first_name' => (string) ($update['first_name'] ?? ''),
            'last_name' => (string) ($update['last_name'] ?? ''),
        ]);

        $telegram->sendMessage(
            $chatId,
            $paired
                ? 'Mantis Bat connected. Your Telegram bot now talks to your Telmi Ghost.'
                : 'Pairing failed. The code is invalid, expired, or already used.'
        );

        echo json_encode(['ok' => true]);
        exit;
    }

    if (!$storage->isAuthorizedTelegramUser($telegramUserId)) {
        $telegram->sendMessage($chatId, 'This Mantis Bat connector is private.');
        echo json_encode(['ok' => true]);
        exit;
    }

    $storage->recordInboundMessage([
        'telegram_update_id' => (string) ($update['update_id'] ?? ''),
        'telegram_message_id' => (string) ($update['message_id'] ?? ''),
        'telegram_user_id' => $telegramUserId,
        'telegram_chat_id' => $chatId,
        'message_type' => 'text',
        'text' => $text,
        'command' => '',
        'status' => 'received',
    ]);

    $commandResult = $commandRouter->handle($text, [
        'telegram_user_id' => $telegramUserId,
        'telegram_chat_id' => $chatId,
    ]);

    if ($commandResult !== null) {
        foreach ($splitter->split($commandResult->text) as $chunk) {
            $telegram->sendMessage($chatId, $chunk);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    $chatHandler->handle($chatId, $text);
    echo json_encode(['ok' => true]);
} catch (Throwable $exception) {
    $logger->exception($exception, ['chat_id' => $chatId, 'telegram_user_id' => $telegramUserId]);
    if ($chatId !== '') {
        try {
            $telegram->sendMessage($chatId, 'Ghost API did not respond.');
        } catch (Throwable) {
        }
    }
    http_response_code(500);
    echo json_encode(['ok' => false]);
}
