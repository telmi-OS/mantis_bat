<?php

declare(strict_types=1);

namespace MantisBat;

final class CommandRouter
{
    public function __construct(
        private readonly Config $config,
        private readonly MemoryCommandHandler $memoryHandler,
        private readonly Storage $storage
    ) {
    }

    public function handle(string $text, array $context = []): ?Response
    {
        $normalized = mb_strtolower(trim($text));
        $memoryPrefix = mb_strtolower((string) $this->config->get('commands.memory_up_prefix', 'bat_memory_up:'));
        $helpCommand = mb_strtolower((string) $this->config->get('commands.help_command', 'bat_help'));
        $statusCommand = mb_strtolower((string) $this->config->get('commands.status_command', 'bat_status'));
        $searchPrefix = mb_strtolower((string) $this->config->get('commands.memory_search_prefix', 'bat_memory_search:'));
        $listCommand = mb_strtolower((string) $this->config->get('commands.memory_list_command', 'bat_memory_list'));
        $deletePrefix = mb_strtolower((string) $this->config->get('commands.memory_delete_prefix', 'bat_memory_delete:'));

        if (str_starts_with($normalized, $memoryPrefix)) {
            return $this->memoryHandler->handle($text, $context);
        }

        if ($normalized === $helpCommand) {
            return new Response("Mantis Bat commands\n\nNormal message:\nTalk to your Telmi Ghost.\n\nUpload memory:\n" . $this->config->get('commands.memory_up_prefix') . " text to remember\n\nSearch memory:\n" . $this->config->get('commands.memory_search_prefix') . " query\n\nStatus:\n" . $this->config->get('commands.status_command') . "\n\nHelp:\n" . $this->config->get('commands.help_command'));
        }

        if ($normalized === $statusCommand) {
            $owner = $this->storage->getAuthorizedOwner();
            $ownerLabel = $owner && ($owner['username'] ?? '') !== '' ? '@' . $owner['username'] : 'paired';
            return new Response("Mantis Bat status\n\nTelegram: connected\nGhost API: configured\nOwner: {$ownerLabel}\nInbox polling: available via cron\nMemory command: enabled\nVersion: " . $this->config->get('app.version', '0.1.0'));
        }

        if (str_starts_with($normalized, $searchPrefix) || $normalized === $listCommand || str_starts_with($normalized, $deletePrefix)) {
            return new Response('This command is planned but not enabled in this version.');
        }

        return null;
    }
}
