<?php

// Reference only. Use public/install.php to create the private live config.
return [
    'app' => [
        'installed' => false, 'base_url' => '', 'timezone' => 'UTC', 'version' => '0.1.0',
        'status_secret' => '', 'access_secret' => '', 'installer_secret_hash' => '',
    ],
    'api' => ['auth_key' => ''],
    'nextcloud' => ['base_url' => '', 'username' => '', 'app_password' => '', 'timeout_seconds' => 10],
    'meeting' => ['default_name' => 'Teleport AI Meeting', 'name_max_length' => 100, 'dedupe_seconds' => 300],
];
