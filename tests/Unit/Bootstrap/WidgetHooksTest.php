<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Bootstrap;

use PHPUnit\Framework\TestCase;
use Porta\Layout\WorkspaceLayout;
use Porta\Tests\Support\BootsIde;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\Services;
use Porta\Widget\BreadcrumbsWidget;
use Porta\Widget\ContextMenu;
use Porta\Widget\HelpViewerWidget;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\TerminalPanelWidget;
use Porta\Workbench\Dock\BottomDock;
use Symfony\Component\Tui\Tui;

/**
 * WidgetHooks::wire() is pure imperative wiring (18 sequential calls handing
 * each widget its runtime collaborator) — every assertion here checks one
 * call's effect via the private property it sets, so a removed call is
 * individually distinguishable from its neighbors.
 */
final class WidgetHooksTest extends TestCase
{
    use BootsIde;

    protected function setUp(): void
    {
        $this->bootIde(['composer.json' => '{}']);
    }

    public function testEditorGetsARealWiredCallbacksInstanceNotItsBarePlaceholderDefault(): void
    {
        // CodeEditorWidget constructs itself with an all-null EditorCallbacks
        // as a placeholder default — every group here being non-null is only
        // possible if WidgetHooks::wire()'s setCallbacks() call actually ran.
        $editor = Services::resolve($this->ide, Tui::class)->getById('editor');
        $callbacks = Probe::get($editor, 'callbacks');

        $this->assertNotNull($callbacks->navigation);
        $this->assertNotNull($callbacks->file);
        $this->assertNotNull($callbacks->completion);
        $this->assertNotNull($callbacks->refactor);
        $this->assertNotNull($callbacks->buffer);
    }

    public function testHelpViewerGetsBothItsDocsRootAndItsHandler(): void
    {
        $helpViewer = Services::resolve($this->ide, HelpViewerWidget::class);

        $this->assertNotNull(Probe::get($helpViewer, 'docsRoot'));
        $this->assertNotNull(Probe::get($helpViewer, 'moreRoots'));
        $this->assertNotNull(Probe::get($helpViewer, 'handler'));
    }

    public function testTabBreadcrumbAndStatusStripsEachGetAContentProvider(): void
    {
        $layout = Services::resolve($this->ide, WorkspaceLayout::class);
        $tabBar = Probe::get($layout, 'tabBar');
        $editorBody = Probe::get($layout, 'editorBody');
        $breadcrumbs = \array_find($editorBody->all(), static fn (object $w): bool => $w instanceof BreadcrumbsWidget);
        $this->assertInstanceOf(BreadcrumbsWidget::class, $breadcrumbs, 'the editor body holds the breadcrumb strip');
        $statusBar = Services::resolve($this->ide, StatusBarWidget::class);

        $this->assertIsCallable(Probe::get($tabBar, 'provider'));
        $this->assertIsCallable(Probe::get($breadcrumbs, 'provider'));
        $this->assertIsCallable(Probe::get($statusBar, 'provider'));
    }

    public function testDockPanelsAreWiredWithATabsProvider(): void
    {
        // BottomDock::wire() (called from WidgetHooks) is what gives the
        // dock's tab strip its provider — the single cheapest observable
        // proof the whole wire() call ran, short of a full render.
        $panels = Services::resolve($this->ide, BottomDock::class);
        $dockTabsWidget = Probe::get($panels, 'dockTabs');

        $this->assertIsCallable(Probe::get($dockTabsWidget, 'provider'));
    }

    public function testTerminalPanelForwardsInputToTheRunController(): void
    {
        $terminalPanel = Services::resolve($this->ide, TerminalPanelWidget::class);

        $this->assertIsCallable(Probe::get($terminalPanel, 'onInputForward'));
    }

    public function testMenuBarAndContextMenuBothGetNavigateAndCloseHooks(): void
    {
        $menuBar = Services::resolve($this->ide, MenuBarWidget::class);
        $contextMenu = Services::resolve($this->ide, ContextMenu::class);

        $this->assertIsCallable(Probe::get($menuBar, 'onNavigate'));
        $this->assertNotEmpty(Probe::get($menuBar, 'onClose'), 'menu bar close hook registered');
        $this->assertIsCallable(Probe::get($contextMenu, 'onNavigate'));
        $this->assertNotEmpty(Probe::get($contextMenu, 'onClose'), 'context menu close hook registered');
    }
}
