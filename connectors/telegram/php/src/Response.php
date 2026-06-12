<?php

declare(strict_types=1);

namespace MantisBat;

final class Response
{
    public function __construct(
        public readonly string $text,
        public readonly array $meta = []
    ) {
    }
}
