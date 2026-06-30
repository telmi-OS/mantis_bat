<?php

return [
    'app' => [
        'installed' => true,
        'base_url' => 'https://example.com/mantis-bat/public',
        'timezone' => 'Europe/Berlin',
        'log_level' => 'info',
        'cron_secret' => 'change-me',
        'status_secret' => 'change-me-status',
        'health_secret' => 'change-me-health',
        'installer_secret_hash' => '',
        'version' => '0.1.0',
    ],
    'telegram' => [
        'bot_token' => 'PASTE_TELEGRAM_BOT_TOKEN',
        'webhook_secret' => 'change-me',
        'owner_chat_id' => null,
        'owner_username' => null,
    ],
    'ghost' => [
        'api_base' => 'https://dev.telmi-ai.com/api/ghost/v2',
        'api_token' => 'PASTE_GHOST_API_TOKEN',
        'default_group_id' => '',
        'ghost_id_mode' => 'token',
        'paths' => [
            'chat' => '/chat',
            'inbox' => '/inbox',
            'inbox_groups' => '/inbox_groups',
            'memory_upsert' => '/memory/upsert',
            'memory_list' => '/memory/list',
            'memory_delete' => '/memory/delete',
            'memory_search' => '/memory/search',
            'settings' => '/settings',
        ],
    ],
    'commands' => [
        'memory_up_prefix' => 'bat_memory_up:',
        'memory_search_prefix' => 'bat_memory_search:',
        'memory_list_command' => 'bat_memory_list',
        'memory_delete_prefix' => 'bat_memory_delete:',
        'help_command' => 'bat_help',
        'status_command' => 'bat_status',
    ],
    'limits' => [
        'telegram_max_message_chars' => 3900,
        'max_inbound_message_chars' => 20000,
        'max_memory_upload_chars' => 50000,
        'cron_batch_size' => 50,
    ],
];
