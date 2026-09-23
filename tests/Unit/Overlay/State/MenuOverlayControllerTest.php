<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Overlay\State;

use PHPUnit\Framework\TestCase;
use Porta\Overlay\Region\DropdownRegion;
use Porta\Overlay\State\MenuOverlayController;
use Porta\Overlay\State\MenuOverlayPanes;
use Porta\Tests\Support\RendersRoot;
use Porta\Tests\Support\TestOverlayPane;
use Porta\Ui\BidiTui;
use Porta\Widget\ContextMenu;
use Porta\Widget\MenuBarWidget;
use Porta\Workbench\Menu\Menu;
use Porta\Workbench\Menu\MenuItem;
use Symfony\Component\Tui\Ansi\AnsiUtils;
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

        $this->assertInstanceOf(DropdownRegion::class, $controller->contextMenuDropdown());
        $rendered = \implode("\n", $pane->render(new RenderContext(100, 24)));
        // Stripped: the item's access letter is underlined, which splits the word.
        $this->assertStringContainsString('Copy', AnsiUtils::stripAnsiCodes($rendered), 'the framed dropdown composited onto the pane');
    }

    /**
     * A select's list wears the heavy, focused frame — it holds the keys —
     * and a list that must flip up because the select sits near the bottom
     * ends above the select's top border instead of covering the select.
     */
    public function testAListboxIsHeavyFramedAndFlipsAboveItsControl(): void
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
        $contextMenu = new ContextMenu();
        $controller = new MenuOverlayController($this->renderer, new MenuBarWidget(), $contextMenu, new MenuOverlayPanes([$pane], static fn (): array => []));

        $contextMenu->openListbox([new MenuItem('dark', '', null), new MenuItem('light', '', null), new MenuItem('high', '', null)], 0, 20);
        $controller->setContextAnchor(23, 10, above: 20); // the select occupies rows 20-22
        $controller->refreshContextMenu();

        $region = $controller->contextMenuDropdown();
        $this->assertInstanceOf(DropdownRegion::class, $region);
        $this->assertSame(15, $region->top, 'five framed rows ending on row 19, just above the select');
        $rendered = \implode("\n", $pane->render(new RenderContext(100, 24)));
        $this->assertStringContainsString('┏', $rendered, 'the heavy frame');
        $this->assertStringContainsString('┃', $rendered);

        $contextMenu->open([new MenuItem('Copy', '', null)]);
        $controller->setContextAnchor(23, 10); // a right-click: no control to clear
        $controller->refreshContextMenu();
        $this->assertSame(20, $controller->contextMenuDropdown()?->top, 'a menu flips to end on its anchor row, as before');
        $this->assertStringContainsString('┏', \implode("\n", $pane->render(new RenderContext(100, 24))), 'a menu holds the keys too: heavy');
    }

    /** An armed bar (F10, nothing dropped) places no dropdown — not even an empty frame. */
    public function testAnArmedBarPlacesNoDropdown(): void
    {
        $this->renderer = new Renderer(new StyleSheet());
        $this->tui = new BidiTui(terminal: new VirtualTerminal(columns: 100, rows: 24), keybindings: new Keybindings([]), renderer: $this->renderer);
        $pane = new TestOverlayPane();
        $menuBar = new MenuBarWidget();
        $menuBar->setMenus([new Menu('File', [new MenuItem('Save', '', null)])]);
        $this->tui->add($menuBar);
        $this->tui->add($pane);
        $this->frame();
        $controller = new MenuOverlayController($this->renderer, $menuBar, new ContextMenu(), new MenuOverlayPanes([$pane], static fn (): array => []));

        $menuBar->arm();
        $controller->refreshMenu();
        $this->assertNull($controller->menuDropdown());

        $menuBar->open(0);
        $controller->refreshMenu();
        $this->assertInstanceOf(DropdownRegion::class, $controller->menuDropdown(), 'the control: dropped, it is placed');
    }
}
