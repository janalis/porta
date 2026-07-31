<?php

declare(strict_types=1);

namespace Porta\Event;

use Porta\Workbench\Dock\DockToolTab;

/**
 * A dock tool tab became the one the results panel displays.
 *
 * Dispatched at the TAIL of the bind, after the panel has been rebuilt and the
 * tab recorded as bound — so a listener that re-binds the same tab finds
 * nothing left to do rather than recursing.
 */
final readonly class DockToolBound
{
    public function __construct(
        public DockToolTab $tab,
    ) {}
}
