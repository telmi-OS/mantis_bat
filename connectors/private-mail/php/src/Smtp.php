<?php

declare(strict_types=1);

namespace MantisBat\PrivateMail;

use RuntimeException;

final class Smtp
{
    public static function probe(array $config): void
    {
        self::validate($config);
        $smtp = (array) ($config['smtp'] ?? []);
        $port = (int) $smtp['port'];
        $scheme = $port === 465 ? 'smtps' : 'smtp';
        $curl = curl_init($scheme . '://' . $smtp['host'] . ':' . $port);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>8, CURLOPT_TIMEOUT=>12,
            CURLOPT_USE_SSL=>CURLUSESSL_ALL, CURLOPT_USERNAME=>(string) $smtp['username'],
            CURLOPT_PASSWORD=>(string) $smtp['password'], CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_PROTOCOLS=>CURLPROTO_SMTPS | CURLPROTO_SMTP,
            CURLOPT_CONNECT_ONLY=>true,
        ]);
        $connected = curl_exec($curl);
        $error = curl_error($curl);
        curl_close($curl);
        if ($connected === false) throw new RuntimeException('Could not connect to the SMTP server with the supplied settings: ' . $error);
    }

    /** Build, submit, and return the RFC 5322 message for the IMAP Sent copy. */
    public static function send(array $config, array $input): array
    {
        self::validate($config);
        $groups = [];
        foreach (['to','cc','bcc'] as $field) $groups[$field] = self::recipients($input[$field] ?? []);
        $recipients = array_values(array_unique(array_merge($groups['to'], $groups['cc'], $groups['bcc'])));
        if ($groups['to'] === []) throw new \InvalidArgumentException('At least one To address is required.');
        $from = (string) $config['owner']['email'];
        foreach (array_merge([$from], $recipients) as $address) {
            if (!filter_var($address, FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('An email address is invalid.');
        }
        $subject = trim((string) ($input['subject'] ?? ''));
        $body = (string) ($input['body'] ?? '');
        if ($subject === '' || mb_strlen($subject) > 998) throw new \InvalidArgumentException('Enter a subject of at most 998 characters.');
        if (preg_match('/[\r\n]/', $subject)) throw new \InvalidArgumentException('The email subject cannot contain line breaks.');
        if (preg_match('/\x00/', $body)) throw new \InvalidArgumentException('The email body contains an invalid null character.');
        $messageId = bin2hex(random_bytes(16)) . '@' . substr(strrchr($from, '@') ?: '@private-mail.local', 1);
        $encode = static fn(string $value): string => '=?UTF-8?B?' . base64_encode($value) . '?=';
        $headers = [
            'From: ' . $encode((string) $config['owner']['name']) . ' <' . $from . '>',
            'To: ' . implode(', ', $groups['to']),
            'Subject: ' . $encode($subject),
            'Date: ' . date(DATE_RFC2822),
            'Message-ID: <' . $messageId . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        if ($groups['cc'] !== []) $headers[] = 'Cc: ' . implode(', ', $groups['cc']);
        $inReplyTo = trim((string) ($input['in_reply_to'] ?? ''));
        $references = trim((string) ($input['references'] ?? ''));
        if ($inReplyTo !== '' && !preg_match('/[\r\n]/', $inReplyTo)) $headers[] = 'In-Reply-To: ' . $inReplyTo;
        if ($references !== '' && !preg_match('/[\r\n]/', $references)) $headers[] = 'References: ' . $references;
        $signature = trim((string) ($config['signature'] ?? ''));
        if ($signature !== '') $body .= ($body === '' ? '' : "\r\n\r\n") . $signature;
        $raw = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body));
        $stream = fopen('php://temp', 'w+b');
        if (!is_resource($stream)) throw new RuntimeException('Could not prepare the outgoing email.');
        fwrite($stream, $raw);
        rewind($stream);
        $smtp = (array) $config['smtp'];
        $port = (int) $smtp['port'];
        $scheme = $port === 465 ? 'smtps' : 'smtp';
        $curl = curl_init($scheme . '://' . $smtp['host'] . ':' . $port);
        try {
            curl_setopt_array($curl, [
                CURLOPT_USE_SSL=>CURLUSESSL_ALL, CURLOPT_USERNAME=>(string) $smtp['username'],
                CURLOPT_PASSWORD=>(string) $smtp['password'], CURLOPT_MAIL_FROM=>'<'.$from.'>',
                CURLOPT_MAIL_RCPT=>array_map(static fn(string $address): string => '<'.$address.'>', $recipients),
                CURLOPT_UPLOAD=>true, CURLOPT_INFILE=>$stream, CURLOPT_INFILESIZE=>strlen($raw),
                CURLOPT_CONNECTTIMEOUT=>8, CURLOPT_TIMEOUT=>30, CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_PROTOCOLS=>CURLPROTO_SMTPS | CURLPROTO_SMTP,
            ]);
            if (curl_exec($curl) === false) {
                throw new RuntimeException('SMTP delivery could not be confirmed. Check the mailbox before retrying.');
            }
        } finally {
            curl_close($curl);
            fclose($stream);
        }
        return ['message_id'=>$messageId,'raw'=>$raw,'to'=>$groups['to'],'cc'=>$groups['cc'],'bcc'=>$groups['bcc'],'subject'=>$subject,'body'=>$body,'sent_at'=>gmdate(DATE_ATOM)];
    }

    private static function validate(array $config): void
    {
        $smtp = (array) ($config['smtp'] ?? []);
        $owner = (array) ($config['owner'] ?? []);
        $host = trim((string) ($smtp['host'] ?? ''));
        $port = (int) ($smtp['port'] ?? 0);
        if ($host === '' || strpbrk($host, chr(10) . chr(13) . '/' . chr(92)) !== false || $port < 1 || $port > 65535
            || (string) ($smtp['username'] ?? '') === '' || (string) ($smtp['password'] ?? '') === '') {
            throw new RuntimeException('SMTP configuration is incomplete.');
        }
        if (!filter_var((string) ($owner['email'] ?? ''), FILTER_VALIDATE_EMAIL)) throw new RuntimeException('The configured owner email address is invalid.');
    }

    private static function recipients(mixed $value): array
    {
        $items = is_array($value) ? $value : (is_string($value) ? [$value] : []);
        $result = [];
        foreach ($items as $item) {
            if (!is_string($item)) continue;
            foreach (MimeParser::addresses($item) as $parsed) $result[] = (string) $parsed['address'];
            if (filter_var(trim($item), FILTER_VALIDATE_EMAIL)) $result[] = strtolower(trim($item));
        }
        return array_values(array_unique($result));
    }
}
