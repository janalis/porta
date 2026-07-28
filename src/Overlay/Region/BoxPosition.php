<?php

declare(strict_types=1);

namespace Porta\Overlay\Region;

/**
 * A floating box's user-dragged top-left corner. The two components always
 * travel together — a drag writes both, the composite clamps both — which is
 * why they are one value and not two nullable ints.
 */
final readonly class BoxPosition
{
    public function __construct(
        public int $row,
        public int $col,
    ) {}
}
