<?php

declare(strict_types=1);

$moduleRoot = dirname(__DIR__);

foreach ([
    'Response',
    'Security',
    'RuntimeConfig',
    'Storage',
    'Logger',
    'TelegramClient',
    'GhostClient',
    'MessageSplitter',
    'MemoryCommandHandler',
    'CommandRouter',
    'ChatHandler',
    'InboxPoller',
    'Installer',
] as $classFile) {
    require_once $moduleRoot . '/src/' . $classFile . '.php';
}

use MantisBat\ChatHandler;
use MantisBat\CommandRouter;
use MantisBat\GhostClient;
use MantisBat\InboxPoller;
use MantisBat\Installer;
use MantisBat\Logger;
use MantisBat\MemoryCommandHandler;
use MantisBat\MessageSplitter;
use MantisBat\RuntimeConfig;
use MantisBat\Security;
use MantisBat\Storage;
use MantisBat\TelegramClient;

$security = new Security();
$config = new RuntimeConfig($moduleRoot . '/storage/config.php');
$storage = new Storage($moduleRoot . '/storage/mantis_bat.sqlite');
$storage->migrate();
$logger = new Logger(
    $security,
    $storage,
    $moduleRoot . '/storage/mantis_bat.log',
    [
        (string) $config->get('telegram.bot_token', ''),
        (string) $config->get('telegram.webhook_secret', ''),
        (string) $config->get('ghost.api_token', ''),
    ]
);
$telegram = new TelegramClient((string) $config->get('telegram.bot_token', ''), $logger);
$ghost = new GhostClient($config, $logger);
$splitter = new MessageSplitter((int) $config->get('limits.telegram_max_message_chars', 3900));
$memoryHandler = new MemoryCommandHandler($config, $ghost);
$commandRouter = new CommandRouter($config, $memoryHandler, $storage);
$chatHandler = new ChatHandler($ghost, $telegram, $splitter);
$inboxPoller = new InboxPoller($storage, $config, $ghost, $telegram, $splitter, $logger);
$installer = new Installer($moduleRoot, $security);

return [
    'module_root' => $moduleRoot,
    'security' => $security,
    'config' => $config,
    'storage' => $storage,
    'logger' => $logger,
    'telegram' => $telegram,
    'ghost' => $ghost,
    'splitter' => $splitter,
    'memory_handler' => $memoryHandler,
    'command_router' => $commandRouter,
    'chat_handler' => $chatHandler,
    'inbox_poller' => $inboxPoller,
    'installer' => $installer,
];
