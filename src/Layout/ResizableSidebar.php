<?php

declare(strict_types=1);

namespace Porta\Layout;

/**
 * A pane whose width the {@see LayoutController} drives from a separator drag.
 * Lets the resize loop treat the file tree, the git left column and the
 * settings nav uniformly instead of branching per widget type.
 */
interface ResizableSidebar
{
    public function width(): int;

    public function setWidth(int $width): void;
}
