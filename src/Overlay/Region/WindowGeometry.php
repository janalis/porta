<?php

declare(strict_types=1);

namespace Porta\Overlay\Region;

/**
 * Where a floating window was placed this frame, and how big: the whole box,
 * frame included, in screen cells. {@see \Porta\Overlay\State\WindowFrame}
 * answers it; the window draws its content at the size it leaves inside.
 */
final readonly class WindowGeometry
{
    public function __construct(
        public int $top,
        public int $col,
        public int $width,
        public int $rows,
    ) {}

    /** The row just below the box. */
    public function bottom(): int
    {
        return $this->top + $this->rows;
    }

    /** The column just right of the box. */
    public function right(): int
    {
        return $this->col + $this->width;
    }
}
