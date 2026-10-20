<?php

declare(strict_types=1);

namespace Porta\Layout;

use Porta\Widget\CodeEditorWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Workbench\Dock\BottomDock;
use Symfony\Component\Tui\Tui;

/**
 * The Shift+F11/F12 resize nudge for whichever resizable pane holds the
 * focus. Sidebars, the preview split's divider and a view split's move 2 columns per press,
 * bottom panels 1 row; a
 * non-resizable focus answers false so the chord falls through to the widget.
 */
final readonly class PaneResizer
{
    public function __construct(
        private Tui $tui,
        private LayoutController $layout,
        private FileTreeWidget $tree,
        private CodeEditorWidget $editor,
        private BottomDock $dock,
        private ViewRegions $regions,
        private PreviewSplit $previewSplit,
    ) {}

    public function canResize(): bool
    {
        return null !== $this->resizer(0);
    }

    public function resize(int $direction): void
    {
        if (null !== ($resize = $this->resizer($direction))) {
            $resize();
        }
    }

    /**
     * The view whose sidebar holds $focus, or null.
     *
     * Two hardcoded three-widget lists stood here, one per view core happened
     * to know. A region's sidebar IS the list — whatever the view stacked in
     * it — so a contributed view's panes answer the same question.
     */
    private function viewOwning(?object $focus): ?string
    {
        // No null guard: a sidebar's children are widgets, so a null focus
        // matches nothing and falls out of the loop on its own.
        foreach ($this->regions->all() as $region) {
            if (null !== $region->sidebarPanes() && \in_array($focus, $region->sidebar()->all(), strict: true)) {
                return $region->id;
            }
        }

        return null;
    }

    /**
     * The [view, split key] of the view split on screen, side by side, whose
     * column holds $focus, or null — a stacked split has no divider to move,
     * so the chord falls through. A split sits in the content, so it and the
     * sidebar never both answer.
     *
     * @return null|array{string, string}
     */
    private function splitOwning(?object $focus): ?array
    {
        foreach ($this->regions->all() as $region) {
            foreach ($region->splits() as $key => $split) {
                if (null !== $split->rect() && !$split->isNarrow() && null !== $focus && ($focus === $split->lead() || $focus === $split->rest())) {
                    return [$region->id, $key];
                }
            }
        }

        return null;
    }

    /**
     * Whether $focus is one half of a preview split on screen: the editor or
     * the pane beside it. The split's area is attached only in Split, so the
     * editor alone — or a lone preview — resizes nothing.
     */
    private function inPreviewSplit(?object $focus): bool
    {
        $pane = $this->previewSplit->preview();

        if (null === $pane || null === $this->previewSplit->area->getContext()) {
            return false;
        }

        return null !== $focus && ($focus === $pane || $focus === $this->editor);
    }

    private function resizer(int $direction): ?\Closure
    {
        $focus = $this->tui->getFocus();

        return match (true) {
            $focus === $this->tree => fn () => $this->layout->nudgeSidebar(2 * $direction),
            null !== $focus && $focus === $this->dock->visiblePanel() => fn () => $this->layout->nudgeBottomPanel($direction),
            null !== ($view = $this->viewOwning($focus)) => fn () => $this->layout->nudgeViewSidebar($view, 2 * $direction),
            null !== ($split = $this->splitOwning($focus)) => fn () => $this->layout->nudgeViewSplit($split[0], $split[1], 2 * $direction),
            $this->inPreviewSplit($focus) => fn () => $this->layout->nudgePreviewSplit(2 * $direction),
            default => null,
        };
    }
}
