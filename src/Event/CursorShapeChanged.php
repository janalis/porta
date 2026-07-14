<?php

declare(strict_types=1);

namespace Porta\Event;

/** The hardware-cursor shape setting changed (holder already swapped). */
final readonly class CursorShapeChanged
{
    public function __construct(
        public string $shape,
    ) {}
}
