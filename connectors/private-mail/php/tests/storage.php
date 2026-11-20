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
    $db->exec("INSERT INTO rules(address, subject, action) VALUES ('a@example.com', NULL, 'draft'), ('a@example.com', 'Subject', 'auto')");
    foreach ([['A@example.com', 'Subject', 'auto'], ['a@example.com', 'subject', 'draft'], ['other@example.com', 'Subject', 'summarize_notify']] as [$address, $subject, $expected]) {
        if ($storage->actionFor($address, $subject) !== $expected) throw new RuntimeException('Rule precedence failed');
    }
    echo "Storage rule checks passed.\n";
} finally {
    @unlink($path);
}
