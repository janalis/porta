<?php

declare(strict_types=1);

namespace Porta\Event;

use Symfony\Component\Tui\Style\CursorShape;

/** The hardware-cursor shape setting changed (holder already swapped, to this resolved shape). */
final readonly class CursorShapeChanged
{
    public function __construct(
        public CursorShape $shape,
    ) {}
}
