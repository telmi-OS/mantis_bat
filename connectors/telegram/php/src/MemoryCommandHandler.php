<?php

declare(strict_types=1);

namespace MantisBat;

use Throwable;

final class MemoryCommandHandler
{
    public function __construct(
        private readonly Config $config,
        private readonly GhostClient $ghostClient
    ) {
    }

    public function handle(string $text, array $context = []): Response
    {
        $prefix = (string) $this->config->get('commands.memory_up_prefix', 'bat_memory_up:');
        $payload = trim(mb_substr($text, mb_strlen($prefix)));
        if ($payload === '') {
            return new Response("Could not upload memory.\n\nReason:\nMemory text is empty.");
        }

        $limit = (int) $this->config->get('limits.max_memory_upload_chars', 50000);
        if (mb_strlen($payload) > $limit) {
            return new Response("Could not upload memory.\n\nReason:\nMemory text exceeds the configured limit.");
        }

        try {
            $this->ghostClient->upsertMemory($payload, [
                'source' => 'mantis_bat',
                'channel' => 'telegram',
                'command' => 'bat_memory_up',
                'created_by' => 'telegram',
                'telegram_user_id' => (string) ($context['telegram_user_id'] ?? ''),
            ]);
        } catch (Throwable $exception) {
            return new Response("Could not upload memory.\n\nReason:\n" . $exception->getMessage());
        }

        $preview = mb_substr($payload, 0, 300);
        return new Response("Memory uploaded to your Ghost.\n\nStored:\n\"{$preview}\"");
    }
}
