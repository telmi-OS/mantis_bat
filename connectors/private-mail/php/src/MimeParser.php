<?php

declare(strict_types=1);

namespace MantisBat\PrivateMail;

/** Small RFC 5322 and IMAP BODYSTRUCTURE reader; it never downloads file parts. */
final class MimeParser
{
    public static function headers(string $raw): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $raw = preg_replace("/\n[ \t]+/", ' ', $raw) ?? $raw;
        $headers = [];
        foreach (explode("\n", $raw) as $line) {
            if ($line === '' || !str_contains($line, ':')) continue;
            [$name, $value] = explode(':', $line, 2);
            $name = strtolower(trim($name));
            if ($name === '') continue;
            $headers[$name][] = self::decodeHeader(trim($value));
        }
        $out = [];
        foreach ($headers as $name => $values) $out[$name] = implode(', ', $values);
        return $out;
    }

    public static function decodeHeader(string $value): string
    {
        $decoded = function_exists('iconv_mime_decode')
            ? @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8')
            : false;
        if (is_string($decoded)) return $decoded;
        $decoded = function_exists('mb_decode_mimeheader') ? @mb_decode_mimeheader($value) : false;
        return is_string($decoded) ? $decoded : $value;
    }

    public static function messageFingerprint(array $headers, string $body): string
    {
        $from = array_values(array_unique(array_map(static fn(array $item): string => strtolower((string) $item['address']), self::addresses((string) ($headers['from'] ?? '')))));
        sort($from, SORT_STRING);
        $date = trim((string) ($headers['date'] ?? ''));
        if ($date !== '') {
            try { $date = (new \DateTimeImmutable($date))->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM); }
            catch (\Throwable) {}
        }
        return hash('sha256', implode(',', $from) . "\0" . trim((string) ($headers['subject'] ?? '')) . "\0" . $date . "\0" . hash('sha256', $body));
    }

    public static function addresses(string $value): array
    {
        $chunks = [];
        $start = 0;
        $quote = false;
        $angle = 0;
        $escape = false;
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            if ($escape) { $escape = false; continue; }
            if ($char === '\\' && $quote) { $escape = true; continue; }
            if ($char === '"') { $quote = !$quote; continue; }
            if (!$quote && $char === '<') { $angle++; continue; }
            if (!$quote && $char === '>') { $angle = max(0, $angle - 1); continue; }
            if (!$quote && $angle === 0 && $char === ',') {
                $chunks[] = substr($value, $start, $i - $start);
                $start = $i + 1;
            }
        }
        if ($start < $length) $chunks[] = substr($value, $start);
        $result = [];
        foreach ($chunks as $chunk) {
            $chunk = trim(preg_replace('/\([^)]*\)/', '', $chunk) ?? $chunk);
            if ($chunk === '') continue;
            if (preg_match('/^(.*?)<\s*([^<>]+)\s*>\s*$/u', $chunk, $match)) {
                $name = trim($match[1], " \t\"'");
                $address = trim($match[2]);
            } else {
                $name = '';
                $address = trim($chunk, " \t<>");
            }
            $address = trim($address, " \t\"'");
            if (!filter_var($address, FILTER_VALIDATE_EMAIL)) continue;
            $result[] = ['address' => strtolower($address), 'display_name' => self::decodeHeader($name)];
        }
        return $result;
    }

    /** Tokenize an IMAP BODYSTRUCTURE list into nested PHP arrays and scalar values. */
    public static function parseStructure(string $input): array
    {
        $tokens = [];
        preg_match_all('/"(?:\\\\.|[^"\\\\])*"|\(|\)|[^\s()]+/', $input, $matches);
        foreach ($matches[0] as $token) {
            if ($token === '(' || $token === ')') { $tokens[] = $token; continue; }
            if (str_starts_with($token, '"') && str_ends_with($token, '"')) {
                $tokens[] = stripcslashes(substr($token, 1, -1));
            } else {
                $tokens[] = strtoupper($token) === 'NIL' ? null : $token;
            }
        }
        $index = 0;
        $read = static function () use (&$read, &$index, $tokens): mixed {
            if (!array_key_exists($index, $tokens)) return null;
            $token = $tokens[$index++];
            if ($token !== '(') return $token;
            $list = [];
            while (array_key_exists($index, $tokens) && $tokens[$index] !== ')') $list[] = $read();
            if (($tokens[$index] ?? null) === ')') $index++;
            return $list;
        };
        $value = $read();
        return is_array($value) ? $value : [];
    }

    /** @return array{parts:array,attachments:array} */
    public static function bodyParts(array $structure): array
    {
        $parts = [];
        $attachments = [];
        $walk = static function (array $node, string $section) use (&$walk, &$parts, &$attachments): void {
            if ($node === []) return;
            $isMultipart = is_array($node[0] ?? null);
            if ($isMultipart) {
                $partNumber = 1;
                foreach ($node as $child) {
                    if (!is_array($child)) break;
                    $walk($child, $section === '' ? (string) $partNumber : $section . '.' . $partNumber);
                    $partNumber++;
                }
                return;
            }
            $type = strtolower((string) ($node[0] ?? 'application'));
            $subtype = strtolower((string) ($node[1] ?? 'octet-stream'));
            $params = is_array($node[2] ?? null) ? self::parameterMap($node[2]) : [];
            $encoding = strtolower((string) ($node[5] ?? '7bit'));
            $size = max(0, (int) ($node[6] ?? 0));
            $contentId = trim((string) ($node[3] ?? ''), '<>');
            $dispositionIndex = $type === 'text' ? 9 : 8;
            $disposition = is_array($node[$dispositionIndex] ?? null) ? strtoupper((string) ($node[$dispositionIndex][0] ?? '')) : '';
            $dispositionParams = is_array($node[$dispositionIndex][1] ?? null) ? self::parameterMap($node[$dispositionIndex][1]) : [];
            $filename = (string) ($dispositionParams['filename'] ?? $params['name'] ?? '');
            $mime = $type . '/' . $subtype;
            $part = $section === '' ? 'TEXT' : $section;
            $isReadableText = $type === 'text' && in_array($subtype, ['plain','html'], true);
            if ($disposition !== '' || $filename !== '' || $type === 'message' || !$isReadableText) {
                $attachments[] = [
                    'part' => $part, 'filename' => $filename !== '' ? self::decodeHeader($filename) : 'attachment',
                    'mime_type' => $mime, 'size' => self::decodedSizeUpperBound($size, $encoding),
                    'encoded_size' => $size, 'transfer_encoding' => $encoding, 'content_id' => $contentId,
                    'disposition' => strtolower($disposition !== '' ? $disposition : 'attachment'),
                ];
                return;
            }
            if ($isReadableText) {
                $charset = (string) ($params['charset'] ?? 'UTF-8');
                $parts[] = ['part' => $part, 'mime_type' => $mime . '; charset=' . $charset, 'encoding' => $encoding, 'subtype' => $subtype];
            }
        };
        $walk($structure, '');
        return ['parts' => $parts, 'attachments' => $attachments];
    }

    public static function decodeBody(string $body, string $encoding, string $mimeType): string
    {
        $encoding = strtolower($encoding);
        if ($encoding === 'base64') $body = base64_decode(preg_replace('/\s+/', '', $body) ?? $body, true) ?: '';
        elseif ($encoding === 'quoted-printable') $body = quoted_printable_decode($body);
        $charset = 'UTF-8';
        if (preg_match('/charset\s*=\s*["\']?([^;"\'\s]+)/i', $mimeType, $match)) $charset = trim($match[1]);
        if (strtoupper($charset) !== 'UTF-8' && function_exists('iconv')) {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $body);
            if (is_string($converted)) $body = $converted;
        }
        if (str_starts_with(strtolower($mimeType), 'text/html')) {
            $body = html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/div|\/li|\/tr)\b[^>]*>/i', "\n", $body) ?? $body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $body = preg_replace("/\r\n?|[\x00-\x08\x0B\x0C\x0E-\x1F]/", "\n", $body) ?? $body;
        $body = preg_replace("/\n{3,}/", "\n\n", $body) ?? $body;
        return trim($body);
    }

    /** Decode an attachment transfer encoding without applying text or charset processing. */
    public static function decodeAttachment(string $body, string $encoding): string
    {
        return match (strtolower(trim($encoding))) {
            'base64' => self::decodeBase64($body),
            'quoted-printable' => quoted_printable_decode($body),
            '7bit', '8bit', 'binary', '' => $body,
            default => throw new \RuntimeException('This attachment uses an unsupported transfer encoding.'),
        };
    }

    /**
     * BODYSTRUCTURE reports encoded octets. Use a conservative upper bound for
     * base64 data while accounting for MIME's 76-character line wrapping, so
     * oversized files can be rejected before any attachment bytes are fetched.
     */
    private static function decodedSizeUpperBound(int $encodedSize, string $encoding): int
    {
        if (strtolower($encoding) !== 'base64' || $encodedSize < 1) return $encodedSize;
        $low = 0;
        $high = $encodedSize;
        while ($low < $high) {
            $middle = intdiv($low + $high + 1, 2);
            $lineBreaks = $middle > 0 ? intdiv($middle - 1, 76) : 0;
            if ($middle + 2 * $lineBreaks <= $encodedSize) $low = $middle;
            else $high = $middle - 1;
        }
        return intdiv($low, 4) * 3 + intdiv(($low % 4) * 3, 4);
    }

    private static function decodeBase64(string $body): string
    {
        $payload = preg_replace('/\s+/', '', $body) ?? $body;
        $decoded = base64_decode($payload, true);
        if (!is_string($decoded)) throw new \RuntimeException('The attachment contains invalid base64 data.');
        return $decoded;
    }

    private static function parameterMap(array $list): array
    {
        $result = [];
        for ($i = 0; $i + 1 < count($list); $i += 2) {
            if (is_string($list[$i])) $result[strtolower($list[$i])] = is_string($list[$i + 1]) ? $list[$i + 1] : '';
        }
        return $result;
    }
}
