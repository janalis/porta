<?php

declare(strict_types=1);

namespace Porta\Layout;

use Porta\Container\AsIdeService;
use Porta\I18n\PaneReorder;
use Porta\I18n\Translator;
use Porta\Ui\BidiTui;
use Porta\Ui\PaneSegmenter;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * The RTL-aware pane ordering for the horizontal areas (the editor's and
 * every view's): owns their canonical LTR pane lists, arranges each area's
 * children in the active writing direction, and re-mirrors everything on a
 * live locale switch (including the per-pane bidi reorder seam on BidiTui).
 */
#[AsIdeService]
final class PaneLayout
{
    /** @var list<AbstractWidget> */
    private readonly array $editorPanes;

    /** Auto-collapse state for narrow terminals — ephemeral, never persisted. */
    private bool $sidebarCollapsed = false;

    public function __construct(
        private readonly WorkspaceLayout $layout,
        private readonly Translator $translator,
        private readonly BidiTui $bidi,
        private readonly PaneSegmenter $segmenter,
        private readonly PaneReorder $reorder,
        private readonly ViewRegions $regions,
        private readonly PreviewSplit $previewSplit,
    ) {
        $this->editorPanes = $layout->editorPanes();
    }

    public function arrangeEditorArea(): void
    {
        $this->setPanes($this->layout->main, $this->currentEditorPanes());
    }

    /** Drop the file-tree sidebar from the editor area (narrow terminal). */
    public function collapseSidebar(): void
    {
        $this->sidebarCollapsed = true;
        $this->arrangeEditorArea();
    }

    /** Restore the file-tree sidebar (the terminal is wide enough again). */
    public function expandSidebar(): void
    {
        $this->sidebarCollapsed = false;
        $this->arrangeEditorArea();
    }

    /**
     * The editor area's canonical panes, minus the tree and its separator
     * while the sidebar is auto-collapsed.
     *
     * @return list<AbstractWidget>
     */
    private function currentEditorPanes(): array
    {
        return $this->sidebarCollapsed ? \array_slice($this->editorPanes, offset: 2) : $this->editorPanes;
    }

    /**
     * Order every minted view region's panes: left to right in every locale.
     * A view is drawn in a floating window, and a mirrored frame mirrors a
     * floating surface across the row — pane order included — where the
     * editor layout's panes keep their places and only their text reads the
     * other way. Read at call time rather than captured in the constructor: a
     * region exists once its view has minted one, and a plugin's view mints
     * after this service is built.
     */
    public function arrangeViewAreas(): void
    {
        foreach ($this->regions->all() as $region) {
            $region->area()->clear();
            foreach ($region->panes() as $pane) {
                $region->area()->add($pane);
            }
        }
    }

    /**
     * Mirror the horizontal layouts for the active writing direction: every
     * view's area, and the editor layout, which stays mounted under an open
     * view window.
     * A no-op until a view has minted a region — the boot-time applyAll()
     * fires before any view controller exists, and build time already ordered
     * the containers for the persisted locale.
     */
    public function applyDirection(): void
    {
        if ([] === $this->regions->all()) {
            return;
        }
        $this->translator->isRtl()
            ? $this->bidi->setPaneReorder($this->segmenter, $this->reorder)
            : $this->bidi->setPaneReorder(null, null);
        // A side tab strip is placed by child order, which is physical: tell the
        // workspace which way it reads so a right-hand strip moves to the far
        // left rather than sitting between the editor and the file tree.
        $this->layout->setMirrored($this->translator->isRtl());
        // The preview split mirrors like the workspace around it: the editor
        // on the leading side, its preview on the trailing one.
        $this->previewSplit->setMirrored($this->translator->isRtl());
        $this->arrangeViewAreas();
        $this->arrangeEditorArea();
    }

    /**
     * Replace a horizontal container's children with $ltr panes in visual order.
     *
     * @param list<AbstractWidget> $ltr
     */
    private function setPanes(ContainerWidget $container, array $ltr): void
    {
        $container->clear();
        foreach ($this->order($ltr) as $pane) {
            $container->add($pane);
        }
    }

    /**
     * @param list<AbstractWidget> $ltr
     *
     * @return list<AbstractWidget>
     */
    private function order(array $ltr): array
    {
        return $this->translator->isRtl() ? \array_reverse($ltr) : $ltr;
    }
}
