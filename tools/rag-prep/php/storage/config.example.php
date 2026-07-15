<?php

return [
    'app' => [
        'installed' => true,
        'base_url' => 'https://example.com/tools/rag-prep/public',
        'timezone' => 'Europe/Berlin',
        'log_level' => 'info',
        'cron_secret' => 'replace-me',
        'status_secret' => 'replace-me',
        'health_secret' => 'replace-me',
        'access_secret' => 'replace-me',
        'installer_secret_hash' => 'replace-me',
        'version' => '0.1.0',
    ],
    'ghost' => [
        'api_base' => 'https://dev.telmi-ai.com/api/ghost/v2',
        'api_token' => 'replace-me',
        'paths' => [
            'chat' => '/chat',
            'settings' => '/settings',
        ],
    ],
    'limits' => [
        'max_file_size_mb' => 15,
        'max_job_size_mb' => 20,
        'max_files_per_job' => 5,
        'max_source_characters' => 120000,
        'worker_jobs_per_run' => 1,
    ],
    'features' => [
        'pdf_support' => true,
        'docx_support' => true,
        'txt_support' => true,
    ],
];
