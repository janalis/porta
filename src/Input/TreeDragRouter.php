<?php

declare(strict_types=1);

namespace Porta\Input;

use Porta\Container\AsIdeService;
use Porta\Tree\TreeDragState;
use Porta\Widget\FileTreeWidget;
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
 * {@see \Porta\Workbench\Onboarding\WelcomeMouseRouter}'s: one `continueDrag()`
 * in the router's `||` chain, one `cancel()` in its fresh-press sweep.
 */
#[AsIdeService]
final class TreeDragRouter
{
    private ?TreeDragState $drag = null;

    public function __construct(
        private readonly FileTreeWidget $tree,
        private readonly Tui $tui,
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
        if (null === $drag || !$mouse->isLeftDrag() && !$mouse->isLeftRelease()) {
            return false;
        }
        $drag->moved = $drag->moved || $mouse->isLeftDrag();
        $drag->target = $this->dropDir($mouse, $treeRect);
        $this->tree->setDropTarget($drag->target);
        if ($mouse->isLeftRelease()) {
            $this->disarm();
            // A press and release on one spot is a CLICK: pay the toggle the
            // press deferred, and move nothing.
            $drag->moved ? $this->drop($drag, $mouse, $editorRect) : $this->tree->commitPress();
        }
        $this->tui->requestRender();

        return true;
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
     */
    private function disarm(): void
    {
        $this->drag = null;
        $this->tree->setDropTarget(null);
    }

    private function dropDir(MouseEvent $mouse, ?WidgetRect $treeRect): ?string
    {
        return null !== $treeRect && $treeRect->contains($mouse->row, $mouse->col)
            ? $this->tree->dropDirAt($treeRect->toRelative($mouse->row, $mouse->col)['row'])
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
            return;
        }
        foreach ($drag->sources as $path) {
            // A folder has nothing to open; dropping one on the editor is a
            // miss, not an instruction.
            if (is_dir($path)) {
                continue;
            }

            $this->tree->requestOpen($path);
        }
    }
}
