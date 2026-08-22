<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Overlay\State;

use PHPUnit\Framework\TestCase;
use Porta\Overlay\State\MenuOverlayController;
use Porta\Overlay\State\MenuOverlayPanes;
use Porta\Tests\Support\RendersRoot;
use Porta\Tests\Support\TestOverlayPane;
use Porta\Ui\BidiTui;
use Porta\Widget\ContextMenu;
use Porta\Widget\MenuBarWidget;
use Porta\Workbench\Menu\MenuItem;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class MenuOverlayControllerTest extends TestCase
{
    use RendersRoot;

    public function testRefreshContextMenuPositionsAndFramesTheDropdown(): void
    {
        $this->renderer = new Renderer(new StyleSheet());
        $this->tui = new BidiTui(
            terminal: new VirtualTerminal(columns: 100, rows: 24),
            keybindings: new Keybindings([]),
            renderer: $this->renderer,
        );
        $pane = new TestOverlayPane();
        $this->tui->add($pane);
        $this->frame();

        $menuBar = new MenuBarWidget();
        $contextMenu = new ContextMenu();
        $panes = new MenuOverlayPanes([$pane], static fn (): array => []);
        $controller = new MenuOverlayController($this->renderer, $menuBar, $contextMenu, $panes);

        // If the constructor's initStyles() call were removed, $this->styles
        // would be an uninitialized typed property, and the wrapMenu() call
        // inside refreshContextMenu() below would throw before ever reaching
        // the assertions — that failure IS the test for that mutant.
        $contextMenu->open([new MenuItem('Copy', 'Ctrl+C', null)]);
        $controller->setContextAnchor(5, 10);
        $controller->refreshContextMenu();

        $this->assertNotNull($controller->contextMenuDropdown());
        $rendered = implode("\n", $pane->render(new RenderContext(100, 24)));
        $this->assertStringContainsString('Copy', $rendered, 'the framed dropdown composited onto the pane');
    }
}
