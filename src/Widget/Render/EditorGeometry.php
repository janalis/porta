<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

/**
 * The horizontal layout of one editor frame: the width of the line-number
 * field, the full gutter (git strip + number + change marker + trailing space),
 * the text content area, and the display column of the right-margin error
 * stripe. Computed once per render from the terminal width and line count, then
 * threaded through the row-assembly helpers instead of four positional ints.
 */
final readonly class EditorGeometry
{
    public function __construct(
        public int $numberWidth,
        public int $gutterWidth,
        public int $contentWidth,
        public int $stripeCol,
    ) {}
}
