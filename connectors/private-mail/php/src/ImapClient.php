<?php

declare(strict_types=1);

namespace MantisBat\PrivateMail;

use RuntimeException;

/** Minimal IMAP client with verified TLS and literal-safe responses. */
final class ImapClient
{
    private mixed $stream = null;
    private int $tagNumber = 0;
    private array $capabilities = [];

    public function __construct(private readonly array $config) {}

    public function connect(): void
    {
        $host = trim((string) ($this->config['host'] ?? ''));
        $port = (int) ($this->config['port'] ?? 993);
        if ($host === '' || strpbrk($host, chr(10) . chr(13) . '/' . chr(92)) !== false || $port < 1 || $port > 65535) {
            throw new RuntimeException('IMAP host or port is invalid.');
        }
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false,
            'peer_name' => $host, 'SNI_enabled' => true,
        ]]);
        $scheme = $port === 993 ? 'tls' : 'tcp';
        $this->stream = @stream_socket_client($scheme . '://' . $host . ':' . $port, $errorCode, $errorText, 12, STREAM_CLIENT_CONNECT, $context);
        if (!is_resource($this->stream)) throw new RuntimeException('Could not connect to the IMAP server. Check host, port, and network access.');
        stream_set_timeout($this->stream, 25);
        $greeting = $this->readLine();
        if (!str_starts_with(strtoupper($greeting), '* OK')) throw new RuntimeException('IMAP server did not provide a ready greeting.');
        $this->capabilities = $this->readCapabilities();
        if ($port !== 993) {
            if (!in_array('STARTTLS', $this->capabilities, true)) throw new RuntimeException('The IMAP server does not offer STARTTLS on this port.');
            $this->command('STARTTLS');
            $method = defined('STREAM_CRYPTO_METHOD_TLS_CLIENT') ? STREAM_CRYPTO_METHOD_TLS_CLIENT : STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            if (@stream_socket_enable_crypto($this->stream, true, $method) !== true) throw new RuntimeException('Could not establish verified IMAP TLS.');
            $this->capabilities = $this->readCapabilities();
        }
        $username = (string) ($this->config['username'] ?? '');
        $password = (string) ($this->config['password'] ?? '');
        if ($username === '' || $password === '' || preg_match('/[\r\n\x00]/', $username . $password)) throw new RuntimeException('IMAP login details are invalid.');
        $this->command('LOGIN ' . $this->quote($username) . ' ' . $this->quote($password));
        $this->capabilities = $this->readCapabilities();
    }

    public function close(): void
    {
        if (!is_resource($this->stream)) return;
        try { $this->command('LOGOUT'); } catch (\Throwable) {}
        fclose($this->stream);
        $this->stream = null;
    }

    public function supports(string $capability): bool { return in_array(strtoupper($capability), $this->capabilities, true); }

    public function folders(): array
    {
        $response = $this->command('LIST "" "*"');
        $folders = [];
        foreach (preg_split('/\r\n|\n/', $response) ?: [] as $line) {
            if (!preg_match('/^\* LIST\s+\([^)]*\)\s+(?:"(?:\\\\.|[^"\\\\])*"|NIL)\s+(.+)$/i', $line, $match)) continue;
            $name = trim($match[1]);
            if (str_starts_with($name, '"') && str_ends_with($name, '"')) $name = stripcslashes(substr($name, 1, -1));
            if ($name !== '' && !in_array($name, $folders, true)) $folders[] = $name;
        }
        return $folders;
    }

    public function ensureFolder(string $name): void
    {
        if (in_array($name, $this->folders(), true)) return;
        try { $this->command('CREATE ' . $this->quote($name)); }
        catch (RuntimeException $exception) {
            if (!in_array($name, $this->folders(), true)) throw new RuntimeException('Could not create the Ghost Deleted IMAP folder.');
        }
    }

    public function select(string $folder): string
    {
        $response = $this->command('SELECT ' . $this->quote($folder));
        if (!preg_match('/\[UIDVALIDITY\s+(\d+)\]/i', $response, $match)) throw new RuntimeException('IMAP folder did not report UIDVALIDITY.');
        return $match[1];
    }

    public function searchAll(): array
    {
        $response = $this->command('UID SEARCH ALL');
        foreach (preg_split('/\r\n|\n/', $response) ?: [] as $line) {
            if (preg_match('/^\* SEARCH(?:\s+(.*))?$/i', trim($line), $match)) {
                $uids = trim((string) ($match[1] ?? ''));
                if ($uids === '') return [];
                return array_values(array_unique(array_filter(array_map('intval', preg_split('/\s+/', $uids) ?: []), static fn(int $uid): bool => $uid > 0)));
            }
        }
        throw new RuntimeException('IMAP server did not return a mailbox search result.');
    }

    public function searchMessageId(string $messageId): array
    {
        $messageId = trim($messageId, " <>\t\r\n");
        if ($messageId === '') return [];
        $response = $this->command('UID SEARCH HEADER Message-ID ' . $this->quote('<' . $messageId . '>'));
        foreach (preg_split('/\r\n|\n/', $response) ?: [] as $line) {
            if (preg_match('/^\* SEARCH(?:\s+(.*))?$/i', trim($line), $match)) {
                $uids = trim((string) ($match[1] ?? ''));
                if ($uids === '') return [];
                return array_values(array_unique(array_filter(array_map('intval', preg_split('/\s+/', $uids) ?: []), static fn(int $uid): bool => $uid > 0)));
            }
        }
        return [];
    }

    public function fetchFlags(array $uids): array
    {
        $uids = array_values(array_filter(array_map('intval', $uids), static fn(int $uid): bool => $uid > 0));
        if ($uids === []) return [];
        $response = $this->command('UID FETCH ' . implode(',', $uids) . ' (UID FLAGS)');
        $result = [];
        foreach (preg_split('/\r\n|\n/', $response) ?: [] as $line) {
            if (preg_match('/\bUID\s+(\d+)\b/i', $line, $uidMatch) && preg_match('/\bFLAGS\s+\(([^)]*)\)/i', $line, $flagMatch)) {
                $result[(int) $uidMatch[1]] = preg_split('/\s+/', trim($flagMatch[1])) ?: [];
            }
        }
        return $result;
    }

    public function fetchMessage(int $uid): array
    {
        $response = $this->command('UID FETCH ' . $uid . ' (UID FLAGS INTERNALDATE RFC822.SIZE BODYSTRUCTURE BODY.PEEK[HEADER.FIELDS (FROM TO CC BCC REPLY-TO SUBJECT DATE MESSAGE-ID IN-REPLY-TO REFERENCES)])');
        if (!preg_match('/\bUID\s+' . $uid . '\b/i', $response) || !preg_match('/\bBODYSTRUCTURE\s+\(/i', $response)) {
            throw new RuntimeException('IMAP server did not return the requested message and body structure.');
        }
        $header = self::literal($response);
        $structureText = '';
        if (preg_match('/\bBODYSTRUCTURE\s+/i', $response, $match, PREG_OFFSET_CAPTURE)) {
            $start = $match[0][1] + strlen($match[0][0]);
            $structureText = self::balancedList(substr($response, $start));
        }
        $flags = [];
        if (preg_match('/\bFLAGS\s+\(([^)]*)\)/i', $response, $match)) $flags = preg_split('/\s+/', trim($match[1])) ?: [];
        $internalDate = '';
        if (preg_match('/\bINTERNALDATE\s+"([^"]+)"/i', $response, $match)) $internalDate = $match[1];
        $size = 0;
        if (preg_match('/\bRFC822\.SIZE\s+(\d+)/i', $response, $match)) $size = (int) $match[1];
        return [
            'headers' => MimeParser::headers($header),
            'structure' => MimeParser::parseStructure($structureText),
            'flags' => $flags,
            'internal_date' => $internalDate,
            'size' => $size,
        ];
    }

    public function fetchPart(int $uid, string $part, ?int $maxBytes = null): string
    {
        if (!preg_match('/^(?:TEXT|[1-9]\d*(?:\.[1-9]\d*)*)$/', $part)) throw new RuntimeException('Invalid IMAP body part.');
        $section = strtoupper($part) === 'TEXT' ? 'TEXT' : $part;
        $partial = $maxBytes === null ? '' : '<0.' . ($maxBytes + 1) . '>';
        $response = $this->command('UID FETCH ' . $uid . ' (BODY.PEEK[' . $section . ']' . $partial . ')');
        $body = self::literal($response);
        if ($maxBytes !== null && strlen($body) > $maxBytes) throw new RuntimeException('The IMAP part exceeded its declared size bound.');
        return $body;
    }

    public function setFlag(int $uid, string $flag, bool $enabled = true): void
    {
        if (!in_array($flag, ['\\Seen','\\Answered'], true)) throw new RuntimeException('Unsupported message status flag.');
        $operation = $enabled ? '+FLAGS.SILENT' : '-FLAGS.SILENT';
        $this->command('UID STORE ' . $uid . ' ' . $operation . ' (' . $flag . ')');
    }

    public function move(int $uid, string $destination): void
    {
        try {
            $this->command('UID MOVE ' . $uid . ' ' . $this->quote($destination));
            return;
        } catch (RuntimeException) {
            if (!$this->supports('UIDPLUS')) throw new RuntimeException('This IMAP server needs MOVE or UIDPLUS support to move messages safely.');
        }
        $this->command('UID COPY ' . $uid . ' ' . $this->quote($destination));
        $this->command('UID STORE ' . $uid . ' +FLAGS.SILENT (\\Deleted)');
        $this->command('UID EXPUNGE ' . $uid);
    }

    public function permanentlyDelete(int $uid): void
    {
        if (!$this->supports('UIDPLUS')) throw new RuntimeException('This IMAP server does not support safe UID EXPUNGE.');
        $this->command('UID STORE ' . $uid . ' +FLAGS.SILENT (\\Deleted)');
        $this->command('UID EXPUNGE ' . $uid);
    }

    public function append(string $folder, string $message, array $flags = ['\\Seen']): void
    {
        $safeFlags = array_values(array_intersect($flags, ['\\Seen','\\Answered']));
        $tag = $this->nextTag();
        $flagText = $safeFlags === [] ? '' : ' (' . implode(' ', $safeFlags) . ')';
        $this->writeAll($tag . ' APPEND ' . $this->quote($folder) . $flagText . ' {' . strlen($message) . "}\r\n");
        $continuation = $this->readLine();
        if (!str_starts_with($continuation, '+')) throw new RuntimeException('IMAP server refused to append the sent message.');
        $this->writeAll($message . "\r\n");
        $response = $this->readUntilTag($tag);
        if (!preg_match('/^' . preg_quote($tag, '/') . '\s+OK\b/im', $response)) throw new RuntimeException('IMAP server could not save the sent copy.');
    }

    private function readCapabilities(): array
    {
        try {
            $response = $this->command('CAPABILITY');
            if (preg_match('/\* CAPABILITY\s+([^\r\n]+)/i', $response, $match)) return array_map('strtoupper', preg_split('/\s+/', trim($match[1])) ?: []);
        } catch (RuntimeException) {}
        return [];
    }

    private function command(string $command): string
    {
        if (!is_resource($this->stream)) throw new RuntimeException('IMAP connection is not open.');
        if (preg_match('/[\r\n]/', $command)) throw new RuntimeException('Invalid IMAP command.');
        $tag = $this->nextTag();
        $this->writeAll($tag . ' ' . $command . "\r\n");
        $response = $this->readUntilTag($tag);
        if (!preg_match('/^' . preg_quote($tag, '/') . '\s+OK\b/im', $response)) {
            $status = 'rejected';
            if (preg_match('/^' . preg_quote($tag, '/') . '\s+(NO|BAD)\b[^\r\n]*/im', $response, $match)) $status = trim($match[0]);
            throw new RuntimeException('IMAP command was ' . $status . '.');
        }
        return $response;
    }

    private function readUntilTag(string $tag): string
    {
        $response = '';
        while (true) {
            $line = $this->readLine();
            $response .= $line;
            if (preg_match('/\{(\d+)\+?\}\r?\n$/', $line, $match)) $response .= $this->readBytes((int) $match[1]);
            if (preg_match('/^' . preg_quote($tag, '/') . '\s+/i', $line)) return $response;
        }
    }

    private function readLine(): string
    {
        $line = fgets($this->stream);
        $metadata = stream_get_meta_data($this->stream);
        if ($line === false || ($metadata['timed_out'] ?? false)) throw new RuntimeException('IMAP server timed out while responding.');
        return $line;
    }

    private function readBytes(int $count): string
    {
        $bytes = '';
        while (strlen($bytes) < $count) {
            $chunk = fread($this->stream, min(8192, $count - strlen($bytes)));
            if ($chunk === false || $chunk === '') throw new RuntimeException('IMAP server returned an incomplete message part.');
            $bytes .= $chunk;
        }
        return $bytes;
    }

    private function writeAll(string $value): void
    {
        $offset = 0;
        while ($offset < strlen($value)) {
            $written = fwrite($this->stream, substr($value, $offset));
            if ($written === false || $written === 0) throw new RuntimeException('Could not write to the IMAP server.');
            $offset += $written;
        }
    }

    private function nextTag(): string { return 'P' . str_pad((string) ++$this->tagNumber, 4, '0', STR_PAD_LEFT); }

    private function quote(string $value): string
    {
        if (preg_match('/[\r\n\x00]/', $value)) throw new RuntimeException('Invalid IMAP mailbox name.');
        return '"' . addcslashes($value, "\\\"") . '"';
    }

    private static function literal(string $response): string
    {
        if (!preg_match('/\{(\d+)\+?\}\r?\n/', $response, $match, PREG_OFFSET_CAPTURE)) return '';
        $start = $match[0][1] + strlen($match[0][0]);
        return substr($response, $start, (int) $match[1][0]);
    }

    private static function balancedList(string $input): string
    {
        $start = strpos($input, '(');
        if ($start === false) return '';
        $depth = 0; $quote = false; $escape = false;
        for ($i = $start, $length = strlen($input); $i < $length; $i++) {
            $char = $input[$i];
            if ($escape) { $escape = false; continue; }
            if ($quote && $char === '\\') { $escape = true; continue; }
            if ($char === '"') { $quote = !$quote; continue; }
            if ($quote) continue;
            if ($char === '(') $depth++;
            if ($char === ')' && --$depth === 0) return substr($input, $start, $i - $start + 1);
        }
        return '';
    }
}
