<?php

declare(strict_types=1);

namespace Porta\Input;

use Porta\Container\AsIdeService;
use Porta\I18n\Translator;
use Porta\Support\ProjectPaths;
use Porta\Support\Scheduler;
use Porta\Tree\TreeDragState;
use Porta\Ui\Glyphs;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\StatusBarWidget;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Tui;

/**
 * The file-tree drag, from the press that arms it to the release that spends
 * it.
 *
 * Its own class rather than four more methods on {@see
 * MouseRouter}, which is the class both of mago's ceilings are
 * bisected against — its parameter list and its cyclomatic complexity are the
 * floors, so anything added there has to displace something. The shape is
 * {@see \Porta\Workbench\Onboarding\ModalWindowRouter}'s: one `continueDrag()`
 * in the router's `||` chain, one `cancel()` in its fresh-press sweep.
 */
#[AsIdeService]
final class TreeDragRouter
{
    /**
     * Rows per tick while the pointer rests in an edge zone — about 16 a
     * second, the rate a held arrow key repeats at.
     */
    private const float SCROLL_INTERVAL = 0.06;

    /**
     * How long a drag rests on a folder before it opens. PhpStorm's own
     * spring-load is about half a second; shorter and a pointer crossing a
     * folder on its way somewhere else opens it by accident.
     */
    private const float SPRING_DELAY = 0.5;

    private ?TreeDragState $drag = null;
    private ?string $scrollTimer = null;
    private ?string $springTimer = null;
    private ?string $springPath = null;

    /**
     * The folders this drag sprang open, in the order it opened them.
     *
     * A folder opened only to look inside closes again once the drag moves
     * off it — Finder's spring-loaded folders, and what keeps a drag across a
     * few folders from leaving the tree unfolded behind it. The ones on the
     * way to where it lands stay open: they are what the user sees the drop
     * arrive in.
     *
     * @var list<string>
     */
    private array $sprung = [];

    /** The in-flight hint this router put on the status bar, '' for none. */
    private string $announced = '';

    public function __construct(
        private readonly FileTreeWidget $tree,
        private readonly Tui $tui,
        private readonly Scheduler $clock,
        private readonly StatusBarWidget $statusBar,
        private readonly Translator $translator,
    ) {}

    /**
     * Arm a drag carrying $sources, or nothing when the press grabbed nothing
     * draggable.
     *
     * @param list<string> $sources
     */
    public function arm(array $sources): void
    {
        $this->drag = [] === $sources ? null : new TreeDragState($sources);
    }

    /**
     * Track an armed drag and spend it on release; false when none is armed,
     * so the router's chain falls through to everything else.
     *
     * A release outside the tree but inside the editor OPENS the dragged files
     * rather than moving them — the second drop target, and the only one that
     * touches no disk.
     */
    public function continueDrag(MouseEvent $mouse, ?WidgetRect $treeRect, ?WidgetRect $editorRect): bool
    {
        $drag = $this->drag;
        if (null === $drag || (!$mouse->isLeftDrag() && !$mouse->isLeftRelease())) {
            return false;
        }
        if (!$drag->moved && $mouse->isLeftDrag()) {
            $drag->moved = true;
            // Once, on the first motion — the only moment it is news. The Alt
            // modifier is read from the RELEASE, so naming it here is an offer
            // rather than a description, and it appears nowhere else in the UI.
            $this->announced = $this->translator->trans(
                'Moving %count% file — hold Alt to copy|Moving %count% files — hold Alt to copy',
                ['%count%' => \count($drag->sources)],
            );
            $this->statusBar->flash($this->announced);
        }
        $this->track($drag, $mouse->row, $mouse->col, $treeRect);
        if ($mouse->isLeftRelease()) {
            $this->disarm($drag->moved ? $drag->target : null);
            // A press and release on one spot is a CLICK: pay the toggle the
            // press deferred, and move nothing.
            $drag->moved ? $this->drop($drag, $mouse, $editorRect) : $this->tree->commitPress();
        }
        $this->tui->requestRender();

        return true;
    }

    /**
     * A drag from OUTSIDE the IDE passing over the screen at ($row, $col) —
     * kitty's drag-and-drop protocol reports the pointer, and nothing else
     * does. The tree half of it is this router's own drag in every respect but
     * the payload, which an outside drag does not show until it is dropped:
     * the same highlight, edge scroll and spring-loaded folders.
     *
     * Returns the folder a drop here would land in, or null off the tree.
     */
    public function hoverExternal(int $row, int $col, ?WidgetRect $treeRect): ?string
    {
        // Fresh on every motion: an outside drag carries nothing from one to
        // the next — its spring and its edge scroll live on this router.
        $drag = new TreeDragState([]);
        $this->drag = $drag;
        $this->track($drag, $row, $col, $treeRect);

        return $drag->target;
    }

    /**
     * The outside drag left the window, or was dropped: forget it, closing the
     * folders it sprang open except those on the way to $landed.
     */
    public function endExternal(?string $landed = null): void
    {
        $this->disarm($landed);
        $this->tui->requestRender();
    }

    /**
     * The gesture was ABANDONED from outside — Escape, or a fresh press
     * somewhere else. Forget the drag, its highlight, and the folder toggle the
     * press deferred.
     *
     * The toggle is the half that is easy to miss, and the reason this is not
     * the same method the release path uses. Escape ends the drag while the
     * button is still DOWN, so the release that follows finds nothing armed and
     * is discarded — {@see continueDrag()} returns false and the router's chain
     * falls through it. `commitPress()` therefore never runs, and a toggle left
     * pending survives the whole gesture, to be paid by the next click that
     * happens to end without motion. A folder nobody touched opening on its own
     * is not cosmetic: the rows below it move, and the click after that lands
     * somewhere nobody aimed at.
     */
    public function cancel(): void
    {
        if (null === $this->drag) {
            return;
        }
        $this->disarm();
        $this->tree->forgetPress();
    }

    /**
     * Spend the drag without judging what the press still owes.
     *
     * The release path's half of {@see cancel()}: it disarms FIRST and then
     * decides, because a release with no motion is a click and still owes the
     * toggle. Folding the two together is the obvious simplification and it is
     * wrong — it makes clicking a folder stop expanding it, which is what the
     * tree's own click tests said the first time this was written that way.
     *
     * $landed is where the drag dropped, whose sprung ancestors stay open;
     * null closes every folder the drag opened.
     */
    private function disarm(?string $landed = null): void
    {
        $this->drag = null;
        $this->stopScrolling();
        $this->stopSpring();
        $this->collapseSprungOutside($landed);
        // What stayed open is the user's tree now, not this drag's to close
        // when the next one passes by.
        $this->sprung = [];
        // The hint describes a drag that is happening; once it is not, sitting
        // out its remaining seconds would cover what the drop has to say —
        // which on a drop into the editor is the name of the file just opened.
        if ('' !== $this->announced) {
            $this->statusBar->withdrawFlash($this->announced);
            $this->announced = '';
        }
        $this->tree->setDropTarget(null);
    }

    /** One motion of a drag: where it aims, the highlight, the edge scroll and the spring. */
    private function track(TreeDragState $drag, int $row, int $col, ?WidgetRect $treeRect): void
    {
        $drag->target = $this->dropDir($row, $col, $treeRect);
        $this->tree->setDropTarget($drag->target, $this->payloadLabel($drag));
        $this->trackEdge($drag, $row, $col, $treeRect);
        $this->trackSpring($drag->target);
    }

    /**
     * Keep the tree scrolling while the pointer rests in an edge zone, and stop
     * the moment it does not.
     *
     * Re-armed from scratch on every motion so the tick always carries the
     * LATEST pointer, which is what it re-resolves the drop target from.
     */
    private function trackEdge(TreeDragState $drag, int $row, int $col, ?WidgetRect $treeRect): void
    {
        $this->stopScrolling();
        if (0 !== $this->edgeDelta($row, $col, $treeRect)) {
            $this->armScroll($drag, $row, $col, $treeRect);
        }
    }

    /**
     * Which way a pointer at this row should scroll the tree, or 0 away from
     * the edges.
     *
     * Row 0 is the tree's header and row 1 its first entry, so both mean "one
     * more row above this one, please"; the pane's last row means the same
     * downwards. A drag that has left the tree scrolls nothing.
     */
    private function edgeDelta(int $row, int $col, ?WidgetRect $treeRect): int
    {
        if (null === $treeRect || !$treeRect->contains($row, $col)) {
            return 0;
        }
        $relRow = $treeRect->toRelative($row, $col)['row'];

        return match (true) {
            $relRow <= 1 => -1,
            $relRow >= ($treeRect->rows - 1) => 1,
            default => 0,
        };
    }

    /**
     * A pointer resting on the edge sends NOTHING — the terminal reports
     * motion, not position — so the repeat has to come from a clock. Without
     * one the scroll would stop the instant the user stopped moving, which is
     * exactly when they are waiting for it.
     *
     * The drag is captured rather than read back off the field: the timer is
     * cancelled by {@see disarm()}, so the one it was armed with is the only
     * one it can ever fire against.
     */
    private function armScroll(TreeDragState $drag, int $row, int $col, ?WidgetRect $treeRect): void
    {
        $this->scrollTimer = $this->clock->delay(self::SCROLL_INTERVAL, function () use ($drag, $row, $col, $treeRect): void {
            $this->scrollTimer = null;
            $this->scrollTick($drag, $row, $col, $treeRect);
        });
    }

    /**
     * One scrolled row, then the same again — until the tree has no more to
     * give, which is what ends the chain.
     *
     * The rows have moved under a pointer that has not, so the drop target is
     * resolved AGAIN from where the pointer still is. Leaving it alone would
     * point the highlight, and the drop, at the row the user just scrolled away
     * from.
     */
    private function scrollTick(TreeDragState $drag, int $row, int $col, ?WidgetRect $treeRect): void
    {
        if (!$this->tree->scrollDuringDrag($this->edgeDelta($row, $col, $treeRect))) {
            return;
        }
        $drag->target = $this->dropDir($row, $col, $treeRect);
        $this->tree->setDropTarget($drag->target, $this->payloadLabel($drag));
        $this->tui->requestRender();
        $this->armScroll($drag, $row, $col, $treeRect);
    }

    /**
     * What the drag is carrying, for the row it would land on.
     *
     * The highlight answers "where" and never "what", and on a multi-selection
     * drag the rows that are travelling may be scrolled off screen entirely —
     * so this is the only thing that says whether one file is moving or nine.
     *
     * A drag from outside carries nothing countable until it lands — the
     * terminal hands the paths over on the drop — so it says what will happen
     * instead.
     */
    private function payloadLabel(TreeDragState $drag): string
    {
        return (
            Glyphs::DROP_INTO
            .' '
            .(
                [] === $drag->sources
                    ? $this->translator->trans('Copy here')
                    : $this->translator->trans(
                        '%count% file|%count% files',
                        ['%count%' => \count($drag->sources)],
                    )
            )
        );
    }

    /**
     * Start, keep or drop the dwell that opens the folder under the pointer.
     *
     * The first line is the one that matters: a pointer is never perfectly
     * still, and every jitter is another motion event. Restart the dwell on
     * each one and the folder never opens at all — a failure that looks exactly
     * like the feature not being there. So the dwell survives as long as the
     * TARGET is unchanged, not as long as the pointer is.
     *
     * Armed for any target rather than only a collapsed folder, because whether
     * a path is one is the tree's question, not this router's; an already-open
     * folder simply wakes up and finds nothing to do.
     */
    private function trackSpring(?string $target): void
    {
        if ($target === $this->springPath) {
            return;
        }
        $this->stopSpring();
        $this->collapseSprungOutside($target);
        if (null === $target) {
            return;
        }
        $this->springPath = $target;
        $this->springTimer = $this->clock->delay(self::SPRING_DELAY, function () use ($target): void {
            $this->springTimer = null;
            if ($this->tree->expandDuringDrag($target)) {
                $this->sprung[] = $target;
                $this->tui->requestRender();
            }
        });
    }

    /** Close every folder this drag sprang open that $target is not inside — all of them for null. */
    private function collapseSprungOutside(?string $target): void
    {
        $holds = static fn (string $folder): bool => null !== $target && ProjectPaths::isUnder($target, $folder);
        foreach ($this->sprung as $folder) {
            if ($holds($folder)) {
                continue;
            }
            $this->tree->collapseDuringDrag($folder);
        }
        $this->sprung = \array_values(\array_filter($this->sprung, $holds));
    }

    private function stopSpring(): void
    {
        if (null !== $this->springTimer) {
            $this->clock->cancel($this->springTimer);
            $this->springTimer = null;
        }
        $this->springPath = null;
    }

    private function stopScrolling(): void
    {
        if (null === $this->scrollTimer) {
            return;
        }
        $this->clock->cancel($this->scrollTimer);
        $this->scrollTimer = null;
    }

    private function dropDir(int $row, int $col, ?WidgetRect $treeRect): ?string
    {
        return null !== $treeRect && $treeRect->contains($row, $col)
            ? $this->tree->dropDirAt($treeRect->toRelative($row, $col)['row'])
            : null;
    }

    private function drop(TreeDragState $drag, MouseEvent $mouse, ?WidgetRect $editorRect): void
    {
        $this->tree->forgetPress();
        if (null !== $drag->target) {
            // Alt, not Ctrl as PhpStorm has it: Ctrl already means "add to the
            // selection" on a tree row and Shift means "extend to it", so that
            // chord would decide two things at once. Read from the RELEASE, so
            // the modifier that started the drag never picks the outcome.
            $this->tree->requestDrop($drag->sources, $drag->target, $mouse->alt);

            return;
        }
        if (null === $editorRect || !$editorRect->contains($mouse->row, $mouse->col)) {
            // A release over the tab strip, the dock or the status bar used to
            // be swallowed here without a word, which reads as the drag having
            // silently failed rather than having missed.
            $this->statusBar->flash($this->translator->trans('Drop on a folder to move, or on the editor to open'));

            return;
        }
        foreach ($drag->sources as $path) {
            // A folder has nothing to open; dropping one on the editor is a
            // miss, not an instruction.
            if (\is_dir($path)) {
                continue;
            }

            $this->tree->requestOpen($path);
        }
    }
}
