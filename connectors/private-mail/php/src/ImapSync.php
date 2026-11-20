<?php

declare(strict_types=1);

namespace MantisBat\PrivateMail;

use PDO;
use RuntimeException;

/** Mirrors the three configured server folders, downloading body text only. */
final class ImapSync
{
    public function __construct(private readonly Config $config, private readonly Storage $storage) {}

    public function sync(int $maxNewMessagesPerFolder = 30): array
    {
        $imap = new ImapClient((array) $this->config->get('imap', []));
        $imap->connect();
        $this->imap = $imap;
        $result = ['new_messages' => 0, 'removed_messages' => 0, 'folders' => [], 'complete' => true];
        try {
            $folderMap = (array) $this->config->get('mail.folders', []);
            $imap->ensureFolder((string) ($folderMap['ghost_deleted'] ?? 'Ghost Deleted'));
            foreach (['inbox','sent','ghost_deleted'] as $role) {
                $remoteName = (string) ($folderMap[$role] ?? '');
                if ($remoteName === '') throw new RuntimeException('A server folder is not configured for ' . $role . '.');
                $this->storage->setFolder($role, $remoteName);
                $uidValidity = $imap->select($remoteName);
                $folder = $this->storage->roleFolder($role);
                if (!is_array($folder)) throw new RuntimeException('Could not initialize the local ' . $role . ' folder.');
                $folderId = (int) $folder['id'];

                if ((string) $folder['uidvalidity'] !== '' && (string) $folder['uidvalidity'] !== $uidValidity) {
                    $delete = $this->storage->db->prepare('DELETE FROM messages WHERE folder_id=:folder_id');
                    $delete->execute([':folder_id' => $folderId]);
                    $reset = $this->storage->db->prepare('UPDATE folders SET sync_complete=0 WHERE id=:id');
                    $reset->execute([':id' => $folderId]);
                    $clear = $this->storage->db->prepare('DELETE FROM sync_state WHERE role=:role');
                    $clear->execute([':role' => $role]);
                    $folder['sync_complete'] = 0;
                }
                $this->updateFolderValidity($folderId, $uidValidity);

                $uids = $imap->searchAll();
                sort($uids, SORT_NUMERIC);
                $localUids = $this->localUidSet($folderId, $uidValidity);
                foreach (array_chunk($uids, 500) as $flagBatch) {
                    $flagMap = $imap->fetchFlags($flagBatch);
                    foreach ($flagMap as $uid => $flags) $this->updateFlags($folderId, $uidValidity, (int) $uid, $flags);
                }
                $state = $this->syncState($role, $uidValidity);
                if ($state === null) {
                    $snapshot = (bool) $folder['sync_complete']
                        ? array_values(array_diff($uids, array_map('intval', array_keys($localUids))))
                        : $uids;
                    $this->startSnapshot($role, $uidValidity, $snapshot);
                    $state = $this->syncState($role, $uidValidity);
                }
                $snapshot = json_decode((string) $state['snapshot_json'], true);
                if (!is_array($snapshot)) $snapshot = $uids;
                $snapshot = array_values(array_filter(array_map('intval', $snapshot), static fn(int $uid): bool => $uid > 0));
                $cursor = max(0, (int) $state['cursor']);
                $batchSize = max(1, min(1000, $maxNewMessagesPerFolder));
                $end = min(count($snapshot), $cursor + $batchSize);
                $batch = array_slice($snapshot, $cursor, $end - $cursor);

                foreach ($batch as $uid) {
                    if (isset($localUids[$uid])) continue;
                    try {
                        $message = $imap->fetchMessage($uid);
                    } catch (RuntimeException $exception) {
                        // A UID can disappear between SEARCH and FETCH. Reconcile it at completion.
                        if (in_array($uid, $imap->searchAll(), true)) throw $exception;
                        continue;
                    }
                    $saved = $this->storeMessage($folderId, $role, $uidValidity, $uid, $message, (bool) $folder['sync_complete']);
                    if ($saved) $result['new_messages']++;
                }

                $this->advanceCursor($role, $end);
                $done = $end >= count($snapshot);
                if ($done) {
                    $currentUids = $imap->searchAll();
                    $removed = $this->reconcile($folderId, $uidValidity, $currentUids);
                    $result['removed_messages'] += $removed;
                    $mark = $this->storage->db->prepare('UPDATE folders SET sync_complete=1,last_synced_at=:at WHERE id=:id');
                    $mark->execute([':at' => gmdate(DATE_ATOM), ':id' => $folderId]);
                    $clear = $this->storage->db->prepare('DELETE FROM sync_state WHERE role=:role');
                    $clear->execute([':role' => $role]);
                } else {
                    $result['complete'] = false;
                }
                $result['folders'][$role] = ['processed' => count($batch), 'total' => count($snapshot), 'complete' => $done];
            }
        } finally {
            $imap->close();
        }
        return $result;
    }

    private function storeMessage(int $folderId, string $role, string $uidValidity, int $uid, array $mail, bool $baselineComplete): bool
    {
        $headers = $mail['headers'];
        $structure = MimeParser::bodyParts($mail['structure']);
        $textParts = $structure['parts'];
        usort($textParts, static fn(array $a, array $b): int => ($a['subtype'] === 'plain' ? 0 : 1) <=> ($b['subtype'] === 'plain' ? 0 : 1));
        $body = '';
        foreach ($textParts as $part) {
            $raw = $this->currentImap()->fetchPart($uid, (string) $part['part']);
            $decoded = MimeParser::decodeBody($raw, (string) $part['encoding'], (string) $part['mime_type']);
            if ($decoded !== '') $body .= ($body === '' ? '' : "\n\n") . $decoded;
            // Alternative bodies duplicate the same content; retain the plain version when present.
            if ($part['subtype'] === 'plain' && $body !== '') break;
        }
        $flags = array_map('strtolower', (array) $mail['flags']);
        $received = $mail['internal_date'] !== '' ? $mail['internal_date'] : (string) ($headers['date'] ?? '');
        $receivedAt = self::isoDate($received);
        $sentAt = self::isoDate((string) ($headers['date'] ?? $received));
        $messageId = trim((string) ($headers['message-id'] ?? ''), " <>\t\r\n");
        $sender = $this->addresses($headers['from'] ?? '', 'from');
        $action = '';
        $processing = 'baseline';
        $flag = '';
        $restoreKey = '';
        $restoreState = null;
        if ($role === 'inbox' && $baselineComplete) {
            $restoreKey = 'restore:' . MimeParser::messageFingerprint($headers, $body);
            $markerQuery = $this->storage->db->prepare('SELECT result_json FROM operations WHERE operation_key=:key AND kind="restore_marker" AND status="pending"');
            $markerQuery->execute([':key'=>$restoreKey]);
            $marker = $markerQuery->fetchColumn();
            if (is_string($marker)) {
                $restoreState=json_decode($marker,true);
                if (!is_array($restoreState)) $restoreState=[];
                $action=(string)($restoreState['rule_action']??'');
                $processing=(string)($restoreState['processing_status']??'baseline');
                $flag=(string)($restoreState['handling_flag']??$action);
            } else {
                $senderEmail = (string) ($sender[0]['address'] ?? '');
                $subject = (string) ($headers['subject'] ?? '');
                $action = $this->storage->actionFor($senderEmail, $subject, (string) $this->config->get('rules.default', 'summarize_notify'));
                $processing = $action === 'ignore' ? 'ignored' : 'pending';
                $flag = $action;
            }
        }

        $this->storage->db->beginTransaction();
        try {
            $lookup = $this->storage->db->prepare('SELECT id FROM messages WHERE folder_id=:folder AND uidvalidity=:validity AND uid=:uid');
            $lookup->execute([':folder' => $folderId, ':validity' => $uidValidity, ':uid' => $uid]);
            $existingId = $lookup->fetchColumn();
            if ($existingId !== false) {
                $messageIdLocal = (int) $existingId;
                $update = $this->storage->db->prepare('UPDATE messages SET message_id=:message_id,subject=:subject,body=:body,sent_at=:sent_at,received_at=:received_at,is_read=:is_read,is_replied=:is_replied,in_reply_to=:in_reply_to,references_header=:references_header WHERE id=:id');
                $update->execute([
                    ':message_id' => $messageId, ':subject' => (string) ($headers['subject'] ?? ''), ':body' => $body,
                    ':sent_at' => $sentAt, ':received_at' => $receivedAt, ':is_read' => in_array('\\seen', $flags, true) ? 1 : 0,
                    ':is_replied' => in_array('\\answered', $flags, true) ? 1 : 0, ':in_reply_to' => (string) ($headers['in-reply-to'] ?? ''),
                    ':references_header' => (string) ($headers['references'] ?? ''), ':id' => $messageIdLocal,
                ]);
                $this->replaceParts($messageIdLocal, $headers, $structure['attachments'], $role === 'sent');
            } else {
                // Reuse a just-sent local row when the server assigns its Sent UID after SMTP acceptance.
                $reuse = false;
                if ($messageId !== '') {
                    $duplicate = $this->storage->db->prepare('SELECT id FROM messages WHERE folder_id=:folder AND uidvalidity="local" AND message_id=:message_id AND message_id<>"" ORDER BY id DESC LIMIT 1');
                    $duplicate->execute([':folder' => $folderId, ':message_id' => $messageId]);
                    $candidate = $duplicate->fetchColumn();
                    if ($candidate !== false) {
                        $messageIdLocal = (int) $candidate;
                        $updateUid = $this->storage->db->prepare('UPDATE messages SET uidvalidity=:validity,uid=:uid,subject=:subject,body=:body,sent_at=:sent_at,received_at=:received_at,is_read=1,is_replied=:replied,processing_status="sent",in_reply_to=:in_reply_to,references_header=:references_header WHERE id=:id');
                        $updateUid->execute([
                            ':validity' => $uidValidity, ':uid' => $uid, ':subject' => (string) ($headers['subject'] ?? ''), ':body' => $body,
                            ':sent_at' => $sentAt, ':received_at' => $receivedAt, ':replied' => in_array('\\answered', $flags, true) ? 1 : 0,
                            ':in_reply_to' => (string) ($headers['in-reply-to'] ?? ''), ':references_header' => (string) ($headers['references'] ?? ''), ':id' => $messageIdLocal,
                        ]);
                        // Bcc is intentionally absent from most sent-message headers. Keep the
                        // envelope recipients recorded when the deterministic backend sent it.
                        $this->replaceParts($messageIdLocal, $headers, $structure['attachments'], true);
                        $reuse = true;
                    }
                }
                if (!$reuse) {
                    $insert = $this->storage->db->prepare('INSERT INTO messages(folder_id,uidvalidity,uid,message_id,subject,body,sent_at,received_at,is_read,is_replied,summary,processing_status,processing_error,notified_at,queued_at,rule_action,handling_flag,in_reply_to,references_header)
                        VALUES(:folder,:validity,:uid,:message_id,:subject,:body,:sent_at,:received_at,:is_read,:is_replied,:summary,:status,:processing_error,:notified_at,:queued_at,:action,:flag,:in_reply_to,:references_header)');
                    $insert->execute([
                        ':folder' => $folderId, ':validity' => $uidValidity, ':uid' => $uid, ':message_id' => $messageId,
                        ':subject' => (string) ($headers['subject'] ?? ''), ':body' => $body, ':sent_at' => $sentAt, ':received_at' => $receivedAt,
                        ':is_read' => in_array('\\seen', $flags, true) ? 1 : 0, ':is_replied' => in_array('\\answered', $flags, true) ? 1 : 0,
                        ':summary'=>(string)($restoreState['summary']??''),
                        ':status' => $role === 'inbox' ? $processing : 'baseline',
                        ':processing_error'=>(string)($restoreState['processing_error']??''),':notified_at'=>(string)($restoreState['notified_at']??''),
                        ':queued_at'=>(string)($restoreState['queued_at']??''),':action' => $action, ':flag' => $flag,
                        ':in_reply_to' => (string) ($headers['in-reply-to'] ?? ''), ':references_header' => (string) ($headers['references'] ?? ''),
                    ]);
                    $messageIdLocal = (int) $this->storage->db->lastInsertId();
                    $this->replaceParts($messageIdLocal, $headers, $structure['attachments']);
                    if ($restoreState !== null && $restoreKey !== '') {
                        $clearMarker=$this->storage->db->prepare('DELETE FROM operations WHERE operation_key=:key AND kind="restore_marker"');
                        $clearMarker->execute([':key'=>$restoreKey]);
                    }
                }
            }
            $this->storage->db->commit();
        } catch (\Throwable $exception) {
            if ($this->storage->db->inTransaction()) $this->storage->db->rollBack();
            throw $exception;
        }
        return true;
    }

    /** Injected by sync before a message's text sections are fetched. */
    private ?ImapClient $imap = null;

    private function currentImap(): ImapClient
    {
        if (!$this->imap instanceof ImapClient) throw new RuntimeException('IMAP synchronization state is missing.');
        return $this->imap;
    }

    private function replaceParts(int $messageId, array $headers, array $attachments, bool $preserveBcc = false): void
    {
        $keepBcc = $preserveBcc && trim((string)($headers['bcc'] ?? '')) === '';
        $deleteAddresses = $this->storage->db->prepare('DELETE FROM addresses WHERE message_id=:id' . ($keepBcc ? ' AND kind<>"bcc"' : ''));
        $deleteAddresses->execute([':id' => $messageId]);
        $deleteAttachments = $this->storage->db->prepare('DELETE FROM attachments WHERE message_id=:id');
        $deleteAttachments->execute([':id' => $messageId]);
        foreach ($attachments as $attachment) {
            $add = $this->storage->db->prepare('INSERT INTO attachments(message_id,part,filename,mime_type,size,encoded_size,transfer_encoding,content_id,disposition) VALUES(:id,:part,:filename,:mime,:size,:encoded_size,:encoding,:content_id,:disposition)');
            $add->execute([
                ':id' => $messageId, ':part' => $attachment['part'], ':filename' => $attachment['filename'],
                ':mime' => $attachment['mime_type'], ':size' => $attachment['size'], ':encoded_size' => $attachment['encoded_size'],
                ':encoding' => $attachment['transfer_encoding'], ':content_id' => $attachment['content_id'],
                ':disposition' => $attachment['disposition'],
            ]);
        }
        foreach (['from'=>'from','to'=>'to','cc'=>'cc','bcc'=>'bcc','reply-to'=>'reply_to'] as $header => $kind) {
            foreach ($this->addresses($headers[$header] ?? '', $kind) as $position => $address) {
                $add = $this->storage->db->prepare('INSERT INTO addresses(message_id,kind,position,address,display_name) VALUES(:id,:kind,:position,:address,:name)');
                $add->execute([':id' => $messageId, ':kind' => $kind, ':position' => $position, ':address' => $address['address'], ':name' => $address['display_name']]);
            }
        }
    }

    private function addresses(string $value, string $unused): array { return MimeParser::addresses($value); }

    private function localUidSet(int $folderId, string $uidValidity): array
    {
        $query = $this->storage->db->prepare('SELECT uid FROM messages WHERE folder_id=:folder AND uidvalidity=:validity');
        $query->execute([':folder' => $folderId, ':validity' => $uidValidity]);
        return array_fill_keys(array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    private function updateFlags(int $folderId, string $validity, int $uid, array $flags): void
    {
        $flags = array_map('strtolower', $flags);
        $query = $this->storage->db->prepare('UPDATE messages SET is_read=:read,is_replied=:replied WHERE folder_id=:folder AND uidvalidity=:validity AND uid=:uid');
        $query->execute([':read' => in_array('\\seen', $flags, true) ? 1 : 0, ':replied' => in_array('\\answered', $flags, true) ? 1 : 0, ':folder' => $folderId, ':validity' => $validity, ':uid' => $uid]);
    }

    private function syncState(string $role, string $validity): ?array
    {
        $query = $this->storage->db->prepare('SELECT * FROM sync_state WHERE role=:role AND uidvalidity=:validity');
        $query->execute([':role' => $role, ':validity' => $validity]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function startSnapshot(string $role, string $validity, array $uids): void
    {
        $query = $this->storage->db->prepare('INSERT OR REPLACE INTO sync_state(role,uidvalidity,snapshot_json,cursor,started_at) VALUES(:role,:validity,:snapshot,0,:started)');
        $query->execute([':role' => $role, ':validity' => $validity, ':snapshot' => json_encode($uids, JSON_THROW_ON_ERROR), ':started' => gmdate(DATE_ATOM)]);
    }

    private function advanceCursor(string $role, int $cursor): void
    {
        $query = $this->storage->db->prepare('UPDATE sync_state SET cursor=:cursor WHERE role=:role');
        $query->execute([':cursor' => $cursor, ':role' => $role]);
    }

    private function updateFolderValidity(int $folderId, string $validity): void
    {
        $query = $this->storage->db->prepare('UPDATE folders SET uidvalidity=:validity WHERE id=:id');
        $query->execute([':validity' => $validity, ':id' => $folderId]);
    }

    private function reconcile(int $folderId, string $validity, array $uids): int
    {
        $current = array_fill_keys(array_map('intval', $uids), true);
        $query = $this->storage->db->prepare('SELECT id,uidvalidity,uid FROM messages WHERE folder_id=:folder');
        $query->execute([':folder'=>$folderId]);
        $remove=[];
        foreach($query->fetchAll(PDO::FETCH_ASSOC) as $row){
            if((string)$row['uidvalidity']!==$validity||!isset($current[(int)$row['uid']]))$remove[]=(int)$row['id'];
        }
        $deleted=0;
        foreach(array_chunk($remove,500) as $batch){
            $delete=$this->storage->db->prepare('DELETE FROM messages WHERE id IN ('.implode(',',array_fill(0,count($batch),'?')).')');
            $delete->execute($batch);$deleted+=$delete->rowCount();
        }
        return $deleted;
    }

    private static function isoDate(string $value): string
    {
        if (trim($value) === '') return '';
        try { return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM); }
        catch (\Throwable) { return ''; }
    }
}
