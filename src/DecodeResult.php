<?php
declare(strict_types=1);

namespace X68000\Printer;

final class DecodeResult
{
    public function __construct(
        public string $text,
        public int $unknownEscapes = 0,
        public int $invalidPairs = 0
    ) {
    }

    public function hasWarnings(): bool
    {
        return $this->unknownEscapes > 0 || $this->invalidPairs > 0;
    }
}
