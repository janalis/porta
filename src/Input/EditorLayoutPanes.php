<?php

declare(strict_types=1);

namespace Porta\Input;

use Porta\Widget\BreadcrumbsWidget;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\EditorPaneWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\FindBarWidget;
use Porta\Widget\NavigablePaneWidget;
use Porta\Widget\TabBarWidget;
use Porta\Workbench\EditorArea;

/**
 * The persistent widgets of the main editor layout that {@see MouseRouter}
 * hit-tests: the file tree, the tab bar, the editor (or the image/help viewer
 * that replaces it on image/help tabs), the breadcrumb bar and the find bar.
 * Bundled (like {@see \Porta\Overlay\State\MenuOverlayPanes}) so the router takes
 * one argument instead of seven, without changing which pane a guard consults.
 */
final readonly class EditorLayoutPanes
{
    public function __construct(
        public FileTreeWidget $tree,
        public TabBarWidget $tabBar,
        public CodeEditorWidget $editor,
        public BreadcrumbsWidget $breadcrumbs,
        private EditorArea $editorArea,
        public FindBarWidget $findBar,
    ) {}

    /**
     * The pane on screen in the editor slot, or null when the code editor has
     * it alone. One question instead of three named getters: a router asks what
     * is showing rather than which of the built-in viewers it might be, so a
     * plugin's pane is routed to on the same path — and a preview beside the
     * editor is there to be clicked whichever of the two holds the keys.
     */
    public function pane(): ?EditorPaneWidget
    {
        return $this->editorArea->visiblePane();
    }

    /** The pane above, when it is one the pointer can pan and zoom. */
    public function navigablePane(): ?NavigablePaneWidget
    {
        $pane = $this->pane();

        return $pane instanceof NavigablePaneWidget ? $pane : null;
    }
}
