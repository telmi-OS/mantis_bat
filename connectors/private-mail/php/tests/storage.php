<?php

declare(strict_types=1);
require dirname(__DIR__) . '/src/Storage.php';
use MantisBat\PrivateMail\Storage;
$path = tempnam(sys_get_temp_dir(), 'private-mail-');
try {
    $storage = new Storage($path);
    $storage->migrate();
    $db = new PDO('sqlite:' . $path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $storage->setRule('a@example.com', null, 'notify');
    $storage->setRule('a@example.com', null, 'draft');
    $storage->setRule('a@example.com', 'Subject', 'notify');
    $storage->setRule('a@example.com', 'Subject', 'auto');
    if (count($storage->rules()) !== 2) throw new RuntimeException('Rule updates created duplicates');
    foreach ([['A@example.com', 'Subject', 'auto'], ['a@example.com', 'subject', 'draft'], ['other@example.com', 'Subject', 'summarize_notify']] as [$address, $subject, $expected]) {
        if ($storage->actionFor($address, $subject) !== $expected) throw new RuntimeException('Rule precedence failed');
    }
    echo "Storage rule checks passed.\n";
} finally {
    @unlink($path);
}
