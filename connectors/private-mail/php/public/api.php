<?php

declare(strict_types=1);

$services = require dirname(__DIR__) . '/src/bootstrap.php';
$config = $services['config'];
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$providedKey = isset($_SERVER['HTTP_X_MAIL_KEY']) && is_string($_SERVER['HTTP_X_MAIL_KEY']) ? $_SERVER['HTTP_X_MAIL_KEY'] : '';
if (!$config->installed() || !$services['security']->equals((string) $config->get('app.api_key', ''), $providedKey)) {
    http_response_code(404);
    echo json_encode(['ok'=>false,'error'=>'Not found.']);
    exit;
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = isset($_GET['action']) && is_string($_GET['action']) ? $_GET['action'] : '';
$body = file_get_contents('php://input');
$input = [];
if ($body !== false && trim($body) !== '') {
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Request body must be a JSON object.']); exit; }
    $input = $decoded;
}

try {
    $operations = $services['operations'];
    $result = match ($action) {
        'rules' => privateMailRules($operations, $method, $input),
        'recipient_rules' => privateMailRecipientRules($operations, $method, $input),
        'search' => privateMailSearch($operations, $method, $input),
        'message' => privateMailMessage($operations, $method, $input),
        'send' => privateMailSend($operations, $method, $input),
        'reply' => privateMailReply($operations, $method, $input),
        'forward' => privateMailForward($operations, $method, $input),
        'delete' => privateMailDelete($operations, $method, $input),
        'attachment' => privateMailAttachment($operations, $method, $input),
        default => throw new InvalidArgumentException('Unknown private mail operation.'),
    };
    $result['ok'] = $result['ok'] ?? true;
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException|RuntimeException $exception) {
    $message = $exception->getMessage();
    http_response_code(str_contains($message, 'not found') ? 404 : (str_contains($message, 'Approval is required') ? 409 : 400));
    echo json_encode(['ok'=>false,'error'=>$message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $exception) {
    error_log('[Private Mail API] ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Private mail could not complete this operation. Check the protected app status page for details.']);
}

function privateMailRules(object $operations, string $method, array $input): array
{
    if ($method === 'POST') {
        if (isset($input['delete_rule_id'])) $operations->deleteRule((int) $input['delete_rule_id']);
        elseif (isset($input['address'])) {
            if (array_key_exists('match_subject', $input)) {
                $matchSubject = filter_var($input['match_subject'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($matchSubject === null) throw new InvalidArgumentException('match_subject must be true or false.');
                if ($matchSubject && !array_key_exists('subject', $input)) throw new InvalidArgumentException('Provide the exact subject when match_subject is true.');
                if (!$matchSubject) unset($input['subject']);
            }
            $operations->saveRule($input);
        }
        elseif ($input !== []) throw new InvalidArgumentException('Provide address and action to create a rule, or delete_rule_id to remove one.');
    } elseif ($method !== 'GET') throw new InvalidArgumentException('Rules support GET and POST.');
    return ['rules'=>$operations->rules()];
}

function privateMailRecipientRules(object $operations, string $method, array $input): array
{
    if ($method !== 'POST') throw new InvalidArgumentException('Recipient rule checks require POST.');
    return $operations->recipientRules($input);
}

function privateMailSearch(object $operations, string $method, array $input): array
{
    if ($method !== 'POST') throw new InvalidArgumentException('Private mail search requires POST.');
    return $operations->search($input);
}

function privateMailMessage(object $operations, string $method, array $input): array
{
    if (!in_array($method, ['GET','POST'], true)) throw new InvalidArgumentException('Message reading supports GET and POST.');
    $id = (int) ($input['message_id'] ?? $_GET['id'] ?? 0);
    return ['email'=>$operations->readMessage($id)];
}

function privateMailSend(object $operations, string $method, array $input): array
{
    if ($method !== 'POST') throw new InvalidArgumentException('Sending private mail requires POST.');
    return $operations->send($input, true);
}

function privateMailReply(object $operations, string $method, array $input): array
{
    if ($method !== 'POST') throw new InvalidArgumentException('Replying to private mail requires POST.');
    return $operations->reply($input, true);
}

function privateMailForward(object $operations, string $method, array $input): array
{
    if ($method !== 'POST') throw new InvalidArgumentException('Forwarding private mail requires POST.');
    return $operations->forward($input, true);
}

function privateMailDelete(object $operations, string $method, array $input): array
{
    if ($method !== 'POST') throw new InvalidArgumentException('Moving private mail to Ghost Deleted requires POST.');
    $operations->deleteToGhostFolder((int) ($input['message_id'] ?? 0));
    return ['deleted'=>true,'folder'=>'ghost_deleted','message'=>'Message moved to Ghost Deleted and can be restored by the user in the app.'];
}

function privateMailAttachment(object $operations, string $method, array $input): array
{
    if ($method !== 'POST') throw new InvalidArgumentException('Requesting private mail attachments requires POST.');
    return $operations->requestAttachment((int) ($input['attachment_id'] ?? 0));
}
