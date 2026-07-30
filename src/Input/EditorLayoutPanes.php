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
        private FileTreeWidget $tree,
        private TabBarWidget $tabBar,
        private CodeEditorWidget $editor,
        private BreadcrumbsWidget $breadcrumbs,
        private EditorArea $editorArea,
        private FindBarWidget $findBar,
    ) {}

    public function findBar(): FindBarWidget
    {
        return $this->findBar;
    }

    /**
     * The pane currently holding the editor slot, or null when the code editor
     * has it. One question instead of three named getters: a router asks what is
     * showing rather than which of the built-in viewers it might be, so a
     * plugin's pane is routed to on the same path.
     */
    public function pane(): ?EditorPaneWidget
    {
        $widget = $this->editorArea->widget();

        return $widget instanceof EditorPaneWidget ? $widget : null;
    }

    /** The pane above, when it is one the pointer can pan and zoom. */
    public function navigablePane(): ?NavigablePaneWidget
    {
        $pane = $this->pane();

        return $pane instanceof NavigablePaneWidget ? $pane : null;
    }

    public function tree(): FileTreeWidget
    {
        return $this->tree;
    }

    public function tabBar(): TabBarWidget
    {
        return $this->tabBar;
    }

    public function editor(): CodeEditorWidget
    {
        return $this->editor;
    }

    public function breadcrumbs(): BreadcrumbsWidget
    {
        return $this->breadcrumbs;
    }
}
