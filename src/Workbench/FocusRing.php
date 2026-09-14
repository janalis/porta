<?php

declare(strict_types=1);

namespace Porta\Workbench;

use Porta\Widget\FileTreeWidget;
use Porta\Workbench\Dock\BottomDock;
use Symfony\Component\Tui\Tui;

/**
 * The keyboard focus ring for the edit view (F6 / Ctrl+F6): file tree, the
 * editor area's focusable content, and the bottom dock's visible panel. The
 * editor area resolves the ring member per cycle so the ring always lands on
 * the active tab's attached viewer (editor / image / help) and never on a
 * detached widget. The git and settings views own their pane cycling; the
 * coordinator delegates to them instead of this ring while they are open.
 */
final readonly class FocusRing
{
    public function __construct(
        private Tui $tui,
        private FileTreeWidget $tree,
        private BottomDock $dock,
        private EditorArea $editorArea,
    ) {}

    /** Move focus to the next (+1) or previous (-1) ring member, wrapping around. */
    public function cycle(int $direction): void
    {
        $members = [$this->tree, $this->editorArea->widget()];
        $panel = $this->dock->visiblePanel();
        if (null !== $panel) {
            $members[] = $panel;
        }

        $count = \count($members);
        $current = \array_search($this->tui->getFocus(), $members, strict: true);
        // Focus outside the ring (menu bar, popups): enter at the edge.
        $edge = $direction > 0 ? 0 : $count - 1;
        $index = false === $current
            ? $edge
            : ($current + $direction + $count) % $count;

        $this->tui->setFocus($members[$index]);
        $this->tui->requestRender();
    }
}
