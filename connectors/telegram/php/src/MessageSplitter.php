<?php

declare(strict_types=1);

namespace MantisBat;

final class MessageSplitter
{
    public function __construct(private readonly int $limit = 3900)
    {
    }

    public function split(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [''];
        }

        if (mb_strlen($text) <= $this->limit) {
            return [$text];
        }

        $parts = [];
        $remaining = $text;

        while (mb_strlen($remaining) > $this->limit) {
            $slice = mb_substr($remaining, 0, $this->limit);
            $breakPos = max(
                (int) mb_strrpos($slice, "\n\n"),
                (int) mb_strrpos($slice, "\n"),
                (int) mb_strrpos($slice, ' ')
            );

            if ($breakPos <= 0) {
                $breakPos = $this->limit;
            }

            $parts[] = trim(mb_substr($remaining, 0, $breakPos));
            $remaining = trim(mb_substr($remaining, $breakPos));
        }

        if ($remaining !== '') {
            $parts[] = $remaining;
        }

        if (count($parts) <= 1) {
            return $parts;
        }

        $total = count($parts);
        foreach ($parts as $index => $part) {
            $parts[$index] = sprintf('(%d/%d) %s', $index + 1, $total, $part);
        }

        return $parts;
    }
}
