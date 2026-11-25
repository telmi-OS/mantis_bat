<?php

declare(strict_types=1);

namespace MantisBat\PrivateMail;

use PDO;
use RuntimeException;

final class MailOperations
{
    private const ATTACHMENT_LIMIT = 2097152;

    public function __construct(private readonly Config $config, private readonly Storage $storage, private readonly GhostClient $ghost, private readonly ?GhostClient $workerGhost = null) {}

    public function rules(): array { return $this->storage->rules(); }

    public function saveRule(array $input): void
    {
        $address = (string) ($input['address'] ?? '');
        $hasSubject = array_key_exists('subject', $input) && $input['subject'] !== null;
        $this->storage->setRule($address, $hasSubject ? (string) $input['subject'] : null, (string) ($input['action'] ?? 'draft'));
    }

    public function deleteRule(int $id): void
    {
        if ($id < 1) throw new RuntimeException('Choose a valid rule.');
        $this->storage->deleteRule($id);
    }

    public function recipientRules(array $input): array
    {
        $subject = (string) ($input['subject'] ?? '');
        $recipients = $this->recipientAddresses($input);
        if ($recipients === []) throw new RuntimeException('Add at least one resolved recipient address to check.');
        $matches = [];
        $requiresApproval = false;
        foreach ($recipients as $address) {
            $action = $this->storage->actionFor($address, $subject, 'draft');
            $query = $this->storage->db->prepare('SELECT id,address,subject,action FROM rules WHERE address=:address COLLATE NOCASE AND (subject=:subject OR subject IS NULL) ORDER BY CASE WHEN subject IS NULL THEN 1 ELSE 0 END LIMIT 1');
            $query->execute([':address'=>$address,':subject'=>$subject]);
            $rule = $query->fetch(PDO::FETCH_ASSOC);
            $hasRule = is_array($rule);
            $approval = $action !== 'auto';
            $requiresApproval = $requiresApproval || $approval;
            $matches[] = ['address'=>$address,'rule'=>$hasRule ? $rule : null,'action'=>$action,'requires_approval'=>$approval];
        }
        return ['recipients'=>$matches,'most_restrictive'=>$requiresApproval ? 'draft' : 'auto','requires_approval'=>$requiresApproval];
    }

    public function search(array $criteria): array
    {
        $where = [];
        $params = [];
        $queryText = trim((string) ($criteria['query'] ?? ''));
        if ($queryText !== '') {
            $where[] = '(m.subject LIKE :query OR m.body LIKE :query OR m.summary LIKE :query OR m.message_id LIKE :query OR EXISTS (SELECT 1 FROM addresses qa WHERE qa.message_id=m.id AND qa.address LIKE :query) OR EXISTS (SELECT 1 FROM attachments qat WHERE qat.message_id=m.id AND (qat.filename LIKE :query OR qat.mime_type LIKE :query OR qat.content_id LIKE :query)))';
            $params[':query'] = '%' . $this->escapeLike($queryText) . '%';
        }
        foreach (['from'=>'from','to'=>'to','cc'=>'cc','bcc'=>'bcc'] as $inputField => $kind) {
            $value = trim((string) ($criteria[$inputField] ?? ''));
            if ($value === '') continue;
            $where[] = 'EXISTS (SELECT 1 FROM addresses a_' . $kind . ' WHERE a_' . $kind . '.message_id=m.id AND a_' . $kind . '.kind=:kind_' . $kind . ' AND a_' . $kind . '.address LIKE :address_' . $kind . ')';
            $params[':kind_' . $kind] = $kind;
            $params[':address_' . $kind] = '%' . $this->escapeLike($value) . '%';
        }
        foreach (['subject'=>'m.subject','body'=>'m.body','summary'=>'m.summary','handling_flag'=>'m.handling_flag','message_id'=>'m.message_id'] as $field => $column) {
            $value = trim((string) ($criteria[$field] ?? ''));
            if ($value !== '') { $where[] = $column . ' LIKE :f_' . $field; $params[':f_' . $field] = '%' . $this->escapeLike($value) . '%'; }
        }
        foreach (['attachment_filename'=>'filename','attachment_mime_type'=>'mime_type','attachment_content_id'=>'content_id'] as $field=>$column) {
            $value=trim((string)($criteria[$field]??''));
            if($value!==''){$where[]='EXISTS (SELECT 1 FROM attachments ax_'.$field.' WHERE ax_'.$field.'.message_id=m.id AND ax_'.$field.'.'.$column.' LIKE :'.$field.')';$params[':'.$field]='%'.$this->escapeLike($value).'%';}
        }
        $folder = (string) ($criteria['folder'] ?? '');
        if ($folder !== '') {
            if (!in_array($folder, ['inbox','sent','ghost_deleted'], true)) throw new RuntimeException('Folder must be inbox, sent, or ghost_deleted.');
            $where[] = 'f.role=:folder'; $params[':folder'] = $folder;
        }
        $status = strtolower(trim((string) ($criteria['status'] ?? '')));
        if ($status !== '') {
            if ($status === 'read') $where[] = 'm.is_read=1';
            elseif ($status === 'unread') $where[] = 'm.is_read=0';
            elseif ($status === 'replied') $where[] = 'm.is_replied=1';
            elseif ($status === 'new') $where[] = "m.processing_status IN ('pending','retry')";
            elseif (in_array($status, ['complete','queued','ignored','baseline','delivery_uncertain','submission_uncertain'], true)) { $where[] = 'm.processing_status=:status'; $params[':status'] = $status; }
            else throw new RuntimeException('Choose a supported message status.');
        }
        foreach (['after'=>'>=','before'=>'<='] as $field => $operator) {
            $date = trim((string) ($criteria[$field] ?? ''));
            if ($date === '') continue;
            try { $date = (new \DateTimeImmutable($date))->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM); }
            catch (\Throwable) { throw new RuntimeException('Search dates must be valid date or date-time values.'); }
            $where[] = 'COALESCE(NULLIF(m.sent_at,""),NULLIF(m.received_at,"")) ' . $operator . ' :date_' . $field;
            $params[':date_' . $field] = $date;
        }
        if (array_key_exists('has_attachments', $criteria) && $criteria['has_attachments'] !== '' && $criteria['has_attachments'] !== null) {
            $has = filter_var($criteria['has_attachments'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($has === null) throw new RuntimeException('has_attachments must be true or false.');
            $where[] = $has
                ? 'EXISTS (SELECT 1 FROM attachments at WHERE at.message_id=m.id)'
                : 'NOT EXISTS (SELECT 1 FROM attachments at WHERE at.message_id=m.id)';
        }
        $limit = max(1, min(100, (int) ($criteria['limit'] ?? 30)));
        $sql = 'SELECT m.id FROM messages m JOIN folders f ON f.id=m.folder_id' . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY COALESCE(NULLIF(m.received_at,""),NULLIF(m.sent_at,"")) DESC,m.id DESC LIMIT ' . $limit;
        $statement = $this->storage->db->prepare($sql);
        $statement->execute($params);
        $emails = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $email = $this->storage->email((int) $id);
            if (is_array($email)) $emails[] = $this->publicEmail($email, false);
        }
        return ['emails'=>$emails,'count'=>count($emails),'limit'=>$limit];
    }

    public function readMessage(int $id, bool $markRead = true): array
    {
        $email = $this->storage->email($id);
        if (!is_array($email)) throw new RuntimeException('Private mail message was not found.');
        if ($markRead && !(bool) $email['is_read']) {
            $this->setMessageFlag($email, '\\Seen', true);
            $update = $this->storage->db->prepare('UPDATE messages SET is_read=1 WHERE id=:id');
            $update->execute([':id'=>$id]);
            $email['is_read'] = 1;
        }
        return $this->publicEmail($email, true);
    }

    public function send(array $input, bool $enforceRules = true): array
    {
        $input['to'] = $this->normalizeRecipientInput($input['to'] ?? []);
        $input['cc'] = $this->normalizeRecipientInput($input['cc'] ?? []);
        $input['bcc'] = $this->normalizeRecipientInput($input['bcc'] ?? []);
        $input['subject'] = trim((string) ($input['subject'] ?? ''));
        $input['body'] = (string) ($input['body'] ?? '');
        if ($enforceRules) {
            $key = trim((string) ($input['idempotency_key'] ?? ''));
            if (!preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $key)) throw new RuntimeException('Supply a unique idempotency_key of 8 to 128 letters, numbers, dots, underscores, colons, or hyphens for this send.');
            $cached = $this->claimOperation($key);
            if ($cached !== null) return $cached;
        } else {
            $key = 'app:' . bin2hex(random_bytes(16));
        }
        try {
            $sent = Smtp::send($this->config->all(), $input);
        } catch (\Throwable $exception) {
            if ($enforceRules) $this->completeOperation($key, 'uncertain', ['message'=>'SMTP delivery could not be confirmed. Check Sent before retrying.']);
            throw $exception;
        }
        $saved = false;
        try {
            $imap = new ImapClient((array) $this->config->get('imap', []));
            $imap->connect();
            $folder = (string) $this->config->get('mail.folders.sent', 'Sent');
            $imap->select($folder);
            $imap->append($folder, (string) $sent['raw'], isset($input['in_reply_to']) ? ['\\Seen','\\Answered'] : ['\\Seen']);
            $saved = true;
            if (isset($input['source_message_id'])) {
                $source = $this->storage->email((int) $input['source_message_id']);
                if (is_array($source)) {
                    $imap->select((string) $source['folder_name']);
                    $imap->setFlag((int) $source['uid'], '\\Answered', true);
                    $update = $this->storage->db->prepare('UPDATE messages SET is_replied=1 WHERE id=:id');
                    $update->execute([':id'=>(int) $source['id']]);
                }
            }
            $imap->close();
        } catch (\Throwable) {
            if (isset($imap)) $imap->close();
            // SMTP has accepted the email. Report that fact and never invite an automatic resend.
        }
        $result = [
            'accepted'=>true,'sent'=>true,'message_id'=>$sent['message_id'],'sent_folder_saved'=>$saved,
            'notice'=>$saved ? 'The email was sent and saved in Sent.' : 'The email was sent, but the server did not confirm a Sent copy. Do not send it again without checking the mailbox.',
        ];
        // Record SMTP acceptance before optional local bookkeeping or Ghost calls.
        if ($enforceRules) $this->completeOperation($key, 'complete', $result);
        try {
        $localId = $this->recordOutgoing($sent, $input);
        $email = $localId !== null ? $this->storage->email($localId) : null;
        $email ??= $this->outgoingEmail($sent, $input);
        $summary = (new MailProcessor($this->config, $this->storage, $this->workerGhost ?? $this->ghost))->summarizeOutgoing($email);
        $actionContext = 'submitted for sending';
        $diary = 'Outgoing to ' . implode(', ', array_merge($sent['to'], $sent['cc'], $sent['bcc'])) . '; subject: ' . $sent['subject']
            . '; handling: ' . $actionContext . '; summary: ' . $summary
            . ((trim((string) ($input['user_instruction'] ?? '')) !== '') ? '; user instruction: ' . trim((string) $input['user_instruction']) : '');
        $this->storage->addDiary($localId, $diary, 'outgoing');
        } catch (\Throwable $exception) {
            error_log('[Private Mail] Sent message bookkeeping failed: ' . $exception->getMessage());
            $result['notice'] .= ' Local summary or diary recording failed.';
        }
        return $result;
    }

    public function reply(array $input, bool $enforceRules = true): array
    {
        $email = $this->storage->email((int) ($input['message_id'] ?? 0));
        if (!is_array($email)) throw new RuntimeException('Private mail message was not found.');
        $plan = ReplyPlan::create($email, (string)$this->config->get('owner.email',''));
        $refs = trim((string) $email['references_header']);
        if ($email['message_id'] !== '') $refs = trim($refs . ' <' . $email['message_id'] . '>');
        $sendInput = $input;
        $sendInput['to'] = $plan['to'];
        $sendInput['cc'] = array_values(array_unique(array_merge($plan['cc'], $this->normalizeRecipientInput($input['cc'] ?? []))));
        $sendInput['subject'] = $plan['subject'];
        $sendInput['source_message_id'] = (int) $email['id'];
        $sendInput['in_reply_to'] = $email['message_id'] !== '' ? '<' . $email['message_id'] . '>' : '';
        $sendInput['references'] = trim($refs);
        return $this->send($sendInput, $enforceRules);
    }

    public function forward(array $input, bool $enforceRules = true): array
    {
        $email = $this->storage->email((int) ($input['message_id'] ?? 0));
        if (!is_array($email)) throw new RuntimeException('Private mail message was not found.');
        $subject = (string) $email['subject'];
        if (!preg_match('/^\s*fwd?\s*:/i', $subject)) $subject = 'Fwd: ' . $subject;
        $addresses = [];
        foreach (['from','to','cc','date'] as $field) {
            if ($field === 'date') { $addresses[] = 'Date: ' . ($email['sent_at'] ?: $email['received_at']); continue; }
            $values = [];
            foreach ($email['addresses'] as $address) if (($address['kind'] ?? '') === $field) $values[] = trim((string) ($address['display_name'] ?? '') . ' <' . $address['address'] . '>');
            $addresses[] = ucfirst($field) . ': ' . implode(', ', $values);
        }
        $forwarded = trim((string) ($input['body'] ?? ''));
        if ($forwarded !== '') $forwarded .= "\r\n\r\n";
        $forwarded .= "---------- Forwarded private mail ----------\r\n" . implode("\r\n", $addresses)
            . "\r\nSubject: " . (string) $email['subject'] . "\r\n\r\n" . (string) $email['body'];
        if (!empty($email['attachments'])) $forwarded .= "\r\n\r\n[Original attachment files are not included in this forward.]";
        $sendInput = $input;
        $sendInput['subject'] = $subject;
        $sendInput['body'] = $forwarded;
        return $this->send($sendInput, $enforceRules);
    }

    public function deleteToGhostFolder(int $id): void
    {
        $email = $this->storage->email($id);
        if (!is_array($email)) throw new RuntimeException('Private mail message was not found.');
        if ($email['folder'] === 'ghost_deleted') throw new RuntimeException('This message is already in Ghost Deleted.');
        $imap = new ImapClient((array) $this->config->get('imap', []));
        try {
            $imap->connect();
            $imap->select((string) $email['folder_name']);
            $imap->move((int) $email['uid'], (string) $this->config->get('mail.folders.ghost_deleted', 'Ghost Deleted'));
        } finally { $imap->close(); }
        $query = $this->storage->db->prepare('DELETE FROM messages WHERE id=:id');
        $query->execute([':id'=>$id]);
    }

    public function restoreFromGhostFolder(int $id): void
    {
        $email = $this->storage->email($id);
        if (!is_array($email) || $email['folder'] !== 'ghost_deleted') throw new RuntimeException('Choose a message from Ghost Deleted to restore.');
        $imap = new ImapClient((array) $this->config->get('imap', []));
        try {
            $imap->connect();
            $imap->select((string) $email['folder_name']);
            $inboxName=(string)$this->config->get('mail.folders.inbox','INBOX');
            $imap->move((int) $email['uid'], $inboxName);
            $validity=$imap->select($inboxName);
            $uids=$imap->searchMessageId((string)$email['message_id']);
            if($uids!==[]){
                $inbox=$this->storage->roleFolder('inbox');
                if(is_array($inbox)){
                    $update=$this->storage->db->prepare('UPDATE messages SET folder_id=:folder,uidvalidity=:validity,uid=:uid WHERE id=:id');
                    $update->execute([':folder'=>(int)$inbox['id'],':validity'=>$validity,':uid'=>max($uids),':id'=>$id]);
                    return;
                }
            }
            $from=[];
            foreach((array)$email['addresses'] as $address)if(($address['kind']??'')==='from')$from[]=(string)$address['address'];
            $fingerprint=MimeParser::messageFingerprint(['from'=>implode(', ',$from),'subject'=>(string)$email['subject'],'date'=>(string)$email['sent_at']],(string)$email['body']);
            $operationKey='restore:'.$fingerprint;
            $marker=$this->storage->db->prepare('INSERT OR REPLACE INTO operations(operation_key,kind,status,created_at,result_json) VALUES(:key,"restore_marker","pending",:created,:result)');
            $marker->execute([':key'=>$operationKey,':created'=>gmdate(DATE_ATOM),':result'=>json_encode([
                'summary'=>(string)$email['summary'],'rule_action'=>(string)$email['rule_action'],'handling_flag'=>(string)$email['handling_flag'],
                'processing_status'=>(string)$email['processing_status'],'processing_error'=>(string)$email['processing_error'],
                'notified_at'=>(string)$email['notified_at'],'queued_at'=>(string)$email['queued_at'],
            ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
        }
        finally { $imap->close(); }
        // If the server cannot search by Message-ID, leave the cached row in Ghost Deleted
        // until the next synchronization reconciles the move into INBOX.
    }

    public function permanentlyDeleteFromGhostFolder(int $id): void
    {
        $email = $this->storage->email($id);
        if (!is_array($email) || $email['folder'] !== 'ghost_deleted') throw new RuntimeException('Permanent deletion is only available from Ghost Deleted.');
        $imap = new ImapClient((array) $this->config->get('imap', []));
        try { $imap->connect(); $imap->select((string) $email['folder_name']); $imap->permanentlyDelete((int) $email['uid']); }
        finally { $imap->close(); }
        $query = $this->storage->db->prepare('DELETE FROM messages WHERE id=:id');
        $query->execute([':id'=>$id]);
    }

    public function setRead(int $id, bool $read): void
    {
        $email = $this->storage->email($id);
        if (!is_array($email)) throw new RuntimeException('Private mail message was not found.');
        $this->setMessageFlag($email, '\\Seen', $read);
        $query = $this->storage->db->prepare('UPDATE messages SET is_read=:read WHERE id=:id');
        $query->execute([':read'=>$read ? 1 : 0, ':id'=>$id]);
    }

    public function requestAttachment(int $attachmentId): array
    {
        $query = $this->storage->db->prepare('SELECT a.*,m.uid,m.uidvalidity,f.remote_name FROM attachments a JOIN messages m ON m.id=a.message_id JOIN folders f ON f.id=m.folder_id WHERE a.id=:id');
        $query->execute([':id'=>$attachmentId]);
        $attachment = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($attachment)) throw new RuntimeException('Private mail attachment was not found.');
        if ((int) $attachment['size'] > self::ATTACHMENT_LIMIT) {
            return ['ok'=>false,'code'=>'attachment_too_large','message'=>'This attachment is larger than the 2 MB per-attachment limit. It was not downloaded.','attachment_id'=>$attachmentId,'size'=>(int) $attachment['size'],'limit'=>self::ATTACHMENT_LIMIT];
        }
        $imap = new ImapClient((array) $this->config->get('imap', []));
        $temporaryDirectory = dirname($this->config->path()) . '/tmp';
        if (!is_dir($temporaryDirectory) && !mkdir($temporaryDirectory, 0700, true) && !is_dir($temporaryDirectory)) throw new RuntimeException('Could not prepare private temporary attachment storage.');
        @chmod($temporaryDirectory, 0700);
        $temporary = tempnam($temporaryDirectory, 'private-mail-');
        if ($temporary === false) throw new RuntimeException('Could not create a temporary attachment file.');
        @chmod($temporary, 0600);
        try {
            $imap->connect();
            $imap->select((string) $attachment['remote_name']);
            $encodedSize = (int) $attachment['encoded_size'];
            if ($encodedSize < 1) throw new RuntimeException('This attachment has no reliable size metadata, so it was not downloaded.');
            $bytes = $imap->fetchPart((int) $attachment['uid'], (string) $attachment['part'], $encodedSize);
            $bytes = MimeParser::decodeAttachment($bytes, (string) $attachment['transfer_encoding']);
            if (strlen($bytes) > self::ATTACHMENT_LIMIT) throw new RuntimeException('This attachment is larger than the 2 MB per-attachment limit. It was not uploaded.');
            if (file_put_contents($temporary, $bytes, LOCK_EX) === false) throw new RuntimeException('Could not save the requested temporary attachment.');
            $imap->close();
            $uploaded = $this->ghost->uploadFile($temporary, (string) ($attachment['filename'] ?: 'attachment'), (string) $attachment['mime_type'],
                (string) $this->config->get('files.space_id', ''), (string) $this->config->get('files.folder_id', ''));
            return ['ok'=>true,'attachment'=>[
                'attachment_id'=>$attachmentId,'filename'=>$attachment['filename'],'mime_type'=>$attachment['mime_type'],
                'size'=>filesize($temporary),'ghost_file'=>$uploaded['data'] ?? $uploaded,
            ]];
        } finally { $imap->close(); @unlink($temporary); }
    }

    public function listMessages(string $folder, string $filter = '', int $limit = 100): array
    {
        if (!in_array($folder, ['inbox','sent','ghost_deleted'], true)) $folder = 'inbox';
        $params = [':folder'=>$folder];
        $where = 'f.role=:folder';
        if ($filter !== '') { $where .= ' AND (m.subject LIKE :filter OR m.body LIKE :filter OR m.summary LIKE :filter OR EXISTS(SELECT 1 FROM addresses a WHERE a.message_id=m.id AND a.address LIKE :filter))'; $params[':filter']='%'.$this->escapeLike($filter).'%'; }
        $query = $this->storage->db->prepare('SELECT m.id FROM messages m JOIN folders f ON f.id=m.folder_id WHERE ' . $where . ' ORDER BY COALESCE(NULLIF(m.received_at,""),NULLIF(m.sent_at,"")) DESC,m.id DESC LIMIT ' . max(1,min(200,$limit)));
        $query->execute($params);
        $rows=[];
        foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $id) { $email=$this->storage->email((int)$id); if (is_array($email)) $rows[]=$this->publicEmail($email,false); }
        return $rows;
    }

    public function listEmail(int $id): ?array
    {
        $email=$this->storage->email($id);
        return is_array($email) ? $this->publicEmail($email,true) : null;
    }

    private function setMessageFlag(array $email, string $flag, bool $enabled): void
    {
        if ((string) $email['uidvalidity'] === 'local') return;
        $imap = new ImapClient((array) $this->config->get('imap', []));
        try { $imap->connect(); $imap->select((string) $email['folder_name']); $imap->setFlag((int) $email['uid'], $flag, $enabled); }
        finally { $imap->close(); }
    }

    private function claimOperation(string $key): ?array
    {
        $query = $this->storage->db->prepare('SELECT status,result_json FROM operations WHERE operation_key=:key');
        $query->execute([':key'=>$key]);
        $existing = $query->fetch(PDO::FETCH_ASSOC);
        if (is_array($existing)) {
            if ($existing['status'] === 'complete') return json_decode((string) $existing['result_json'], true) ?: ['accepted'=>true,'message'=>'This idempotent send was already accepted.'];
            throw new RuntimeException('A send with this idempotency_key is already pending or uncertain. Check Sent before retrying.');
        }
        $insert = $this->storage->db->prepare('INSERT INTO operations(operation_key,kind,status,created_at) VALUES(:key,"send","sending",:created)');
        $insert->execute([':key'=>$key,':created'=>gmdate(DATE_ATOM)]);
        return null;
    }

    private function completeOperation(string $key, string $status, array $result): void
    {
        $query = $this->storage->db->prepare('UPDATE operations SET status=:status,result_json=:result WHERE operation_key=:key');
        $query->execute([':status'=>$status,':result'=>json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),':key'=>$key]);
    }

    private function recordOutgoing(array $sent, array $input): ?int
    {
        $folder = $this->storage->roleFolder('sent');
        $folderId = is_array($folder) ? (int)$folder['id'] : false;
        if ($folderId === false) return null;
        $find = $this->storage->db->prepare('SELECT id FROM messages WHERE folder_id=:folder AND message_id=:message_id');
        $find->execute([':folder'=>$folderId,':message_id'=>$sent['message_id']]);
        $id=$find->fetchColumn();
        if ($id !== false) return (int)$id;
        $owner=(array)$this->config->get('owner',[]);
        $localUid=-random_int(1,2147483647);
        $insert=$this->storage->db->prepare("INSERT INTO messages(folder_id,uidvalidity,uid,message_id,subject,body,sent_at,received_at,is_read,processing_status,handling_flag)
            VALUES(:folder,'local',:uid,:message_id,:subject,:body,:sent_at,:received_at,1,'sent','outgoing')");
        $insert->execute([
            ':folder'=>$folderId,':uid'=>$localUid,':message_id'=>$sent['message_id'],':subject'=>$sent['subject'],
            ':body'=>$sent['body'],':sent_at'=>$sent['sent_at'],':received_at'=>$sent['sent_at'],
        ]);
        $messageId=(int)$this->storage->db->lastInsertId();
        $all=['from'=>[[(string)($owner['email']??''),(string)($owner['name']??'')]],'to'=>[],'cc'=>[],'bcc'=>[]];
        foreach(['to','cc','bcc'] as $field) foreach($sent[$field] as $address)$all[$field][]=[$address,''];
        foreach($all as $kind=>$addresses) foreach($addresses as $position=>[$address,$name]) {
            if(!filter_var($address,FILTER_VALIDATE_EMAIL))continue;
            $add=$this->storage->db->prepare('INSERT INTO addresses(message_id,kind,position,address,display_name) VALUES(:id,:kind,:position,:address,:name)');
            $add->execute([':id'=>$messageId,':kind'=>$kind,':position'=>$position,':address'=>strtolower($address),':name'=>$name]);
        }
        return $messageId;
    }

    private function outgoingEmail(array $sent, array $input): array
    {
        $addresses=[];
        $owner=(array)$this->config->get('owner',[]);
        $addresses[]=['kind'=>'from','address'=>(string)($owner['email']??''),'display_name'=>(string)($owner['name']??'')];
        foreach(['to'=>'to','cc'=>'cc','bcc'=>'bcc'] as $field=>$kind)foreach($sent[$field] as $address)$addresses[]=['kind'=>$kind,'address'=>$address,'display_name'=>''];
        return ['id'=>null,'folder'=>'sent','message_id'=>$sent['message_id'],'subject'=>$sent['subject'],'body'=>$sent['body'],
            'sent_at'=>$sent['sent_at'],'received_at'=>$sent['sent_at'],'addresses'=>$addresses,'attachments'=>[]];
    }

    private function publicEmail(array $email, bool $includeBody): array
    {
        $result = [
            'id'=>(int)$email['id'],'message_id'=>(string)$email['message_id'],'folder'=>(string)$email['folder'],
            'subject'=>(string)$email['subject'],'sent_at'=>(string)$email['sent_at'],'received_at'=>(string)$email['received_at'],
            'status'=>[
                'new'=>in_array((string)$email['processing_status'],['pending','retry'],true),
                'read'=>(bool)$email['is_read'],'replied'=>(bool)$email['is_replied'],
                'processing'=>(string)$email['processing_status'],
            ],
            'handling_flag'=>(string)$email['handling_flag'],'rule_action'=>(string)$email['rule_action'],'summary'=>(string)$email['summary'],
            'body_preview'=>mb_substr(trim(preg_replace('/\s+/u',' ',(string)$email['body'])??(string)$email['body']),0,240),
            'addresses'=>[], 'attachments'=>array_map(static fn(array $a): array => [
                'attachment_id'=>(int)$a['id'],'filename'=>(string)$a['filename'],'mime_type'=>(string)$a['mime_type'],
                'size'=>(int)$a['size'],'encoded_size'=>(int)$a['encoded_size'],
                'size_is_upper_bound'=>strtolower((string)$a['transfer_encoding'])==='base64',
                'content_id'=>(string)$a['content_id'],'disposition'=>(string)$a['disposition'],
            ], (array)$email['attachments']),
        ];
        foreach (['from','to','cc','bcc','reply_to'] as $kind) {
            $result['addresses'][$kind] = array_values(array_map(static fn(array $address): array => [
                'email'=>(string)$address['address'],'name'=>(string)$address['display_name'],
            ], array_filter((array)$email['addresses'],static fn(array $a): bool => ($a['kind']??'')===$kind)));
        }
        if ($includeBody) {
            $result['body']=(string)$email['body'];
            if ((string)($email['processing_error']??'')!=='') $result['processing_error']=(string)$email['processing_error'];
        }
        return $result;
    }

    private function recipientAddresses(array $input): array
    {
        $result=[];
        foreach (['to','cc','bcc'] as $field) {
            $value=$input[$field]??[];
            foreach ($this->normalizeRecipientInput($value) as $address) $result[]=$address;
        }
        return array_values(array_unique($result));
    }

    private function normalizeRecipientInput(mixed $input): array
    {
        $values=is_array($input)?$input:(is_string($input)?[$input]:[]);
        $result=[];
        foreach($values as $value) {
            if(!is_string($value)) continue;
            foreach(MimeParser::addresses($value) as $parsed)$result[]=(string)$parsed['address'];
            if(filter_var(trim($value),FILTER_VALIDATE_EMAIL))$result[]=strtolower(trim($value));
        }
        return array_values(array_unique($result));
    }

    private function escapeLike(string $value): string { return str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$value); }
}
