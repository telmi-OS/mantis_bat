<?php

declare(strict_types=1);

namespace MantisBat;

use RuntimeException;

final class GhostClient
{
    public function __construct(
        private readonly RuntimeConfig $config,
        private readonly ?Logger $logger = null
    ) {
    }

    public function readSettings(): array
    {
        return $this->request('GET', (string) $this->config->get('ghost.paths.settings', '/settings'));
    }

    public function probeSettings(): array
    {
        return $this->rawRequest('GET', (string) $this->config->get('ghost.paths.settings', '/settings'));
    }

    public function processDocument(string $title, string $guidance, string $normalizedText): string
    {
        $payload = [
            'message' => $this->buildPrompt($title, $guidance, $normalizedText),
            'mode' => 'realtime',
            'use_history' => false,
            'history' => false,
            'meta' => [
                'source' => 'mantis_bat_rag_prep',
                'channel' => 'web',
            ],
            'options' => [
                'mode' => 'realtime',
                'use_history' => false,
            ],
        ];

        $response = $this->request('POST', (string) $this->config->get('ghost.paths.chat', '/chat'), $payload);
        $reply = $this->extractReply($response);
        if ($reply === '') {
            throw new RuntimeException('Ghost returned an empty semantic chunking result.');
        }

        return $this->normalizeGhostOutput($reply);
    }

    private function buildPrompt(string $title, string $guidance, string $normalizedText): string
    {
        $guidance = trim($guidance);
        $preface = $guidance !== ''
            ? "Additional user guidance:\n" . $guidance . "\n\n"
            : '';

        return <<<PROMPT
Please transform the following normalized document into retrieval-optimized memory chunks for telmi OS memory upload.

Important output format:
- Output plain text only.
- Output chunks separated by exactly one empty line.
- Do not use JSON.
- Do not use markdown code fences.
- Do not add numbering, labels, explanations, or commentary before or after the chunks.
- The output must be ready to save directly as one .txt file.
- Do not place empty lines inside a chunk. Empty lines are only separators between chunks.

Chunking rules:
- Chunk by semantic meaning, not by page boundaries.
- Each chunk must stand on its own and still make sense if retrieved alone later.
- Preserve facts exactly, including names, dates, numbers, URLs, product names, and claims.
- Keep the original language of the source text.
- Remove obvious extraction noise such as repeated headers, footers, page numbers, broken line-wraps, and OCR junk.
- Repair whitespace and line-break hyphenation where needed.
- Keep headings together with their relevant content.
- Keep short lists together if they belong to one coherent idea.
- If a section is long, split it into multiple chunks at natural semantic boundaries.
- When splitting long sections, make sure each chunk still contains enough local topic context to be understandable on its own.
- Avoid chunks that begin with unclear references like “it”, “this”, or “they” unless the referenced subject is included in the same chunk.
- Do not summarize aggressively. Preserve enough original wording so semantic retrieval still works well.
- Prefer chunks of roughly 500 to 1000 characters.
- Avoid going beyond about 1150 characters unless absolutely necessary.
- Avoid very short chunks unless they are complete and useful on their own.
- If tables appear, convert them into compact factual prose or compact list-style chunks that remain retrievable.
- If duplicate passages appear due to extraction artifacts, keep the clean version once unless repetition is required for meaning.

Document title:
{$title}

{$preface}Here is the normalized document content:

{$normalizedText}
PROMPT;
    }

    private function extractReply(array $response): string
    {
        foreach ([
            ['reply'],
            ['data', 'reply'],
            ['data', 'message'],
            ['data', 'text'],
        ] as $path) {
            $value = $this->readPath($response, $path);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    private function normalizeGhostOutput(string $reply): string
    {
        $reply = trim($reply);
        $reply = preg_replace('/^```(?:text|txt)?\s*/i', '', $reply) ?? $reply;
        $reply = preg_replace('/\s*```$/', '', $reply) ?? $reply;
        $reply = str_replace(["\r\n", "\r"], "\n", $reply);
        $reply = preg_replace("/\n{3,}/", "\n\n", $reply) ?? $reply;
        return trim($reply);
    }

    private function request(string $method, string $path, array $payload = [], array $query = []): array
    {
        $result = $this->rawRequest($method, $path, $payload, $query);
        $body = $result['body'];
        $status = $result['status'];
        $url = $result['url'];
        $contentType = $result['content_type'];

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            $preview = $this->previewBody($body);
            $this->logger?->warning('Ghost API returned non-JSON response.', [
                'path' => $path,
                'url' => $url,
                'status' => $status,
                'content_type' => $contentType,
                'body_preview' => $preview,
            ]);
            throw new RuntimeException(sprintf(
                'Ghost API returned non-JSON response. URL: %s Status: %d Content-Type: %s Preview: %s',
                $url,
                $status,
                $contentType !== '' ? $contentType : 'unknown',
                $preview
            ));
        }

        if ($status >= 400 || (isset($decoded['ok']) && $decoded['ok'] === false)) {
            $this->logger?->warning('Ghost API returned an error response.', ['path' => $path, 'url' => $url, 'status' => $status, 'body' => $decoded]);
            $errorMessage = (string) ($decoded['error'] ?? 'Ghost API request failed.');
            throw new RuntimeException(sprintf('Ghost API error. URL: %s Status: %d Error: %s', $url, $status, $errorMessage));
        }

        return $decoded;
    }

    private function rawRequest(string $method, string $path, array $payload = [], array $query = []): array
    {
        $base = rtrim((string) $this->config->require('ghost.api_base'), '/');
        $url = $base . '/' . ltrim($path, '/');
        if ($query !== []) {
            $query = array_filter($query, static fn($value) => $value !== '');
            $url .= '?' . http_build_query($query);
        }

        $headers = [
            'Authorization: Bearer ' . $this->config->require('ghost.api_token'),
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADER => true,
        ]);

        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
        }

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('Ghost API request failed: ' . $error);
        }

        $rawHeaders = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        return [
            'url' => $url,
            'status' => $status,
            'headers' => $rawHeaders,
            'body' => $body,
            'content_type' => $this->extractContentType($rawHeaders),
        ];
    }

    private function extractContentType(string $rawHeaders): string
    {
        foreach (preg_split("/\r\n|\n|\r/", $rawHeaders) ?: [] as $line) {
            if (stripos($line, 'Content-Type:') === 0) {
                return trim(substr($line, strlen('Content-Type:')));
            }
        }

        return '';
    }

    private function previewBody(string $body): string
    {
        $body = trim(preg_replace('/\s+/', ' ', $body) ?? $body);
        if ($body === '') {
            return '[empty body]';
        }

        return mb_substr($body, 0, 220);
    }

    public function preview(string $body): string
    {
        return $this->previewBody($body);
    }

    private function readPath(array $payload, array $path): mixed
    {
        $value = $payload;
        foreach ($path as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
