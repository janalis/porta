<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench\Menu;

use PHPUnit\Framework\TestCase;
use Porta\I18n\PaneReorder;
use Porta\Overlay\Region\FloatingRegions;
use Porta\Overlay\State\MenuOverlayController;
use Porta\Overlay\State\MenuOverlayPanes;
use Porta\Tests\Support\Probe;
use Porta\Ui\BidiTui;
use Porta\Ui\LtrPane;
use Porta\Ui\PaneSegmenter;
use Porta\Widget\ContextMenu;
use Porta\Widget\MenuBarWidget;
use Porta\Workbench\Menu\ListboxOpener;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * A select's list drops where its control is DRAWN: the floating list keeps
 * its own columns, so under a right-to-left reorder the control's column is
 * mirrored within its surface to meet it — unless the surface is verbatim.
 */
final class ListboxOpenerTest extends TestCase
{
    private BidiTui $tui;
    private Renderer $renderer;
    private ContextMenu $menu;
    private ListboxOpener $opener;

    protected function setUp(): void
    {
        $this->renderer = new Renderer(new StyleSheet());
        $this->tui = new BidiTui(terminal: new VirtualTerminal(columns: 60, rows: 12), keybindings: new Keybindings([]), renderer: $this->renderer);
        $menuBar = new MenuBarWidget();
        $this->menu = new ContextMenu();
        $this->opener = new ListboxOpener(
            $this->tui,
            $this->renderer,
            $menuBar,
            new MenuOverlayController($this->renderer, $menuBar, $this->menu, new MenuOverlayPanes([], static fn (): array => [])),
            $this->menu,
        );
    }

    private function host(AbstractWidget $pane): AbstractWidget
    {
        $root = Probe::object($this->tui, 'root');
        $this->assertInstanceOf(ContainerWidget::class, $root, 'the Tui root is a container');
        $root->clear();
        $this->tui->add($pane);
        $this->tui->requestRender(force: true);
        $this->tui->processRender(); // a real frame: the pane has a rect, 0..60, and the reorder its map

        return $pane;
    }

    private static function pane(): AbstractWidget
    {
        return new class extends AbstractWidget {
            public function render(RenderContext $context): array
            {
                return \array_fill(0, count: 12, value: \str_repeat(' ', $context->getColumns()));
            }
        };
    }

    /** @return int the column the context menu was anchored at */
    private function anchorAfterOpening(AbstractWidget $host): int
    {
        $picked = null;
        $this->opener->openUnder($host, 4, 10, 1, 20, [['a', 'Alpha'], ['b', 'Beta']], 'b', static function (string $value) use (&$picked): void {
            $picked = $value;
        });
        $this->assertTrue($this->menu->isListbox());
        $this->menu->handleInput("\r");
        $this->assertSame('b', $picked, 'the list opens on the current option');

        $col = Probe::get(Probe::object($this->opener, 'overlay'), 'contextAnchorCol');
        \assert(\is_int($col), description: 'the anchor column is an int');

        return $col;
    }

    public function testUnmirroredTheListHangsAtTheControlsColumn(): void
    {
        $this->assertSame(10, $this->anchorAfterOpening($this->host(self::pane())));
    }

    public function testMirroredTheListMeetsTheControlWhereItIsDrawn(): void
    {
        $this->tui->setPaneReorder(new PaneSegmenter($this->renderer, new class implements FloatingRegions {
            public function regions(): array
            {
                return [];
            }
        }), new PaneReorder());

        // A full-width pane: the row mirror that draws the control at 30-50
        // and the one that carries the list back are the same mirror, so they
        // cancel. (Where they differ — a pane beside a sidebar — the Hebrew
        // settings E2e holds the list under its select.)
        $this->assertSame(10, $this->anchorAfterOpening($this->host(self::pane())), 'drawn at 30-50, the list anchors where the row mirror brings it back to');

        $verbatim = new class extends AbstractWidget implements LtrPane {
            public function render(RenderContext $context): array
            {
                return \array_fill(0, count: 12, value: \str_repeat(' ', $context->getColumns()));
            }
        };
        $this->assertSame(30, $this->anchorAfterOpening($this->host($verbatim)), 'a verbatim pane draws the control at 10-30 unmirrored, and the list is mirrored: so it anchors at 60 - 10 - 20');
    }

    /** A control no screen column shows (past the frame's edge) keeps its own column: there is nothing to meet. */
    public function testAControlOffTheFrameKeepsItsColumn(): void
    {
        $this->tui->setPaneReorder(new PaneSegmenter($this->renderer, new class implements FloatingRegions {
            public function regions(): array
            {
                return [];
            }
        }), new PaneReorder());
        $this->host(self::pane());

        $this->opener->openAt(4, 100, 1, 20, [['a', 'Alpha']], 'a', static function (string $value): void {}, drawnOn: 1);

        $this->assertSame(100, Probe::get(Probe::object($this->opener, 'overlay'), 'contextAnchorCol'));
    }
}
