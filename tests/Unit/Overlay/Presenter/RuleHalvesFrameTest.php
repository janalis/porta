<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Overlay\Presenter;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferService;
use Porta\Editor\OpenFocus;
use Porta\Ide;
use Porta\Input\HalfRows;
use Porta\Layout\PreviewSplit;
use Porta\Layout\WorkspaceLayout;
use Porta\Overlay\Presenter\RuleHalves;
use Porta\Support\AnsiText;
use Porta\Tests\Support\BootsIde;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\Services;
use Porta\Tree\FileTreeNode;
use Porta\Ui\BidiTui;
use Porta\Widget\BreadcrumbsWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\MarkdownPageWidget;
use Porta\Widget\Support\TabBarPosition;
use Porta\Workbench\EditorArea;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The halves in the real frame: the shown side tab's in the rule over the
 * strip, and — with the settings view open — General's in the window's
 * padding, while the tab strip's go on outside the window. And, read as the
 * frame join hands it to the terminal: a markdown file in Split, where the
 * navigation bar reaches up into the menu bar's half, the preview's header
 * into the navigation bar's, the split's divider stands on the header's
 * shade; and the tree's last row reaching into the status bar's half.
 */
final class RuleHalvesFrameTest extends TestCase
{
    use BootsIde;
    use PreservesPath;

    private BidiTui $tui;

    /** @var list<string> */
    private array $frame = [];

    private function boot(int $rows): void
    {
        $this->columns = 120;
        $this->rows = $rows;
        $files = ['composer.json' => '{}', 'README.md' => "# Read me\n\nText.\n"];
        foreach (\range(1, end: 20) as $n) {
            $files[\sprintf('f%02d.txt', $n)] = "x\n";
        }
        $ide = $this->bootQuietIde($files);
        $tui = Probe::object($ide, 'bidiTui');
        $this->assertInstanceOf(BidiTui::class, $tui);
        $this->tui = $tui;
        $join = Probe::get($tui, 'frameJoin');
        $this->assertInstanceOf(\Closure::class, $join);
        $tui->setFrameJoin(function (array $lines) use ($join): array {
            $joined = $join($lines);
            $this->assertIsArray($joined);

            return $this->frame = \array_values(\array_map(\strval(...), $joined));
        });
    }

    private function frame(): void
    {
        $this->tui->requestRender(true);
        Probe::invoke($this->tui, 'processRender');
    }

    private function rect(AbstractWidget $widget): WidgetRect
    {
        $rect = Services::resolve($this->ide, Renderer::class)->getWidgetRect($widget);
        $this->assertInstanceOf(WidgetRect::class, $rect);

        return $rect;
    }

    private function cell(int $row, int $col): string
    {
        return AnsiText::columnsFrom($this->frame[$row] ?? '', $col, 1);
    }

    private function ide(): Ide
    {
        return $this->ide;
    }

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

    /** @return list<string> the frame as composed, plain */
    private function plainFrame(BidiTui $tui): array
    {
        $tui->requestRender(true);
        Probe::invoke($tui, 'processRender');
        $frame = Probe::get($this->ide, 'lastFrame');
        $this->assertIsArray($frame);

        return $frame;
    }

    public function testTheShownTabsAndTheOpenNavsHalvesAreDrawn(): void
    {
        $this->columns = 100;
        $this->rows = 40;
        $ide = $this->bootQuietIde(['composer.json' => '{}', 'a.php' => "<?php\n"]);
        $tui = Probe::object($ide, 'bidiTui');
        $this->assertInstanceOf(BidiTui::class, $tui);
        Services::resolve($ide, WorkspaceLayout::class)->setTabBarPosition(TabBarPosition::Right);
        Services::resolve($ide, BufferService::class)->openFile($this->project->path('a.php'), OpenFocus::Editor);
        $renderer = Services::resolve($ide, Renderer::class);

        $frame = $this->plainFrame($tui);
        $tabs = $renderer->getWidgetRect($tui->getById('tabs') ?? $this->fail('no tabs'));
        $this->assertInstanceOf(WidgetRect::class, $tabs);
        $over = \mb_substr($frame[$tabs->row - 1], $tabs->col, $tabs->columns);
        $this->assertSame(\str_repeat('▄', $tabs->columns), $over, 'the shown tab\'s upper half, its rule\'s column too: the tab is open');

        Services::resolve($ide, ViewRegistry::class)->open('settings');
        $this->plainFrame($tui);
        $frame = $this->plainFrame($tui);
        $nav = $renderer->getWidgetRect($tui->getById('settings-nav') ?? $this->fail('no nav'));
        $this->assertInstanceOf(WidgetRect::class, $nav);
        $this->assertStringContainsString('▄', \mb_substr($frame[$nav->row - 1], $nav->col, $nav->columns), 'General\'s upper half in the window\'s padding');
        $this->assertSame($over, \mb_substr($frame[$tabs->row - 1], $tabs->col, $tabs->columns), 'and the strip\'s still over it, outside the window');
    }

    /** On top, the tree's divider opens beside the shown first tab: blank on its row, its halves over and under. */
    public function testATopStripOpensTheDividerBesideItsShownFirstTab(): void
    {
        $this->columns = 100;
        $this->rows = 40;
        $ide = $this->bootQuietIde(['composer.json' => '{}', 'a.php' => "<?php\n"]);
        $tui = Probe::object($ide, 'bidiTui');
        $this->assertInstanceOf(BidiTui::class, $tui);
        Services::resolve($ide, BufferService::class)->openFile($this->project->path('a.php'), OpenFocus::Editor);

        $frame = $this->plainFrame($tui);
        $tabs = Services::resolve($ide, Renderer::class)->getWidgetRect($tui->getById('tabs') ?? $this->fail('no tabs'));
        $this->assertInstanceOf(WidgetRect::class, $tabs);
        $divider = $tabs->col - 1;

        $this->assertSame(['▄', ' ', '▀'], [
            \mb_substr($frame[$tabs->row - 1], $divider, length: 1),
            \mb_substr($frame[$tabs->row], $divider, length: 1),
            \mb_substr($frame[$tabs->row + 1], $divider, length: 1),
        ]);
        $this->assertSame('│', \mb_substr($frame[$tabs->row + 2], $divider, length: 1), 'the divider runs on under the strip');
    }

    public function testTheBarsReachIntoTheRowsBesideThem(): void
    {
        $this->boot(rows: 40);
        Services::resolve($this->ide(), WorkspaceLayout::class)->setTabBarPosition(TabBarPosition::Right);
        Services::resolve($this->ide(), BufferService::class)->openFile($this->project->path('README.md'), OpenFocus::Keep);
        $this->frame();
        $this->frame();
        $bar = $this->tui->getById('breadcrumbs');
        $this->assertInstanceOf(BreadcrumbsWidget::class, $bar);
        $crumbs = $this->rect($bar);
        $page = Services::resolve($this->ide(), EditorArea::class)->visiblePane();
        $this->assertInstanceOf(MarkdownPageWidget::class, $page);
        $header = $this->rect($page);
        $divider = $this->rect(Services::resolve($this->ide(), PreviewSplit::class)->separator);

        $this->assertStringContainsString("\x1b[38;5;235;48;5;237m▄", $this->cell($crumbs->row - 1, $crumbs->col + 2), 'the navigation bar under the menu bar\'s half');
        $this->assertStringContainsString("\x1b[38;5;237;48;5;235m▄", $this->cell($header->row - 1, $header->col + 2), 'the header under the navigation bar\'s half');
        $this->assertSame('▀', AnsiUtils::stripAnsiCodes($this->cell($header->row - 1, $crumbs->col + 2)), 'over the text, the bar\'s half alone');
        $this->assertStringContainsString("\x1b[38;5;238;48;5;237m│", $this->cell($header->row, $divider->col), 'the divider on the header\'s shade');
    }

    public function testTheTreesLastRowReachesIntoTheStatusBarsHalf(): void
    {
        $this->boot(rows: 41); // an odd height: the tree's last row is an entry, not a gap
        $this->frame();
        $tree = Services::resolve($this->ide(), FileTreeWidget::class);
        $rect = $this->rect($tree);
        $last = $tree->nodeAtRow($rect->rows - 1);
        $this->assertInstanceOf(FileTreeNode::class, $last, 'an entry on the tree\'s last row');
        $tree->revealPath($last->path);
        $this->frame();
        $this->assertSame($last->path, $tree->nodeAtRow($rect->rows - 1)?->path, 'still the last row, selected');

        $edge = $this->cell($rect->row + $rect->rows, $rect->col + 3);
        $this->assertSame('▀', AnsiUtils::stripAnsiCodes($edge));
        $this->assertStringContainsString(';48;5;237m', $edge, 'the selection\'s half on the status bar\'s');
        $this->assertSame('▄', AnsiUtils::stripAnsiCodes($this->cell($rect->row + $rect->rows, $rect->col + $rect->columns + 3)), 'past the tree, the bar\'s half alone');
    }

    /**
     * Under a top strip the shown tab's lower half stands in the baseline
     * over the navigation bar: the strip's, in the tab's colour — the bar
     * reaches into no pill there, so it grows no bump under one tab.
     */
    public function testUnderATopStripTheBarReachesIntoNoPill(): void
    {
        $this->boot(rows: 40);
        Services::resolve($this->ide(), BufferService::class)->openFile($this->project->path('README.md'), OpenFocus::Editor);
        $this->frame();
        $this->frame();
        $bar = $this->tui->getById('breadcrumbs');
        $this->assertInstanceOf(BreadcrumbsWidget::class, $bar);
        $crumbs = $this->rect($bar);
        $tabs = $this->rect($this->tui->getById('tabs') ?? $this->fail('no tabs'));
        $this->assertSame($tabs->row + $tabs->rows, $crumbs->row, 'the bar right under the strip');

        $pill = $this->cell($crumbs->row - 1, $tabs->col + 2);
        $this->assertSame('▀', AnsiUtils::stripAnsiCodes($pill), 'the shown tab\'s lower half');
        $this->assertStringNotContainsString('48;5;235', $pill, 'on nothing of the bar\'s');
    }

    /** Under an open view's window nothing of the editor's reaches: no head bar, no half drawn into the window. */
    public function testAnOpenViewHasNoHeads(): void
    {
        $this->boot(rows: 40);
        Services::resolve($this->ide(), BufferService::class)->openFile($this->project->path('README.md'), OpenFocus::Keep);
        $halves = Probe::get(Services::resolve($this->ide(), HalfRows::class), 'halves');
        $this->assertInstanceOf(RuleHalves::class, $halves);
        $this->assertCount(2, $halves->headWidgets(), 'the bar and the page header');

        Services::resolve($this->ide(), ViewRegistry::class)->open('settings');
        $this->assertSame([], $halves->headWidgets());
    }
}
