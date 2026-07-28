<?php

declare(strict_types=1);

namespace Porta\Overlay\Region;

/**
 * A floating box's user-dragged size override. For the find modal, $rows is
 * the preview WINDOW's row count (the frame chrome is not included); a corner
 * drag writes both components together, which is why they are one value.
 */
final readonly class BoxSize
{
    public function __construct(
        public int $width,
        public int $rows,
    ) {}
}
