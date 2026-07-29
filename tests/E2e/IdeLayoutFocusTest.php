<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Ui\Glyphs;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\Renderer;

/**
 * Layout persistence and the focus ring: pane resizes by mouse
 * and keyboard, saved proportions, F6 traversal.
 * Split out of the oversized IdeEdgeCasesTest.
 */
final class IdeLayoutFocusTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;

    public function testMouseDragResizesSidebarAndPersists(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui): void {
                    $this->assertSame(30, $tui->getById('tree')->width());
                    // Force a frame so the separator has a live rect for the arm.
                    $this->frame($tui);
                    // Drag the tree↔editor gap (SGR col 31 = the gap column) out to col 44.
                    $tui->handleInput("\x1b[<0;31;5M"); // arm on the gap
                    $tui->handleInput("\x1b[<32;45;5M"); // drag to col 44
                    $tui->handleInput("\x1b[<0;45;5m"); // release
                },
                320 => function ($tui) use ($project): void {
                    $this->assertSame(44, $tui->getById('tree')->width(), 'sidebar resized by the drag');
                    $layout = json_decode((string) file_get_contents($project->path('.porta/layout.json')), associative: true);
                    $this->assertSame(28, $layout['sidebarWidthPercent'], 'saved as a percentage (round(44/160))');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testMouseDragResizesBottomPanelAndPersists(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => static function ($tui, $terminal, $ide): void {
                    $tp = $tui->getById('terminal');
                    $tp->open('sh', new \Porta\Terminal\TerminalBuffer());
                    $tui->requestRender();
                    $tui->tick();
                    $renderer = Services::resolve($ide, Renderer::class);
                    $rect = $renderer->getWidgetRect($tp);
                    $top = $rect->row + 1; // 1-based screen row of the panel's top edge
                    // Arm past the tab strip's tabs (the tail of the top row is
                    // the resize grab; a tab hit would select instead).
                    $grabCol = $rect->col + $rect->columns - 5;
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $grabCol, $top)); // arm on the top edge
                    $tui->handleInput(\sprintf("\x1b[<32;%d;%dM", $grabCol, $top - 4)); // drag the edge up 4 rows
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $grabCol, $top - 4)); // release
                },
                320 => function ($tui) use ($project): void {
                    $this->assertSame(16, Probe::get($tui->getById('terminal'), 'visibleRows'), 'panel grew by the drag (clamped at rows-8)');
                    $layout = json_decode((string) file_get_contents($project->path('.porta/layout.json')), associative: true);
                    $this->assertSame(67, $layout['bottomPanelHeightPercent'], 'saved as a percentage (round(16/24))');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testMouseDragResizesResultsPaneAndPersists(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);
        $expected = 0;

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => static function ($tui, $terminal, $ide) use (&$expected): void {
                    $results = $tui->getById('results');
                    Services::resolve($ide, \Porta\Workbench\Dock\BottomDock::class)
                        ->showList(\Porta\Workbench\Dock\ResultsPanelMode::Search, 'Find in Project', [
                            ['path' => 'b.php', 'line' => 0, 'text' => 'one'],
                            ['path' => 'c.php', 'line' => 0, 'text' => 'two'],
                        ]);
                    $tui->requestRender();
                    $tui->tick(); // force a frame so the pane gets a positioned rect
                    $renderer = Services::resolve($ide, Renderer::class);
                    $rect = $renderer->getWidgetRect($results);
                    $expected = $rect->rows + 4;
                    $top = $rect->row; // 1-based screen row of the dock strip — the resize grab
                    $tui->handleInput(\sprintf("\x1b[<0;60;%dM", $top)); // arm on the strip's empty tail
                    $tui->handleInput(\sprintf("\x1b[<32;60;%dM", $top - 4)); // drag the edge up 4 rows
                    $tui->handleInput(\sprintf("\x1b[<0;60;%dm", $top - 4)); // release
                },
                320 => function ($tui) use ($project, &$expected): void {
                    $this->assertSame($expected, Probe::get($tui->getById('results'), 'visibleRows'), 'the results pane grew by the drag');
                    $this->assertSame($expected, Probe::get($tui->getById('terminal'), 'visibleRows'), 'shared bottom-panel height stays in sync');
                    $layout = json_decode((string) file_get_contents($project->path('.porta/layout.json')), associative: true);
                    $this->assertSame((int) round(($expected * 100) / 24), $layout['bottomPanelHeightPercent'], 'saved as a percentage of the 24 rows');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testMouseDragResizesAnEmptyResultsPane(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);
        $expected = 0;

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => static function ($tui, $terminal, $ide) use (&$expected): void {
                    // An empty tool (no results): it used to render no rows, so the
                    // dock had no rect to grab. It now reserves its full height.
                    Services::resolve($ide, \Porta\Workbench\Dock\BottomDock::class)
                        ->showList(\Porta\Workbench\Dock\ResultsPanelMode::Search, 'Find in Project', []);
                    $tui->requestRender();
                    $tui->tick();
                    $renderer = Services::resolve($ide, Renderer::class);
                    $rect = $renderer->getWidgetRect($tui->getById('results'));
                    $expected = $rect->rows + 4;
                    $top = $rect->row; // the dock strip row doubles as the resize grab
                    $tui->handleInput(\sprintf("\x1b[<0;60;%dM", $top)); // arm on the strip's empty tail
                    $tui->handleInput(\sprintf("\x1b[<32;60;%dM", $top - 4)); // drag up 4 rows
                    $tui->handleInput(\sprintf("\x1b[<0;60;%dm", $top - 4)); // release
                },
                320 => function ($tui) use (&$expected): void {
                    $this->assertSame($expected, Probe::get($tui->getById('results'), 'visibleRows'), 'the empty results pane is resizable');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testSavedLayoutIsAppliedOnStartup(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);
        if (!is_dir($project->path('.porta'))) {
            mkdir($project->path('.porta'), permissions: 0o777, recursive: true);
        }
        file_put_contents(
            $project->path('.porta/layout.json'),
            json_encode(['sidebarWidthPercent' => 25, 'bottomPanelHeightPercent' => 40]),
        );

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui): void {
                    $this->assertSame(40, $tui->getById('tree')->width(), '25% of 160 columns');
                    $this->assertSame(10, Probe::get($tui->getById('terminal'), 'visibleRows'), '40% of 24 rows');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testGapClickWithoutDragDoesNotResizeOrPersist(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui) use ($project): void {
                    $tui->handleInput("\x1b[<0;31;5M"); // press the gap
                    $tui->handleInput("\x1b[<0;31;5m"); // release without moving
                    $this->assertSame(30, $tui->getById('tree')->width(), 'width unchanged');
                    $this->assertFileDoesNotExist($project->path('.porta/layout.json'), 'no file written for a no-op');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testThePaneDividerThickensUnderThePointer(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui): void {
                    $ctx = new \Symfony\Component\Tui\Render\RenderContext(1, 5);
                    $this->assertStringContainsString(Glyphs::PANE_DIVIDER, $tui->getById('separator')->render($ctx)[0], 'a thin rule at rest');

                    // Force a full frame so the separator has a live WidgetRect for
                    // the hover to hit — without it the hover routes against a null
                    // rect (the miss branch) and the divider never thickens.
                    $this->frame($tui);
                    // The 30-wide tree ends at column 29, so its divider is column 30 (SGR 31).
                    $tui->handleInput("\x1b[<35;31;5M");
                },
                280 => function ($tui): void {
                    $line = $tui->getById('separator')->render(new \Symfony\Component\Tui\Render\RenderContext(1, 5))[0];
                    $this->assertStringContainsString(Glyphs::PANE_DIVIDER_ACTIVE, $line, 'heavy under the pointer — the boundary announces it is draggable');
                    $this->assertSame(1, AnsiUtils::visibleWidth($line), 'still exactly one column');

                    $tui->handleInput("\x1b[<35;60;5M"); // into the editor
                },
                360 => function ($tui): void {
                    $line = $tui->getById('separator')->render(new \Symfony\Component\Tui\Render\RenderContext(1, 5))[0];
                    $this->assertStringContainsString(Glyphs::PANE_DIVIDER, $line, 'thin again once the pointer leaves');
                    $this->assertStringNotContainsString(Glyphs::PANE_DIVIDER_ACTIVE, $line);
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testF6CyclesFocusAcrossPanesAndDelegatesToViews(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n\$a = 1;\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui): void {
                    $this->assertSame('editor', $tui->getFocus()?->getId());
                    $tui->handleInput("\x1b[17~"); // F6: editor wraps to the tree
                    $this->assertSame('tree', $tui->getFocus()?->getId());
                    $tui->handleInput("\x1b[17~"); // F6: tree → editor
                    $this->assertSame('editor', $tui->getFocus()?->getId());
                },
                350 => function ($tui, $terminal, $ide): void {
                    Services::resolve($ide, CommandRegistry::class)->run('ide_terminal');
                    $this->assertSame('terminal', $tui->getFocus()?->getId());
                    $tui->handleInput("\x1b[17;5~"); // Ctrl+F6: terminal → editor (backward)
                    $this->assertSame('editor', $tui->getFocus()?->getId());
                    $tui->handleInput("\x1b[17~"); // F6 forward returns to the terminal
                    $this->assertSame('terminal', $tui->getFocus()?->getId());
                    Services::resolve($ide, CommandRegistry::class)->run('ide_terminal'); // close it again
                },
                500 => function ($tui, $terminal, $ide): void {
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                    $this->assertSame('settings-form', $tui->getFocus()?->getId(), 'settings opens on the form');
                    $tui->handleInput("\x1b[17~"); // F6 delegates to the settings view
                    $this->assertSame('settings-nav', $tui->getFocus()?->getId());
                    $tui->handleInput("\x1b[17;5~"); // either direction bounces the two panes
                    $this->assertSame('settings-form', $tui->getFocus()?->getId());
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                },
            ],
        );
    }

    public function testShiftF11AndF12ResizeTheFocusedPaneAndPersist(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n\$a = 1;\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui): void {
                    $tui->handleInput("\x1b[24;2~"); // Shift+F12 with editor focus: not resizable, falls through
                    $this->assertSame(30, $tui->getById('tree')->width(), 'editor focus resizes nothing');

                    $tui->setFocus($tui->getById('tree'));
                    $tui->handleInput("\x1b[24;2~"); // grow
                    $this->assertSame(32, $tui->getById('tree')->width());
                    $tui->handleInput("\x1b[23;2~"); // Shift+F11 shrinks it back
                    $this->assertSame(30, $tui->getById('tree')->width());
                },
                350 => function ($tui, $terminal, $ide): void {
                    Services::resolve($ide, CommandRegistry::class)->run('ide_terminal');
                    $tui->tick(); // render a frame so the panel has a rect to resize from
                    $rows = Probe::get($tui->getById('terminal'), 'visibleRows');
                    $tui->handleInput("\x1b[24;2~"); // grow the focused bottom panel
                    $this->assertSame($rows + 1, Probe::get($tui->getById('terminal'), 'visibleRows'));
                },
                500 => function () use ($project): void {
                    $layout = json_decode((string) file_get_contents($project->dir.'/.porta/layout.json'), associative: true);
                    $this->assertSame(19, $layout['sidebarWidthPercent'], 'the last tree width (30 of 160 cols) persisted');
                    $this->assertArrayHasKey('bottomPanelHeightPercent', $layout, 'the panel grow persisted too');
                },
            ],
        );
    }
}
