<?php

declare(strict_types=1);

namespace Porta\Event;

use Porta\Workbench\Dock\DockTab;

/**
 * A dock strip tab was dragged to a new position WITHIN its own family —
 * sessions among sessions, tools among tools. Cross-family endpoints never
 * reach here; the strip resolves them to a no-op first.
 */
final readonly class DockTabMoveRequested
{
    public function __construct(
        public ?DockTab $family,
        public int $from,
        public int $to,
    ) {}
}
