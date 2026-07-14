<?php

declare(strict_types=1);

namespace Porta\Help;

/**
 * One rendered fragment of a markdown link: the display-column range it
 * occupies on one rendered line. A link split across wrapped lines yields
 * several spans sharing a `group`, so link-cycling focuses and highlights
 * every fragment together.
 */
final readonly class LinkSpan
{
    /** @param int $endCol exclusive display column */
    public function __construct(
        public int $line,
        public int $col,
        public int $endCol,
        public string $target,
        public int $group,
    ) {}
}
