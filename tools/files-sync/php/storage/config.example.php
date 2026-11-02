<?php

// Optional display configuration. The guide works without a live config file.
// Do not put Ghost JWTs or Files-space credentials here; they belong on the
// user's local computer in the downloaded sync script or its environment.
return [
    'app' => [
        'title' => 'Files Sync',
        'version' => '1.0.0',
        'timezone' => 'UTC',
    ],
    'guide' => [
        'start_image' => 'assets/images/files-sync-start.webp',
        'end_image' => 'assets/images/files-sync-end.webp',
    ],
];
