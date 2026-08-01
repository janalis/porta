<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Settings\SettingsController;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * RTL locale mirroring: layout, per-pane row reordering, and
 * click routing under a mirrored sidebar.
 * Split out of the oversized IdeEdgeCasesTest.
 */
final class IdeRtlLocaleTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;

    public function testSwitchingTheLocaleLiveRelabelsTheMenuAndMirrorsTheLayout(): void
    {
        // Every other test here BOOTS mirrored, so the listener that reacts to
        // a live switch — one menu rebuild, one applyDirection — was reached by
        // nothing: emptying it left the whole suite green.
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui): void {
                    $ids = array_map(static fn ($w) => $w->getId(), $tui->getById('main')->all());
                    $this->assertSame(['tree', 'separator', 'editor-pane'], $ids, 'LTR at boot');
                    $this->assertStringContainsString('File', AnsiUtils::stripAnsiCodes(implode('', $tui->getById('menu')->render(new RenderContext(120, 1)))));
                },
                400 => static function ($tui, $terminal, $ide): void {
                    Services::resolve($ide, SettingsController::class)->changeSetting('language', 'he');
                    $tui->tick();
                },
                600 => function ($tui): void {
                    $ids = array_map(static fn ($w) => $w->getId(), $tui->getById('main')->all());
                    $this->assertSame(['editor-pane', 'separator', 'tree'], $ids, 'applyDirection mirrored the panes');

                    $menu = AnsiUtils::stripAnsiCodes(implode('', $tui->getById('menu')->render(new RenderContext(120, 1))));
                    $this->assertStringContainsString('קובץ', $menu, 'the menu rebuilt in the new locale');
                    $this->assertStringNotContainsString('File', $menu, 'and dropped the English labels');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testRtlLocaleMirrorsTheMainLayoutSidebarToTheRight(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject([
            '.porta.json' => json_encode(['settings' => ['language' => 'he']]),
            'a.php' => "<?php\n",
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui): void {
                    $ids = array_map(static fn ($w) => $w->getId(), $tui->getById('main')->all());
                    $this->assertSame(['editor-pane', 'separator', 'tree'], $ids, 'RTL puts the file tree on the right');
                },
            ],
        );
    }

    public function testRtlSidebarResizesFromItsMirroredSeparator(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject([
            '.porta.json' => json_encode(['settings' => ['language' => 'he']]),
            'a.php' => "<?php\n",
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui): void {
                    $this->assertSame(30, $tui->getById('tree')->width());
                    // Force a full frame so the mirrored separator has a live rect
                    // for the arm to hit — without it the drag routes against a null
                    // rect and no-ops, leaving the tree its original width.
                    $this->frame($tui);
                    // Mirrored, the 30-wide tree ends the 160-column row, so it owns
                    // columns 130..159 and its separator is column 129 (SGR 130).
                    // Dragging that separator LEFT must widen the tree, not shrink it.
                    $tui->handleInput("\x1b[<0;130;5M"); // arm on the mirrored separator
                    $tui->handleInput("\x1b[<32;121;5M"); // drag out to column 120
                    $tui->handleInput("\x1b[<0;121;5m"); // release
                },
                320 => function ($tui): void {
                    $this->assertSame(40, $tui->getById('tree')->width(), 'dragging the mirrored separator leftward widens the tree');
                },
            ],
        );
    }

    public function testLtrLocaleKeepsTheSidebarOnTheLeft(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui): void {
                    $ids = array_map(static fn ($w) => $w->getId(), $tui->getById('main')->all());
                    $this->assertSame(['tree', 'separator', 'editor-pane'], $ids, 'LTR keeps the file tree on the left');
                },
            ],
        );
    }

    public function testRtlEditorCodeIsNotTornOrMirrored(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject([
            '.porta.json' => json_encode(['settings' => ['language' => 'he']]),
            'demo.php' => "<?php\necho \$name;\n",
        ]);

        $this->runIde(
            $project,
            $project->path('demo.php'),
            [
                300 => function ($tui, $terminal): void {
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    // The code pane stays LTR: the open tag is intact, its
                    // punctuation is not bidi-mirrored, and the line is whole.
                    $this->assertStringContainsString('<?php', $screen, 'open tag intact (not split)');
                    $this->assertStringNotContainsString('?>', $screen, 'punctuation not mirrored to ?>');
                    $this->assertStringContainsString('echo $name;', $screen, 'the code line is not reordered');
                },
            ],
        );
    }

    public function testRtlEveryPaneRowKeepsItsWidth(): void
    {
        // The garble that broke RTL was over-wide rows bleeding across panes.
        // Assert the per-pane reorder keeps every row exactly the terminal width,
        // in the editor view and the (also-reported) settings view.
        putenv('PATH=/nonexistent');
        $project = new TempProject([
            '.porta.json' => json_encode(['settings' => ['language' => 'he']]),
            'demo.php' => "<?php\necho \$name;\n",
        ]);

        $assertWidths = function ($tui, $ide): void {
            $tui->tick();
            $renderer = Probe::get($tui, 'renderer');
            $root = Probe::get($tui, 'root');
            $segmenter = Services::resolve($ide, \Porta\Ui\PaneSegmenter::class);
            $reorder = Services::resolve($ide, \Porta\I18n\PaneReorder::class);
            $terminal = $tui->getTerminal();
            $columns = $terminal->getColumns();
            $lines = array_values($renderer->render($root, $columns, $terminal->getRows()));
            $runs = $segmenter->segment($root, $columns, $terminal->getRows());
            foreach ($lines as $row => $line) {
                $this->assertSame($columns, AnsiUtils::visibleWidth($reorder->reorderRow($line, $runs[$row])), "row {$row} kept its width");
            }
        };

        $this->runIde(
            $project,
            $project->path('demo.php'),
            [
                300 => static function ($tui, $terminal, $ide) use ($assertWidths): void {
                    $assertWidths($tui, $ide); // editor view
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                },
                500 => static function ($tui, $terminal, $ide) use ($assertWidths): void {
                    $assertWidths($tui, $ide); // settings view
                },
            ],
        );
    }

    public function testRtlSurvivesATerminalResize(): void
    {
        // RTL and resize meet in one place and nowhere else: `Tui::start()`
        // forces a FULL repaint on SIGWINCH (symfony/tui 8.1.2), and the frame
        // it forces goes through `BidiTui::processRender()` — an override that
        // reimplements the parent's private render step and re-mirrors every
        // row. Both halves are covered on their own (IdeResponsiveTest resizes
        // in LTR, the tests above mirror without resizing); the 100% line gate
        // cannot see that the COMBINATION was never run, because every line is
        // executed, just never together.
        putenv('PATH=/nonexistent');
        $project = new TempProject([
            '.porta.json' => json_encode(['settings' => ['language' => 'he']]),
            'demo.php' => "<?php\necho \$name;\n",
        ]);

        $this->runIde(
            $project,
            $project->path('demo.php'),
            [
                300 => function ($tui, $terminal): void {
                    $this->frame($tui);
                    $terminal->simulateResize(120, 24);
                    $tui->requestRender();
                    $this->frame($tui);
                },
                500 => function ($tui, $terminal, $ide): void {
                    $ids = array_map(static fn ($w) => $w->getId(), $tui->getById('main')->all());
                    $this->assertSame(['editor-pane', 'separator', 'tree'], $ids, 'still mirrored after the resize');

                    // Two assertions, because the first alone does not bite:
                    // the pane RUNS must span the new width (a segmentation
                    // still describing 160 columns is what a missed resize
                    // looks like — verified by mutation: reordering rows at the
                    // old width still yields correctly-wide rows, so only this
                    // check catches it), and the reordered rows must then be
                    // exactly the terminal width, which is what the RTL garble
                    // bug violated by bleeding one pane across another.
                    $renderer = Probe::get($tui, 'renderer');
                    $root = Probe::get($tui, 'root');
                    $segmenter = Services::resolve($ide, \Porta\Ui\PaneSegmenter::class);
                    $reorder = Services::resolve($ide, \Porta\I18n\PaneReorder::class);
                    $this->assertSame(120, $terminal->getColumns());
                    $lines = array_values($renderer->render($root, 120, $terminal->getRows()));
                    $runs = $segmenter->segment($root, 120, $terminal->getRows());
                    $last = $runs[0][array_key_last($runs[0])];
                    $this->assertSame(120, $last['start'] + $last['width'], 'the pane segmentation spans the new width');
                    foreach ($lines as $row => $line) {
                        $this->assertSame(120, AnsiUtils::visibleWidth($reorder->reorderRow($line, $runs[$row])), "row {$row} kept the new width");
                    }
                },
            ],
        );
    }

    public function testRtlClickInTheTreeRoutesToTheClickedEntry(): void
    {
        // End-to-end mouse chain in RTL: SGR click → MouseRouter → per-pane
        // logicalColumn → rect hit-test → open. A broken RTL column map would
        // misroute the click out of the (right-hand) tree pane and B would never open.
        putenv('PATH=/nonexistent');
        $project = new TempProject([
            '.porta.json' => json_encode(['settings' => ['language' => 'he']]),
            'A.php' => "<?php\n",
            'B.php' => "<?php\n",
        ]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui): void {
                    $tui->tick();
                    $renderer = Probe::get($tui, 'renderer');
                    $rect = $renderer->getWidgetRect($tui->getById('tree'));
                    $this->assertNotNull($rect);
                    $this->assertGreaterThan(0, $rect->col, 'RTL puts the file tree on the right');
                    // Tree lists .porta.json, A.php, B.php → B.php is screen row 5.
                    // Click inside the (right-hand) tree columns; the RTL column map
                    // must keep the click in the tree pane, not spill to the editor.
                    $col = $rect->col + 6;
                    $tui->handleInput("\x1b[<0;{$col};5M");
                },
                500 => function ($tui): void {
                    $this->assertStringEndsWith('B.php', (string) $tui->getById('editor')->getFilePath(), 'the RTL click opened the tree entry');
                },
            ],
        );
    }
}
