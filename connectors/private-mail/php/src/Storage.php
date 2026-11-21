<?php

declare(strict_types=1);

namespace MantisBat\PrivateMail;

use PDO;
use RuntimeException;

/** Private SQLite state. Mailbox UIDs are scoped to a folder and UIDVALIDITY. */
final class Storage
{
    public PDO $db;

    public function __construct(private readonly string $path)
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create private storage directory.');
        }
        $this->db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000; PRAGMA journal_mode = WAL');
    }

    public function migrate(): void
    {
        $this->db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS folders (
 id INTEGER PRIMARY KEY,
 role TEXT NOT NULL UNIQUE CHECK(role IN ('inbox','sent','ghost_deleted')),
 remote_name TEXT NOT NULL,
 uidvalidity TEXT NOT NULL DEFAULT '',
 sync_complete INTEGER NOT NULL DEFAULT 0,
 last_synced_at TEXT NOT NULL DEFAULT ''
);
CREATE TABLE IF NOT EXISTS messages (
 id INTEGER PRIMARY KEY,
 folder_id INTEGER NOT NULL REFERENCES folders(id) ON DELETE CASCADE,
 uidvalidity TEXT NOT NULL DEFAULT '',
 uid INTEGER NOT NULL,
 message_id TEXT NOT NULL DEFAULT '',
 subject TEXT NOT NULL DEFAULT '',
 body TEXT NOT NULL DEFAULT '',
 sent_at TEXT NOT NULL DEFAULT '',
 received_at TEXT NOT NULL DEFAULT '',
 is_read INTEGER NOT NULL DEFAULT 0,
 is_replied INTEGER NOT NULL DEFAULT 0,
 summary TEXT NOT NULL DEFAULT '',
 handling_flag TEXT NOT NULL DEFAULT '',
 processing_status TEXT NOT NULL DEFAULT 'baseline',
 processing_error TEXT NOT NULL DEFAULT '',
 notified_at TEXT NOT NULL DEFAULT '',
 queued_at TEXT NOT NULL DEFAULT '',
 rule_action TEXT NOT NULL DEFAULT '',
 in_reply_to TEXT NOT NULL DEFAULT '',
 references_header TEXT NOT NULL DEFAULT '',
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(folder_id,uidvalidity,uid)
);
CREATE INDEX IF NOT EXISTS messages_sort ON messages(folder_id,received_at DESC,sent_at DESC);
CREATE INDEX IF NOT EXISTS messages_message_id ON messages(message_id);
CREATE INDEX IF NOT EXISTS messages_processing ON messages(processing_status,folder_id,id);
CREATE TABLE IF NOT EXISTS addresses (
 message_id INTEGER NOT NULL REFERENCES messages(id) ON DELETE CASCADE,
 kind TEXT NOT NULL CHECK(kind IN ('from','to','cc','bcc','reply_to')),
 position INTEGER NOT NULL,
 address TEXT NOT NULL COLLATE NOCASE,
 display_name TEXT NOT NULL DEFAULT '',
 PRIMARY KEY(message_id,kind,position)
);
CREATE INDEX IF NOT EXISTS addresses_lookup ON addresses(address COLLATE NOCASE,kind,message_id);
CREATE TABLE IF NOT EXISTS attachments (
 id INTEGER PRIMARY KEY,
 message_id INTEGER NOT NULL REFERENCES messages(id) ON DELETE CASCADE,
 part TEXT NOT NULL,
 filename TEXT NOT NULL DEFAULT '',
 mime_type TEXT NOT NULL DEFAULT 'application/octet-stream',
 size INTEGER NOT NULL DEFAULT 0,
 encoded_size INTEGER NOT NULL DEFAULT 0,
 transfer_encoding TEXT NOT NULL DEFAULT '',
 content_id TEXT NOT NULL DEFAULT '',
 disposition TEXT NOT NULL DEFAULT '',
 UNIQUE(message_id,part)
);
CREATE TABLE IF NOT EXISTS rules (
 id INTEGER PRIMARY KEY,
 address TEXT NOT NULL COLLATE NOCASE,
 subject TEXT,
 action TEXT NOT NULL CHECK(action IN ('ignore','notify','summarize_notify','draft','auto'))
);
CREATE UNIQUE INDEX IF NOT EXISTS rules_sender ON rules(address) WHERE subject IS NULL;
CREATE UNIQUE INDEX IF NOT EXISTS rules_subject ON rules(address,subject) WHERE subject IS NOT NULL;
CREATE TABLE IF NOT EXISTS diary (
 id INTEGER PRIMARY KEY,
 message_id INTEGER REFERENCES messages(id) ON DELETE SET NULL,
 created_at TEXT NOT NULL,
 entry_kind TEXT NOT NULL DEFAULT 'context',
 entry TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS operations (
 id INTEGER PRIMARY KEY,
 operation_key TEXT NOT NULL UNIQUE,
 kind TEXT NOT NULL,
 status TEXT NOT NULL,
 created_at TEXT NOT NULL,
 result_json TEXT NOT NULL DEFAULT '{}'
);
CREATE TABLE IF NOT EXISTS ghost_api_log (
 id INTEGER PRIMARY KEY,
 created_at TEXT NOT NULL,
 method TEXT NOT NULL,
 path TEXT NOT NULL,
 mode TEXT NOT NULL DEFAULT '',
 http_status INTEGER NOT NULL,
 response TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS sync_state (
 role TEXT PRIMARY KEY,
 uidvalidity TEXT NOT NULL,
 snapshot_json TEXT NOT NULL,
 cursor INTEGER NOT NULL DEFAULT 0,
 started_at TEXT NOT NULL
);
SQL);
        $this->ensureColumns('folders', ['last_synced_at' => "TEXT NOT NULL DEFAULT ''"]);
        $this->ensureColumns('messages', [
            'processing_error' => "TEXT NOT NULL DEFAULT ''", 'notified_at' => "TEXT NOT NULL DEFAULT ''",
            'queued_at' => "TEXT NOT NULL DEFAULT ''", 'handling_flag' => "TEXT NOT NULL DEFAULT ''",
            'rule_action' => "TEXT NOT NULL DEFAULT ''", 'in_reply_to' => "TEXT NOT NULL DEFAULT ''",
            'references_header' => "TEXT NOT NULL DEFAULT ''", 'created_at' => "TEXT NOT NULL DEFAULT ''",
        ]);
        $attachmentColumns = $this->columns('attachments');
        $this->ensureColumns('attachments', ['size' => 'INTEGER NOT NULL DEFAULT 0', 'encoded_size' => 'INTEGER NOT NULL DEFAULT 0', 'transfer_encoding' => "TEXT NOT NULL DEFAULT ''", 'content_id' => "TEXT NOT NULL DEFAULT ''", 'disposition' => "TEXT NOT NULL DEFAULT ''"]);
        if (isset($attachmentColumns['encoded_size']) && !isset($attachmentColumns['size'])) {
            $this->db->exec('UPDATE attachments SET size=encoded_size');
        }
        $this->ensureColumns('diary', ['entry_kind' => "TEXT NOT NULL DEFAULT 'context'"]);
        $this->db->exec('CREATE UNIQUE INDEX IF NOT EXISTS diary_message_kind ON diary(message_id,entry_kind) WHERE message_id IS NOT NULL');
        @chmod($this->path, 0600);
    }

    public function actionFor(string $address, string $subject, string $default = 'summarize_notify'): string
    {
        $query = $this->db->prepare('SELECT action FROM rules
            WHERE address = :address COLLATE NOCASE AND (subject = :subject OR subject IS NULL)
            ORDER BY CASE WHEN subject IS NULL THEN 1 ELSE 0 END LIMIT 1');
        $query->execute([':address' => trim($address), ':subject' => $subject]);
        $value = $query->fetchColumn();
        return $value === false ? $default : (string) $value;
    }

    public function setRule(string $address, ?string $subject, string $action): void
    {
        $address = strtolower(trim($address));
        $subject = $subject === null ? null : trim($subject);
        if (!filter_var($address, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid email address for this rule.');
        if ($subject !== null && mb_strlen($subject) > 500) throw new RuntimeException('Rule subject is too long.');
        if (!in_array($action, ['ignore','notify','summarize_notify','draft','auto'], true)) throw new RuntimeException('Choose a supported handling action.');
        $sql = $subject === null
            ? 'INSERT INTO rules(address,subject,action) VALUES(:address,NULL,:action) ON CONFLICT(address) WHERE subject IS NULL DO UPDATE SET action=excluded.action'
            : 'INSERT INTO rules(address,subject,action) VALUES(:address,:subject,:action) ON CONFLICT(address,subject) WHERE subject IS NOT NULL DO UPDATE SET action=excluded.action';
        $query = $this->db->prepare($sql);
        $params = [':address' => $address, ':action' => $action];
        if ($subject !== null) $params[':subject'] = $subject;
        $query->execute($params);
    }

    public function deleteRule(int $id): void
    {
        $query = $this->db->prepare('DELETE FROM rules WHERE id=:id');
        $query->execute([':id' => $id]);
    }

    public function rules(): array
    {
        return $this->db->query('SELECT id,address,subject,action FROM rules ORDER BY address COLLATE NOCASE, subject IS NULL DESC, subject COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function diaryContext(int $limit = 50): string
    {
        $query = $this->db->prepare('SELECT created_at,entry FROM diary ORDER BY id DESC LIMIT :limit');
        $query->bindValue(':limit', min(50, max(1, $limit)), PDO::PARAM_INT);
        $query->execute();
        $rows = array_reverse($query->fetchAll(PDO::FETCH_ASSOC));
        $parts = [];
        $total = 0;
        foreach ($rows as $row) {
            $entry = trim((string) $row['created_at'] . ' — ' . (string) $row['entry']);
            if (mb_strlen($entry) > 900) $entry = mb_substr($entry, 0, 897) . '…';
            if ($total + mb_strlen($entry) > 10000) break;
            $parts[] = $entry;
            $total += mb_strlen($entry);
        }
        return implode("\n", $parts);
    }

    public function addDiary(?int $messageId, string $entry, string $kind = 'context'): void
    {
        $query = $this->db->prepare('INSERT OR IGNORE INTO diary(message_id,created_at,entry_kind,entry) VALUES(:message_id,:created_at,:kind,:entry)');
        $query->execute([':message_id' => $messageId, ':created_at' => gmdate(DATE_ATOM), ':kind' => $kind, ':entry' => mb_substr(trim($entry), 0, 2000)]);
        $this->db->exec('DELETE FROM diary WHERE id NOT IN (SELECT id FROM diary ORDER BY id DESC LIMIT 50)');
    }

    public function email(int $id): ?array
    {
        $query = $this->db->prepare('SELECT m.*,f.role AS folder,f.remote_name AS folder_name FROM messages m JOIN folders f ON f.id=m.folder_id WHERE m.id=:id');
        $query->execute([':id' => $id]);
        $message = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($message)) return null;
        $addresses = $this->db->prepare('SELECT kind,address,display_name FROM addresses WHERE message_id=:id ORDER BY kind,position');
        $addresses->execute([':id' => $id]);
        $message['addresses'] = $addresses->fetchAll(PDO::FETCH_ASSOC);
        $attachments = $this->db->prepare('SELECT id,part,filename,mime_type,size,encoded_size,transfer_encoding,content_id,disposition FROM attachments WHERE message_id=:id ORDER BY id');
        $attachments->execute([':id' => $id]);
        $message['attachments'] = $attachments->fetchAll(PDO::FETCH_ASSOC);
        return $message;
    }

    public function addressList(int $messageId, string $kind): array
    {
        $query = $this->db->prepare('SELECT address,display_name FROM addresses WHERE message_id=:id AND kind=:kind ORDER BY position');
        $query->execute([':id' => $messageId, ':kind' => $kind]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function roleFolder(string $role): ?array
    {
        $query = $this->db->prepare('SELECT * FROM folders WHERE role=:role');
        $query->execute([':role' => $role]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function setFolder(string $role, string $remoteName): void
    {
        $current = $this->roleFolder($role);
        if (is_array($current) && (string) $current['remote_name'] !== $remoteName) {
            $deleteMessages = $this->db->prepare('DELETE FROM messages WHERE folder_id=:folder');
            $deleteMessages->execute([':folder' => (int) $current['id']]);
            $deleteState = $this->db->prepare('DELETE FROM sync_state WHERE role=:role');
            $deleteState->execute([':role' => $role]);
            $reset = $this->db->prepare('UPDATE folders SET uidvalidity="",sync_complete=0 WHERE id=:id');
            $reset->execute([':id' => (int) $current['id']]);
        }
        $query = $this->db->prepare('INSERT INTO folders(role,remote_name) VALUES(:role,:name)
            ON CONFLICT(role) DO UPDATE SET remote_name=excluded.remote_name');
        $query->execute([':role' => $role, ':name' => $remoteName]);
    }

    private function columns(string $table): array
    {
        $result = [];
        foreach ($this->db->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC) as $row) $result[(string) $row['name']] = true;
        return $result;
    }

    private function ensureColumns(string $table, array $definitions): void
    {
        $columns = $this->columns($table);
        foreach ($definitions as $name => $definition) {
            if (isset($columns[$name])) continue;
            $this->db->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $name . ' ' . $definition);
        }
    }
}
