<?php

declare(strict_types=1);
require dirname(__DIR__) . '/src/MimeParser.php';

use MantisBat\PrivateMail\MimeParser;

$headers = MimeParser::headers("From: Alice <alice@example.com>\r\nSubject: A long\r\n subject\r\n\r\n");
if (($headers['subject'] ?? '') !== 'A long subject') throw new RuntimeException('Folded header was not unfolded.');
$html = MimeParser::decodeBody('<p>Hello<br>world</p>', '7bit', 'text/html; charset=UTF-8');
if (str_contains($html, '<') || !str_contains($html, 'world')) throw new RuntimeException('HTML text was not converted.');
$structure = MimeParser::parseStructure('(("TEXT" "PLAIN" ("CHARSET" "UTF-8") NIL NIL "7BIT" 5 1)("APPLICATION" "PDF" ("NAME" "doc.pdf") NIL NIL "BASE64" 8 NIL ("ATTACHMENT" ("FILENAME" "doc.pdf"))) "MIXED")');
$parts = MimeParser::bodyParts($structure);
if (count($parts['parts']) !== 1 || count($parts['attachments']) !== 1 || $parts['attachments'][0]['part'] !== '2') throw new RuntimeException('MIME parts were not identified.');
if (MimeParser::decodeAttachment('aGVsbG8=', 'base64') !== 'hello') throw new RuntimeException('Attachment decoding failed.');
echo "MIME checks passed.\n";
