<?php

declare(strict_types=1);

namespace Porta\Input;

use Porta\Widget\Git\ChangesListWidget;
use Porta\Widget\Git\CommitBoxWidget;
use Porta\Widget\Git\DiffViewWidget;
use Porta\Widget\Git\GitTabsWidget;
use Porta\Widget\Git\HistoryListWidget;
use Porta\Widget\SeparatorWidget;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Tui;

/**
 * Mouse routing for the git view's panes — the changes list, commit box,
 * history browser, tabs and diff pane — plus the diff pane's row-range drag
 * selection. Owns the git widgets and the diff-drag flag so {@see MouseRouter}
 * (which keeps the load-bearing guard order and the menu/editor logic) no longer
 * has to. Its panes are live only while the git view is swapped into the main
 * area, so its hit tests miss under the normal editor layout.
 */
final class GitMouseRouter
{
    /** A press on a diff body row arms a row-range drag (rows join as the pointer moves). */
    private bool $diffSelecting = false;

    public function __construct(
        private readonly HitTester $hit,
        private readonly Tui $tui,
        private readonly ChangesListWidget $changes,
        private readonly DiffViewWidget $diff,
        private readonly CommitBoxWidget $commitBox,
        private readonly HistoryListWidget $history,
        private readonly GitTabsWidget $tabs,
        private readonly SeparatorWidget $separator,
    ) {}

    /** The sidebar's drag column (identity for the layout's resize arming). */
    public function separatorRect(): ?WidgetRect
    {
        return $this->hit->rect($this->separator);
    }

    /** The sidebar's left edge (the tab strip spans exactly the sidebar width). */
    public function leftRect(): ?WidgetRect
    {
        return $this->hit->rect($this->tabs);
    }

    /** Clear the diff-drag arming on a fresh press (mirrors the other drag resets). */
    public function cancelDrag(): void
    {
        $this->diffSelecting = false;
    }

    /** Horizontal wheel over the diff pane; returns whether it scrolled. */
    public function scrollHorizontal(MouseEvent $mouse): bool
    {
        if (null !== $this->hit->hit($this->diff, $mouse->row, $mouse->col)) {
            $this->diff->scrollHBy(6 * $mouse->wheelHDelta);

            return true;
        }

        return false;
    }

    /** Vertical wheel over a git pane; returns whether it scrolled. */
    public function scrollVertical(MouseEvent $mouse, int $step): bool
    {
        if (null !== $this->hit->hit($this->diff, $mouse->row, $mouse->col)) {
            $this->diff->scrollBy($step);

            return true;
        }
        // The lists scroll by feeding arrow keys so the scroll follows selection.
        $key = $mouse->wheelDelta < 0 ? "\x1b[A" : "\x1b[B";
        if (null !== $this->hit->hit($this->changes, $mouse->row, $mouse->col)) {
            for ($i = 0; $i < 3; ++$i) {
                $this->changes->handleInput($key);
            }

            return true;
        }
        if (null !== $this->hit->hit($this->history, $mouse->row, $mouse->col)) {
            for ($i = 0; $i < 3; ++$i) {
                $this->history->handleInput($key);
            }

            return true;
        }

        return false;
    }

    /**
     * Extend the diff row-range selection while the left button drags over the
     * diff pane; the release keeps the range armed for a follow-up copy.
     */
    public function continueDiffDrag(MouseEvent $mouse): bool
    {
        if (!$this->diffSelecting || !$mouse->isLeftDrag() && !$mouse->isLeftRelease()) {
            return false;
        }
        // Only genuine motion extends the range — a plain click's release
        // must not arm an anchor on the row it already selected.
        if ($mouse->isLeftDrag() && null !== ($rel = $this->hit->hit($this->diff, $mouse->row, $mouse->col)) && $rel['row'] > 0) {
            $this->diff->dragSelectTo($rel['row'], $rel['col']);
            $this->tui->requestRender();
        }
        if ($mouse->isLeftRelease()) {
            $this->diffSelecting = false;
        }

        return true;
    }

    /** A left press on a git pane (rects exist only while the view is swapped in). */
    public function tryPaneClick(MouseEvent $mouse): bool
    {
        if (null !== ($rel = $this->hit->hit($this->tabs, $mouse->row, $mouse->col))) {
            $this->tabs->handleClick($rel['col']);
            $this->tui->requestRender();

            return true;
        }
        if (null !== ($rel = $this->hit->hit($this->changes, $mouse->row, $mouse->col))) {
            $this->tui->setFocus($this->changes);
            $this->changes->handleClick($rel['row'], $rel['col']);
            $this->tui->requestRender();

            return true;
        }
        if (null !== ($rel = $this->hit->hit($this->history, $mouse->row, $mouse->col))) {
            $this->tui->setFocus($this->history);
            $this->history->handleClick($rel['row']);
            $this->tui->requestRender();

            return true;
        }
        if (null !== ($rel = $this->hit->hit($this->commitBox, $mouse->row, $mouse->col))) {
            $this->tui->setFocus($this->commitBox);
            $this->commitBox->handleClick($rel['row']);
            $this->tui->requestRender();

            return true;
        }
        if (null !== ($rel = $this->hit->hit($this->diff, $mouse->row, $mouse->col))) {
            $this->tui->setFocus($this->diff);
            $this->diff->handleClick($rel['row'], $rel['col']);
            // A body-row press arms a drag selection (rows join the range as
            // the pointer moves; ctrl+c then copies the highlighted lines).
            $this->diffSelecting = $rel['row'] > 0;
            $this->tui->requestRender();

            return true;
        }

        return false;
    }

    /**
     * Hover tints the row under the pointer in whichever list is attached; a
     * miss clears the tint (pointer over another pane). Called after the menu
     * bar has had its turn.
     */
    public function hover(MouseEvent $mouse): void
    {
        $changed = $this->hit->hover($mouse->row, $mouse->col, $this->changes, $this->history, $this->commitBox);

        $tabsRect = $this->hit->rect($this->tabs);
        $onTabs = null !== $tabsRect && $tabsRect->contains($mouse->row, $mouse->col);
        $changed = $this->tabs->hoverMode($onTabs ? $this->tabs->modeAtColumn($mouse->col - $tabsRect->col) : null) || $changed;

        if ($changed) {
            $this->tui->requestRender();
        }
    }
}
