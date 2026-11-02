<?php

// Optional display configuration. The guide works without a live config file.
// Do not put Ghost JWTs, AWS credentials, bucket names, or space IDs here.
// Keep the Ghost JWT in AWS Secrets Manager and AWS access on the Lambda role.
return [
    'app' => [
        'title' => 'Files Sync AWS S3',
        'version' => '1.0.0',
        'timezone' => 'UTC',
    ],
    'guide' => [
        'start_image' => 'assets/images/files-sync-start.webp',
        'end_image' => 'assets/images/files-sync-end.webp',
    ],
];
