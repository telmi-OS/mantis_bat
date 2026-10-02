<?php

// Reference only. Use public/install.php to create the private live config.
return [
    'app' => [
        'installed' => false,
        'base_url' => '',
        'timezone' => 'Europe/Berlin',
        'version' => '0.1.0',
        'cron_secret' => '',
        'access_secret' => '',
        'status_secret' => '',
        'health_secret' => '',
        'installer_secret_hash' => '',
    ],
    'ghost' => [
        'api_base' => 'https://dev.telmi-ai.com/api/ghost/v2',
        'api_token' => '',
        'group_id' => '',
        'group_name' => '',
    ],
    'files' => ['space_id' => '', 'group_label' => '', 'folder_id' => ''],
    'evaluation' => ['use_rag' => true, 'use_history' => false, 'max_cases' => 40, 'judge_rubric' => ''],
    'schedule' => [
        'enabled' => false, 'suite_file_id' => '', 'suite_file_name' => '',
        'interval' => 'daily', 'interval_seconds' => 86400, 'next_run_at' => 0,
        'last_attempt_at' => 0, 'last_status' => 'Disabled', 'last_error' => '',
    ],
    'notifications' => ['enabled' => false, 'on_start' => false, 'on_finish' => true, 'on_errors' => true, 'on_p0_failures' => true],
];
