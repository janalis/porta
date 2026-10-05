<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Input;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferService;
use Porta\Editor\OpenFocus;
use Porta\Ide;
use Porta\Input\HalfRows;
use Porta\Input\MouseEvent;
use Porta\Layout\WorkspaceLayout;
use Porta\Tests\Support\BootsIde;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\Services;
use Porta\Ui\BidiTui;
use Porta\Ui\Theme\MonochromeTheme;
use Porta\Ui\Theme\Styles;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\Support\TabBarPosition;
use Porta\Widget\TabBarWidget;
use Porta\Workbench\ChromeInvalidator;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * A half row is the item's it is drawn for, in the real workbench: a gap's
 * upper half is the entry above, its lower half the entry below, and the
 * lower half of the rule over the tab strip is the strip's. Driven through a
 * booted Ide on a roomy screen, the terminal's answers fed in as input, so
 * the rects are the layout's and the pixel mode is the one the probe turns on.
 */
final class HalfRowsTest extends TestCase
{
    use BootsIde;
    use PreservesPath;

    /**
     * An Ide booted with no git and no language server on the PATH: a booted
     * Ide spawns one for a PHP file and, never run, never shuts it down.
     *
     * @param array<string, string> $files
     */
    private function bootQuietIde(array $files): Ide
    {
        \putenv('PATH=/nonexistent');

        return $this->bootIde($files);
    }

    private BidiTui $tui;

    protected function setUp(): void
    {
        $this->columns = 100;
        $this->rows = 40;
        $ide = $this->bootQuietIde(['composer.json' => '{}', 'a.php' => "<?php\n", 'b.php' => "<?php\n", 'c.php' => "<?php\n"]);
        $tui = Probe::object($ide, 'bidiTui');
        $this->assertInstanceOf(BidiTui::class, $tui);
        $this->tui = $tui;
    }

    private function frame(): void
    {
        $this->tui->requestRender(true);
        Probe::invoke($this->tui, 'processRender');
    }

    /** The terminal's answers: it knows mode 1016, and a cell is 10 × 20 pixels. */
    private function pixelsOn(): void
    {
        $this->tui->handleInput("\x1b[?1016;2\$y");
        $this->tui->handleInput("\x1b[6;20;10t");
    }

    /** A pixel hover report in screen cell ($col, $row), in its lower or upper half — resolved. */
    // @mago-expect lint:no-boolean-flag-parameter -- which half of the cell IS the report under test.
    private function at(int $col, int $row, bool $lower): MouseEvent
    {
        $mouse = MouseEvent::parse(\sprintf("\x1b[<35;%d;%dM", ($col * 10) + 3, ($row * 20) + ($lower ? 16 : 6)));
        $this->assertInstanceOf(MouseEvent::class, $mouse);

        return Services::resolve($this->ide, HalfRows::class)->resolve($mouse);
    }

    private function tabBar(): TabBarWidget
    {
        $bar = $this->tui->getById('tabs');
        $this->assertInstanceOf(TabBarWidget::class, $bar);

        return $bar;
    }

    private function rect(AbstractWidget $widget): WidgetRect
    {
        $rect = Services::resolve($this->ide, Renderer::class)->getWidgetRect($widget);
        $this->assertInstanceOf(WidgetRect::class, $rect);

        return $rect;
    }

    public function testAGapsHalvesAreTheTreeEntriesBesideIt(): void
    {
        $this->pixelsOn();
        $this->frame();
        $tree = $this->rect(Services::resolve($this->ide, FileTreeWidget::class));
        $col = $tree->col + 3;

        // Rows: the project name, a gap, a.php, a gap, b.php …
        $this->assertSame($tree->row + 4, $this->at($col, $tree->row + 3, lower: true)->row, 'the lower half of a gap is the entry below it');
        $this->assertSame($tree->row + 2, $this->at($col, $tree->row + 3, lower: false)->row, 'the upper half the entry above');
        $this->assertSame($tree->row + 2, $this->at($col, $tree->row + 2, lower: true)->row, 'an entry\'s own row stays its, either half');
        $this->assertSame($tree->row + 1, $this->at($col, $tree->row + 1, lower: false)->row, 'the gap under the name: its upper half is no entry\'s');
        $this->assertSame($tree->row + 2, $this->at($col, $tree->row + 1, lower: true)->row);
        $this->assertTrue($this->at($col, $tree->row + 3, lower: true)->lowerHalf, 'the event says which half it was in');

        // Through the dispatcher, as the terminal sends it: the pointer in the
        // gap's lower half hovers b.php, the entry under the gap.
        $this->tui->handleInput(\sprintf("\x1b[<35;%d;%dM", ($col * 10) + 3, (($tree->row + 3) * 20) + 16));
        $this->assertSame(1, Probe::get(Services::resolve($this->ide, FileTreeWidget::class), 'hoveredIndex'), 'b.php, the second entry');
    }

    public function testTheRuleOverTheTabStripIsTheStripsOnItsLowerHalf(): void
    {
        $this->pixelsOn();
        $this->frame();
        $tabs = $this->rect($this->tabBar());
        $col = $tabs->col + 3;

        $this->assertSame($tabs->row, $this->at($col, $tabs->row - 1, lower: true)->row, 'the lower half of the rule over the strip');
        $this->assertSame($tabs->row - 2, $this->at($col, $tabs->row - 1, lower: false)->row, 'its upper half the menu bar\'s, which it holds');
        $this->assertSame($tabs->row + 2, $this->at($col, $tabs->row + 2, lower: false)->row, 'the row under the baseline is not the strip\'s: the baseline is');
        $this->assertSame($tabs->row + 1, $this->at($col, $tabs->row + 1, lower: true)->row);
    }

    /** At the bottom the strip's outer side is under it: the rule over the status bar. */
    public function testABottomStripTakesTheUpperHalfOfTheRuleUnderIt(): void
    {
        Services::resolve($this->ide, WorkspaceLayout::class)->setTabBarPosition(TabBarPosition::Bottom);
        $this->pixelsOn();
        $this->frame();
        $tabs = $this->rect($this->tabBar());
        $col = $tabs->col + 3;
        $below = $tabs->row + $tabs->rows;

        $this->assertSame($below - 1, $this->at($col, $below, lower: false)->row, 'the upper half of the rule under the strip');
        $this->assertSame($below + 1, $this->at($col, $below, lower: true)->row, 'its lower half the status bar\'s, which it holds');
        $this->assertSame($tabs->row - 1, $this->at($col, $tabs->row - 1, lower: true)->row, 'over it the baseline is its own: the row above is not the strip\'s');
    }

    public function testASideStripsGapsAndTheRuleOverIt(): void
    {
        Services::resolve($this->ide, WorkspaceLayout::class)->setTabBarPosition(TabBarPosition::Left);
        $buffers = Services::resolve($this->ide, BufferService::class);
        $buffers->openFile($this->project->path('a.php'), OpenFocus::Keep);
        $buffers->openFile($this->project->path('b.php'), OpenFocus::Keep);
        $this->pixelsOn();
        $this->frame();
        $bar = $this->tabBar();
        $this->assertTrue($bar->position()->isSide());
        $tabs = $this->rect($bar);
        $col = $tabs->col + 3;

        $this->assertSame($tabs->row, $this->at($col, $tabs->row - 1, lower: true)->row, 'the rule over the first tab');
        $this->assertSame($tabs->row, $this->at($tabs->col, $tabs->row - 1, lower: true)->row, 'from the strip\'s first column');
        $this->assertSame($tabs->row, $this->at($tabs->col + $tabs->columns - 1, $tabs->row - 1, lower: true)->row, 'to its last');
        $this->assertSame($tabs->row - 1, $this->at($tabs->col - 1, $tabs->row - 1, lower: true)->row, 'not a column before it');
        $this->assertSame($tabs->row - 1, $this->at($tabs->col + $tabs->columns, $tabs->row - 1, lower: true)->row, 'nor after it');
        $this->assertSame($tabs->row, $this->at($col, $tabs->row + 1, lower: false)->row, 'the gap under it, upper half');
        $this->assertSame($tabs->row + 2, $this->at($col, $tabs->row + 1, lower: true)->row, 'its lower half: the next tab');
        $this->assertSame($tabs->row + 3, $this->at($col, $tabs->row + 3, lower: true)->row, 'under the last tab, the lower half is nobody\'s');
        $this->assertSame($tabs->row + 2, $this->at($col, $tabs->row + 3, lower: false)->row, 'the upper half is the last tab\'s');
    }

    /** A cell report cannot say, and a compact screen splits no row: either passes as it came. */
    public function testWithoutPixelsOrOnACompactScreenNothingMoves(): void
    {
        $this->frame();
        $tree = $this->rect(Services::resolve($this->ide, FileTreeWidget::class));

        $cell = $this->at($tree->col + 3, $tree->row + 3, lower: true);
        $this->assertNull($cell->lowerHalf, 'cell reports until the terminal has answered');

        $this->pixelsOn();
        $this->terminal->simulateResize(columns: 100, rows: 24);
        $this->frame();
        $tree = $this->rect(Services::resolve($this->ide, FileTreeWidget::class));
        $this->assertSame($tree->row + 3, $this->at($tree->col + 3, $tree->row + 3, lower: true)->row, 'compact: rows are whole');
    }

    /** With a view open the tree and the strip are behind it; the open view's nav takes the halves. */
    public function testAnOpenViewsNavTakesTheHalvesAndTheTreeNone(): void
    {
        $this->pixelsOn();
        $this->frame();
        $tree = $this->rect(Services::resolve($this->ide, FileTreeWidget::class));
        $tabs = $this->rect($this->tabBar());
        Services::resolve($this->ide, ViewRegistry::class)->open('settings');
        $this->frame();
        $this->frame(); // the window lays the view out on a frame of its own
        $nav = $this->rect($this->tui->getById('settings-nav') ?? $this->fail('no nav'));

        $this->assertSame($nav->row, $this->at($nav->col + 3, $nav->row - 1, lower: true)->row, 'the window\'s padding over the nav');
        $this->assertSame($nav->row, $this->at($nav->col + 3, $nav->row + 1, lower: false)->row, 'a gap\'s upper half: General');
        $this->assertSame($nav->row + 2, $this->at($nav->col + 3, $nav->row + 1, lower: true)->row, 'its lower half: the next');
        $this->assertSame(0, $this->at(1, 0, lower: true)->row, 'the menu bar\'s row is nobody\'s gap');
        $this->assertSame($tree->row + 3, $this->at(0, $tree->row + 3, lower: true)->row, 'nor the tree\'s, behind the window');
        $this->assertSame($tabs->row - 1, $this->at($tabs->col + 3, $tabs->row - 1, lower: true)->row, 'and the tab strip is out of reach too');
    }

    /**
     * A menu over the tree is what the pointer is over: its rows are whole,
     * and a gap behind one moves nothing. A menu hanging over the tree's gaps
     * lost every item on one to the row beside it.
     */
    public function testAMenuOverTheTreeKeepsItsRowsWhole(): void
    {
        $this->frame();
        $this->tui->handleInput("\x1b[<0;2;1M"); // File, as cells: pixels are not on yet
        $this->tui->handleInput("\x1b[<0;2;1m");
        $this->pixelsOn();
        $this->frame();
        $tree = $this->rect(Services::resolve($this->ide, FileTreeWidget::class));

        $this->assertSame($tree->row + 3, $this->at($tree->col + 3, $tree->row + 3, lower: true)->row, 'the menu\'s row, not the tree entry under its gap');
        $this->assertSame($tree->row + 3, $this->at($tree->col + 3, $tree->row + 3, lower: false)->row);
    }

    /**
     * The edge rows hold their bars' halves: the upper half of the row under
     * the menu bar is the bar's, the lower half of the one over the status
     * bar too — the other halves are not. A theme whose bars have no shade
     * draws a rule there, which no bar holds.
     */
    public function testTheEdgeRowsHalvesAreTheBars(): void
    {
        $this->pixelsOn();
        $this->frame();
        $layout = Services::resolve($this->ide, WorkspaceLayout::class);
        $top = $this->rect($layout->topRule)->row;
        $bottom = $this->rect($layout->bottomRule)->row;
        $col = $this->rect($layout->topRule)->col + 1;

        $this->assertSame($top - 1, $this->at($col, $top, lower: false)->row, 'the menu bar\'s half');
        $this->assertSame($bottom + 1, $this->at($col, $bottom, lower: true)->row, 'the status bar\'s');
        $this->assertSame($bottom, $this->at($col, $bottom, lower: false)->row, 'the upper half over the status bar is no bar\'s');

        // The theme every widget shares, as a theme switch swaps it.
        $styles = Probe::get($layout->topRule, 'styles');
        $this->assertInstanceOf(Styles::class, $styles);
        $styles->switchTo(MonochromeTheme::definition());
        Services::resolve($this->ide, ChromeInvalidator::class)->invalidateAll(); // the switch's sweep, which the edge rows are not in
        $this->frame();
        $this->assertSame($top, $this->at($col, $top, lower: false)->row, 'a rule holds no bar\'s half');
        $this->assertSame($bottom, $this->at($col, $bottom, lower: true)->row);
    }
}
