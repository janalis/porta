<?php

declare(strict_types=1);

namespace Porta\Event;

use Porta\Workbench\Dock\DockToolTab;

/** A dock tool tab was closed — by its ×, or programmatically. */
final readonly class DockToolClosed
{
    public function __construct(
        public DockToolTab $tab,
    ) {}
}
