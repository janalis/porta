<?php

declare(strict_types=1);

namespace Porta\Event;

use Porta\Workbench\Dock\DockTab;

/** A dock strip tab was clicked: show what it names. */
final readonly class DockTabActivationRequested
{
    public function __construct(
        public DockTab $tab,
        public string $key,
    ) {}
}
