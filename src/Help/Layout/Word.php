<?php

declare(strict_types=1);

namespace Porta\Help\Layout;

/**
 * A word of wrapped text: what {@see Wrapper} packs into lines. $glued joins
 * it to the word before with no space (a link followed by its full stop);
 * $sourceStart is -1 when the word maps to no source byte of its own.
 */
final readonly class Word
{
    public function __construct(
        public string $text,
        public int $width,
        public Fragment $from,
        public bool $glued = false,
        public int $sourceStart = -1,
    ) {}
}
