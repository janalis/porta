<?php

declare(strict_types=1);

namespace Porta\Input;

use Porta\Widget\BreadcrumbsWidget;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\HelpViewerWidget;
use Porta\Widget\ImageViewerWidget;
use Porta\Widget\TabBarWidget;

/**
 * The persistent widgets of the main editor layout that {@see MouseRouter}
 * hit-tests: the file tree, the tab bar, the editor (or the image/help viewer
 * that replaces it on image/help tabs) and the breadcrumb bar. Bundled (like
 * {@see \Porta\Overlay\MenuOverlayPanes}) so the router takes one argument
 * instead of six, without changing which pane a guard consults.
 */
final readonly class EditorLayoutPanes
{
    public function __construct(
        private FileTreeWidget $tree,
        private TabBarWidget $tabBar,
        private CodeEditorWidget $editor,
        private BreadcrumbsWidget $breadcrumbs,
        private ImageViewerWidget $imageViewer,
        private HelpViewerWidget $helpViewer,
    ) {}

    public function imageViewer(): ImageViewerWidget
    {
        return $this->imageViewer;
    }

    public function helpViewer(): HelpViewerWidget
    {
        return $this->helpViewer;
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
