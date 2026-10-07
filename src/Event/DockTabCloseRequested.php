<?php

declare(strict_types=1);

namespace Porta\Event;

use Porta\Workbench\Dock\DockTab;

/** A dock strip tab's ✖ was pressed. */
final readonly class DockTabCloseRequested
{
    public function __construct(
        public ?DockTab $tab,
        public string $key,
    ) {}
}
