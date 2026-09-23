<?php

declare(strict_types=1);

namespace Porta\Workbench\Menu;

use Porta\Overlay\State\MenuOverlayController;
use Porta\Ui\BidiTui;
use Porta\Ui\Glyphs;
use Porta\Widget\ContextMenu;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\Render\PopupFrame;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Drops a select's option list, the browser's opened `<select>`, under the
 * control it belongs to.
 *
 * The list IS the context menu in its listbox mode: the floating overlay, the
 * modal key routing, click-to-pick, outside-dismiss, hover, wheel, the flip
 * above a control near the bottom edge and the right-to-left layout all come
 * with it. A popup drawn inside the host widget would be clipped to its pane
 * and would need every one of those again.
 *
 * Each option runs the host's own change path, so picking an option and
 * stepping to it are one write; the current option carries the check.
 */
final readonly class ListboxOpener
{
    public function __construct(
        private BidiTui $tui,
        private Renderer $renderer,
        private MenuBarWidget $menuBar,
        private MenuOverlayController $overlay,
        private ContextMenu $contextMenu,
    ) {}

    /**
     * Open under a control $host drew: $relRow is the row below the control,
     * $relAbove the control's first row (where an upward list ends), $relCol
     * and $width its columns — all $host-relative.
     *
     * A list of pairs, not a value => label map: PHP turns a numeric-string
     * key (an indent size, `'4'`) into an int, and the current option would
     * never match.
     *
     * @param list<array{string, string}> $options value and label, in list order
     * @param \Closure(string): void       $choose  runs with the picked value
     */
    public function openUnder(AbstractWidget $host, int $relRow, int $relCol, int $relAbove, int $width, array $options, string $current, \Closure $choose): void
    {
        $rect = $this->renderer->getWidgetRect($host);
        if (null === $rect) {
            return; // not drawn, so nothing to hang from
        }
        // Mirrored with its pane or not (a verbatim pane keeps its columns),
        // the control is found where the frame DREW it.
        $this->openAt($rect->row + $relRow, $rect->col + $relCol, $rect->row + $relAbove, $width, $options, $current, $choose, drawnOn: $rect->row + $relAbove);
    }

    /**
     * Open under a control at SCREEN coordinates — for a host that lays
     * itself out in screen space, as the floating modals do.
     *
     * $drawnOn is a screen row the control occupies, when the frame may have
     * reordered it right-to-left. Two mirrors are then in play, and neither is
     * the other: the control was reordered with the row it sits in (a
     * left-to-right control inside a mirrored pane keeps its own order, and
     * lands wherever the row's runs put it), while the floating list is its
     * own run, mirrored across the whole row (its shadow column is widened
     * to the LEFT under right-to-left, so the mirror leaves the box's edge
     * where it was). So the list is anchored where that second mirror carries it
     * onto the first: at the leftmost screen column the control was DRAWN at
     * ({@see BidiTui::logicalColumn()} inverted), mirrored back.
     *
     * @param list<array{string, string}> $options value and label, in list order
     * @param \Closure(string): void       $choose  runs with the picked value
     */
    public function openAt(int $row, int $col, int $above, int $width, array $options, string $current, \Closure $choose, ?int $drawnOn = null): void
    {
        $drawn = null === $drawnOn || !$this->tui->isMirrored() ? null : $this->drawnColumn($drawnOn, $col, $width);
        if (null !== $drawn) {
            $col = $this->tui->getTerminal()->getColumns() - $drawn - $width;
        }
        $items = [];
        $selected = 0;
        foreach ($options as $i => [$value, $label]) {
            $selected = $value === $current ? $i : $selected;
            $items[] = new MenuItem($label, $value === $current ? Glyphs::OPTION_CURRENT : '', static function () use ($choose, $value): void {
                $choose($value);
            });
        }
        $this->menuBar->close();
        $this->overlay->setContextAnchor($row, $col, $above);
        $this->contextMenu->openListbox($items, $selected, $width - PopupFrame::CHROME_COLS);
        $this->tui->requestRender();
    }

    /** The leftmost screen column on $row that shows one of the logical columns [$col, $col + $width), or null when none does. */
    private function drawnColumn(int $row, int $col, int $width): ?int
    {
        for ($visual = 0, $columns = $this->tui->getTerminal()->getColumns(); $visual < $columns; ++$visual) {
            $logical = $this->tui->logicalColumn($row, $visual);
            if ($logical >= $col && $logical < ($col + $width)) {
                return $visual;
            }
        }

        return null; // drawn nowhere on screen: there is nothing to meet
    }
}
