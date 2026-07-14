<?php

declare(strict_types=1);

namespace Porta\Ui;

use Porta\Widget\DebugPanelWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\TerminalPanelWidget;
use Symfony\Component\Tui\Tui;

/**
 * The keyboard focus ring for the edit view (F6 / Ctrl+F6): file tree, the
 * editor area's focusable content, and whichever bottom panels are open. The
 * editor area resolves the ring member per cycle so the ring always lands on
 * the active tab's attached viewer (editor / image / help) and never on a
 * detached widget. The git and settings views own their pane cycling; the
 * coordinator delegates to them instead of this ring while they are open.
 */
final class FocusRing
{
    public function __construct(
        private readonly Tui $tui,
        private readonly FileTreeWidget $tree,
        private readonly TerminalPanelWidget $terminalPanel,
        private readonly ResultsPanelWidget $resultsPanel,
        private readonly DebugPanelWidget $debugPanel,
        private readonly EditorArea $editorArea,
    ) {}

    /** Move focus to the next (+1) or previous (-1) ring member, wrapping around. */
    public function cycle(int $direction): void
    {
        $members = [$this->tree, $this->editorArea->widget()];
        foreach ([$this->terminalPanel, $this->resultsPanel, $this->debugPanel] as $panel) {
            if ($panel->isOpen()) {
                $members[] = $panel;
            }
        }

        $count = \count($members);
        $current = array_search($this->tui->getFocus(), $members, true);
        // Focus outside the ring (menu bar, popups): enter at the edge.
        $index = false === $current
            ? ($direction > 0 ? 0 : $count - 1)
            : ($current + $direction + $count) % $count;

        $this->tui->setFocus($members[$index]);
        $this->tui->requestRender();
    }
}
