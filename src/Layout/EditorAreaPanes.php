<?php

declare(strict_types=1);

namespace Porta\Layout;

use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The editor area's panes in the current writing direction's visual order —
 * what a swapped-in full view (git, settings) restores into the main
 * container on close. Implemented by {@see PaneLayout}; tests inject stubs.
 */
interface EditorAreaPanes
{
    /** @return list<AbstractWidget> */
    public function orderedEditorPanes(): array;
}
