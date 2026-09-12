<?php

declare(strict_types=1);

namespace Porta\Layout;

use Porta\Container\AsIdeService;
use Porta\I18n\PaneReorder;
use Porta\I18n\Translator;
use Porta\Ui\BidiTui;
use Porta\Ui\PaneSegmenter;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * The RTL-aware pane ordering for the three horizontal areas (editor, git,
 * settings): owns their canonical LTR pane lists, arranges each area's
 * children in the active writing direction, and re-mirrors everything on a
 * live locale switch (including the per-pane bidi reorder seam on BidiTui).
 */
#[AsIdeService]
final class PaneLayout implements EditorAreaPanes
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
        private readonly ViewRegistry $views,
        private readonly ViewRegions $regions,
    ) {
        $this->editorPanes = $layout->editorPanes();
    }

    /**
     * The editor area's panes in visual order: reversed (sidebar on the right)
     * for RTL locales. The swapped-in views restore this order on close.
     *
     * @return list<AbstractWidget>
     */
    #[\Override]
    public function orderedEditorPanes(): array
    {
        return $this->order($this->currentEditorPanes());
    }

    public function arrangeEditorArea(): void
    {
        $this->setPanes($this->layout->main(), $this->currentEditorPanes());
    }

    /**
     * Drop the file-tree sidebar from the editor area (narrow terminal). The
     * swapped-in views pick the collapsed list up through
     * {@see orderedEditorPanes()} when they restore the editor area.
     */
    public function collapseSidebar(): void
    {
        $this->sidebarCollapsed = true;
        $this->rearrangeEditorAreaIfOwning();
    }

    /** Restore the file-tree sidebar (the terminal is wide enough again). */
    public function expandSidebar(): void
    {
        $this->sidebarCollapsed = false;
        $this->rearrangeEditorAreaIfOwning();
    }

    /** Re-arrange the main container only while the editor area owns it (not a swapped-in view). */
    private function rearrangeEditorAreaIfOwning(): void
    {
        if (!$this->views->isAnyOpen()) {
            $this->arrangeEditorArea();
        }
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
     * Order every minted view region's panes. Read at call time rather than
     * captured in the constructor: a region exists once its view has minted
     * one, and a plugin's view mints after this service is built.
     */
    public function arrangeViewAreas(): void
    {
        foreach ($this->regions->all() as $region) {
            $this->setPanes($region->area(), $region->panes());
        }
    }

    /**
     * Mirror the horizontal layouts for the active writing direction: the git
     * and settings areas always, and the editor layout when it currently owns
     * the main container (a swapped-in view restores in order when it closes).
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
        $this->arrangeViewAreas();
        if (!$this->views->isAnyOpen()) {
            $this->arrangeEditorArea();
        }
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
        return $this->translator->isRtl() ? array_reverse($ltr) : $ltr;
    }
}
