<?php

declare(strict_types=1);

namespace MantisBat;

use DOMDocument;
use DOMXPath;
use RuntimeException;
use ZipArchive;

final class DocumentExtractor
{
    public function __construct(
        private readonly RuntimeConfig $config,
        private readonly ?Logger $logger = null
    ) {
    }

    public function extract(string $path, string $extension): string
    {
        if (!is_file($path)) {
            throw new RuntimeException('Uploaded file is missing.');
        }

        $extension = mb_strtolower(trim($extension));
        $text = match ($extension) {
            'txt' => $this->extractTxt($path),
            'pdf' => $this->extractPdf($path),
            'docx' => $this->extractDocx($path),
            default => throw new RuntimeException('Unsupported file type: ' . $extension),
        };

        $normalized = $this->normalizeText($text);
        if ($normalized === '') {
            throw new RuntimeException('Document extraction produced no usable text.');
        }

        return $normalized;
    }

    private function extractTxt(string $path): string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Could not read uploaded text file.');
        }

        return $this->toUtf8($contents);
    }

    private function extractPdf(string $path): string
    {
        $command = sprintf('pdftotext -enc UTF-8 %s - 2>/dev/null', escapeshellarg($path));
        $output = shell_exec($command);
        if (!is_string($output) || trim($output) === '') {
            throw new RuntimeException('PDF extraction failed. Check pdftotext availability and source file quality.');
        }

        return $this->toUtf8($output);
    }

    private function extractDocx(string $path): string
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Could not open DOCX archive.');
        }

        $documentXml = $zip->getFromName('word/document.xml');
        $zip->close();

        if (!is_string($documentXml) || trim($documentXml) === '') {
            throw new RuntimeException('DOCX document.xml is missing.');
        }

        $dom = new DOMDocument();
        $loaded = @$dom->loadXML($documentXml);
        if ($loaded !== true) {
            throw new RuntimeException('DOCX XML could not be parsed.');
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $paragraphs = [];
        foreach ($xpath->query('//w:p') ?: [] as $paragraphNode) {
            $parts = [];
            foreach ($xpath->query('.//w:t', $paragraphNode) ?: [] as $textNode) {
                $parts[] = $textNode->textContent;
            }

            $paragraph = trim(implode('', $parts));
            if ($paragraph !== '') {
                $paragraphs[] = $paragraph;
            }
        }

        return implode("\n\n", $paragraphs);
    }

    private function normalizeText(string $text): string
    {
        $text = $this->toUtf8($text);
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        $text = str_replace(["\r\n", "\r", "\f"], ["\n", "\n", "\n"], $text);
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = preg_replace("/\t+/", ' ', $text) ?? $text;
        $text = preg_replace("/[ ]{2,}/", ' ', $text) ?? $text;
        $text = preg_replace("/([[:alpha:]])-\n([[:alpha:]])/u", '$1$2', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        $paragraphs = preg_split("/\n\s*\n/", $text) ?: [];
        $normalizedParagraphs = [];
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            $paragraph = preg_replace("/(?<![\.\:\;\?\!])\n(?![\-\*\d])/", ' ', $paragraph) ?? $paragraph;
            $paragraph = preg_replace("/\n+/", "\n", $paragraph) ?? $paragraph;
            $paragraph = preg_replace("/[ ]{2,}/", ' ', $paragraph) ?? $paragraph;
            $paragraph = trim($paragraph);
            if ($paragraph !== '') {
                $normalizedParagraphs[] = $paragraph;
            }
        }

        return trim(implode("\n\n", $normalizedParagraphs));
    }

    private function toUtf8(string $text): string
    {
        if (mb_detect_encoding($text, 'UTF-8', true) !== false) {
            return $text;
        }

        return mb_convert_encoding($text, 'UTF-8', 'UTF-8, Windows-1252, ISO-8859-1');
    }
}
