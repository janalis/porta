<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Overlay\State;

use PHPUnit\Framework\TestCase;
use Porta\I18n\Translator;
use Porta\Overlay\Region\DropdownRegion;
use Porta\Overlay\State\MenuOverlayController;
use Porta\Overlay\State\MenuOverlayPanes;
use Porta\Tests\Support\Probe;
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
    /**
     * Mirrored, a menu claims its shadow to the LEFT, where it is cast before
     * the flip; at the screen's left edge there is no column to claim, and the
     * box keeps its own first column.
     */
    public function testAMirroredMenuClaimsItsShadowToTheLeft(): void
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
        $controller = new MenuOverlayController($this->renderer, new MenuBarWidget(), $contextMenu, new MenuOverlayPanes([$pane], static fn (): array => []), new Translator('he'));
        $contextMenu->open([new MenuItem('Copy', 'Ctrl+C', null)]);

        $controller->setContextAnchor(5, 10);
        $controller->refreshContextMenu();
        $box = $controller->contextMenuDropdown();
        $this->assertInstanceOf(DropdownRegion::class, $box);
        [$shadow] = $controller->regions();
        $this->assertSame([9, $box->width + 1, $box->count + 1], [$shadow->col, $shadow->width, $shadow->count], 'the side column left of the box, and the band');
        $this->assertSame([9, $box->width], $shadow->columnsOn($box->top + $box->count), 'the band row: the band alone');

        $controller->setContextAnchor(5, 0);
        $controller->refreshContextMenu();
        [$edge] = $controller->regions();
        $this->assertSame([0, $box->width], [$edge->col, $edge->width], 'flush left: the box alone, from its own first column');
    }

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

    /**
     * A right-click menu or a select's list too tall for either side of its
     * anchor takes the roomier side and scrolls there, instead of flipping
     * up past the screen's top; a select opened on an option far down its
     * list shows that option.
     */
    public function testAListTooTallForEitherSideScrollsOnTheRoomierOne(): void
    {
        $this->renderer = new Renderer(new StyleSheet());
        $this->tui = new BidiTui(terminal: new VirtualTerminal(columns: 100, rows: 24), keybindings: new Keybindings([]), renderer: $this->renderer);
        $pane = new TestOverlayPane();
        $this->tui->add($pane);
        $this->frame();
        $contextMenu = new ContextMenu();
        $controller = new MenuOverlayController($this->renderer, new MenuBarWidget(), $contextMenu, new MenuOverlayPanes([$pane], static fn (): array => []));
        $items = \array_map(static fn (int $i): MenuItem => new MenuItem("Item {$i}", '', null), \range(0, end: 29));
        $rows = static fn (): array => \array_map(AnsiUtils::stripAnsiCodes(...), $pane->render(new RenderContext(100, 24)));

        $contextMenu->open($items);
        $controller->setContextAnchor(5, 10);
        $controller->refreshContextMenu();
        $region = $controller->contextMenuDropdown();
        $this->assertSame([5, 19], [$region?->top, $region?->count], 'more room below row 5: down, to the screen\'s last row');
        $this->assertStringContainsString('▼', $rows()[23]);

        $controller->setContextAnchor(18, 10);
        $controller->refreshContextMenu();
        $region = $controller->contextMenuDropdown();
        $this->assertSame([0, 18], [$region?->top, $region?->count], 'more room above row 18: up, from the screen\'s top');

        $contextMenu->openListbox($items, 25, 10);
        $controller->setContextAnchor(5, 10);
        $controller->refreshContextMenu();
        $this->assertSame(9, $controller->contextMenuDropdown()?->first, 'the current option, 25, is the window\'s last row');
        $this->assertStringContainsString('▲', $rows()[5]);
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

    /**
     * A menu taller than the screen ends where the panes do: a window of its
     * items that follows the selection, ▲ and ▼ in the border where more run
     * on, and a click on a row picks the item that row shows.
     */
    public function testATallMenuScrollsWithinTheScreen(): void
    {
        $this->renderer = new Renderer(new StyleSheet());
        $this->tui = new BidiTui(terminal: new VirtualTerminal(columns: 30, rows: 8), keybindings: new Keybindings([]), renderer: $this->renderer);
        $menuBar = new MenuBarWidget();
        $menuBar->setMenus([new Menu('File', \array_map(static fn (int $i): MenuItem => new MenuItem("Item {$i}", '', null), \range(0, end: 9)))]);
        $pane = new TestOverlayPane();
        $this->tui->add($menuBar);
        $this->tui->add($pane);
        $this->frame(30, 8);
        $controller = new MenuOverlayController($this->renderer, $menuBar, new ContextMenu(), new MenuOverlayPanes([$pane], static fn (): array => []));
        // The pane's rect spans rows 1-8: the box gets eight rows, six items.
        $screen = static fn (): array => \array_map(AnsiUtils::stripAnsiCodes(...), $pane->render(new RenderContext(30, 8)));

        $menuBar->open(0);
        $controller->refreshMenu();
        $rows = $screen();
        $this->assertStringContainsString('Item 0', $rows[1]);
        $this->assertStringContainsString('Item 5', $rows[6]);
        $this->assertStringContainsString('▼', $rows[7], 'more below');
        $this->assertStringNotContainsString('▲', $rows[0], 'none above');
        $this->assertSame(8, $controller->menuDropdown()?->count, 'the box stops at the pane\'s last row');

        for ($i = 0; $i < 7; ++$i) {
            $menuBar->handleInput("\x1b[B");
        }
        $controller->refreshMenu();
        $rows = $screen();
        $this->assertStringContainsString('Item 7', $rows[6], 'the window followed the selection down, no further');
        $this->assertStringContainsString('Item 2', $rows[1]);
        $this->assertStringContainsString('▲', $rows[0], 'more above');
        $this->assertStringContainsString('▼', $rows[7], 'and below');
        $this->assertSame(2, $controller->menuDropdown()?->itemAt(2, 3), 'a click on the first item row picks the item it shows');
    }

    /**
     * An open menu follows a resize on the frame tick after it: the hook sees
     * the new screen's rects, lays the dropdown out again for them and asks
     * for the frame that shows it. Nothing to redo, it asks for nothing.
     */
    public function testAnOpenMenuIsLaidOutAgainWhenTheScreenChanges(): void
    {
        $this->renderer = new Renderer(new StyleSheet());
        $this->tui = new BidiTui(terminal: new VirtualTerminal(columns: 30, rows: 12), keybindings: new Keybindings([]), renderer: $this->renderer);
        $menuBar = new MenuBarWidget();
        $menuBar->setMenus([new Menu('File', \array_map(static fn (int $i): MenuItem => new MenuItem("Item {$i}", '', null), \range(0, end: 9)))]);
        $pane = new TestOverlayPane();
        $this->tui->add($menuBar);
        $this->tui->add($pane);
        $this->frame(30, 12);
        $contextMenu = new ContextMenu();
        $controller = new MenuOverlayController($this->renderer, $menuBar, $contextMenu, new MenuOverlayPanes([$pane], static fn (): array => []), frameHooks: $this->tui, tui: $this->tui);
        $pending = fn (): bool => (bool) Probe::get($this->tui, 'renderRequested');
        $this->tui->processRender(); // the frame adding the widgets asked for

        $this->frame(30, 8);
        $this->tui->processRender();
        $this->assertFalse($pending(), 'no menu open: a changed screen asks for nothing');
        $this->frame(30, 12);

        $menuBar->open(0);
        $controller->refreshMenu();
        $this->assertSame(12, $controller->menuDropdown()?->count, 'all ten items and the borders');

        $this->tui->processRender();
        $this->assertFalse($pending(), 'the same screen: nothing to redo, no frame asked for');

        $this->frame(30, 8);
        $this->tui->processRender();
        $this->assertSame(8, $controller->menuDropdown()?->count, 'laid out again for the shrunk screen');
        $this->assertTrue($pending(), 'and the frame that shows it asked for');

        // A right-click menu too: on the short screen it scrolls under its
        // anchor — there is no room above row 1 to flip into; grown back,
        // every item shows.
        $menuBar->close();
        $controller->refreshMenu();
        $contextMenu->open(\array_map(static fn (int $i): MenuItem => new MenuItem("Item {$i}", '', null), \range(0, end: 9)));
        $controller->setContextAnchor(1, 0);
        $controller->refreshContextMenu();
        $this->assertSame(8, $controller->contextMenuDropdown()?->count, 'rows 1-8 of the short screen');
        $this->frame(30, 12);
        $this->tui->processRender();
        $this->assertSame(12, $controller->contextMenuDropdown()?->count, 'all ten items on the grown one');
    }

    /**
     * The dropdown's top border is left open under its title, whose rails run
     * on into the frame — also when the screen's edge pushed the box left of
     * its title. A context menu hangs from no title, and stays closed.
     */
    public function testTheDropdownOpensUnderItsTitle(): void
    {
        $this->renderer = new Renderer(new StyleSheet());
        $this->tui = new BidiTui(terminal: new VirtualTerminal(columns: 24, rows: 12), keybindings: new Keybindings([]), renderer: $this->renderer);
        $menuBar = new MenuBarWidget();
        $menuBar->setMenus([new Menu('File', [new MenuItem('Save', '', null)]), new Menu('Help', [new MenuItem('About Porta', 'Ctrl+Shift+F1', null)])]);
        $pane = new TestOverlayPane();
        $contextMenu = new ContextMenu();
        $this->tui->add($menuBar);
        $this->tui->add($pane);
        $this->frame(24, 12);
        $controller = new MenuOverlayController($this->renderer, $menuBar, $contextMenu, new MenuOverlayPanes([$pane], static fn (): array => []));
        $topRow = static fn (): string => AnsiUtils::stripAnsiCodes($pane->render(new RenderContext(24, 11))[0]);

        $menuBar->open(0);
        $controller->refreshMenu();
        $this->assertStringStartsWith('┃    ┗', $topRow(), '┃File┃ opens into the box under it');

        // Help sits at columns 6-11, but its box is 24 wide: pushed flush left.
        $menuBar->open(1);
        $controller->refreshMenu();
        $this->assertSame('┏━━━━━┛    ┗━━━━━━━━━━━┓', $topRow());

        $menuBar->close();
        $controller->refreshMenu();
        $contextMenu->open([new MenuItem('Copy', '', null)]);
        $controller->setContextAnchor(1, 0); // the pane's first row
        $controller->refreshContextMenu();
        $this->assertStringStartsWith('┏━', $topRow(), 'a context menu keeps its closed border');
    }
}
