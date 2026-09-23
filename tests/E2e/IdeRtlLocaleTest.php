<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Config\ConfigScope;
use Porta\I18n\BaseDirection;
use Porta\I18n\Bidi;
use Porta\I18n\PaneReorder;
use Porta\Ide;
use Porta\Settings\SettingsController;
use Porta\Settings\SettingsScope;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\NavigatesSettings;
use Porta\Tests\Support\Paint;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\ScreenReads;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Ui\PaneSegmenter;
use Porta\Widget\ContextMenu;
use Porta\Widget\MenuBarWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * RTL locale mirroring: layout, per-pane row reordering, and
 * click routing under a mirrored sidebar.
 * Split out of the oversized IdeEdgeCasesTest.
 */
final class IdeRtlLocaleTest extends TestCase
{
    use ClearsPortaLsp;
    use NavigatesSettings;
    use PreservesPath;
    use RunsIde;
    use ScreenReads;

    public function testSwitchingTheLocaleLiveRelabelsTheMenuAndMirrorsTheLayout(): void
    {
        // Every other test here BOOTS mirrored, so the listener that reacts to
        // a live switch — one menu rebuild, one applyDirection — was reached by
        // nothing: emptying it left the whole suite green.
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui): void {
                    $ids = \array_map(static fn ($w) => $w->getId(), $tui->getById('main')->all());
                    $this->assertSame(['tree', 'separator', 'editor-pane'], $ids, 'LTR at boot');
                    $this->assertStringContainsString('File', AnsiUtils::stripAnsiCodes(\implode('', Paint::lines($tui->getById('menu'), new RenderContext(120, 1)))));
                },
                400 => static function ($tui, $terminal, Ide $ide): void {
                    // The session scope defaults to Global for a project with
                    // no settings block, and Global here is the suite's SHARED
                    // fixture home — persisting 'he' there leaks Hebrew into
                    // every later test in the run. Pin the write to this
                    // project, which is removed with its temp dir.
                    Services::resolve($ide, SettingsScope::class)->set(ConfigScope::Project);
                    Services::resolve($ide, SettingsController::class)->changeSetting('language', 'he');
                    $tui->tick();
                },
                600 => function ($tui): void {
                    $ids = \array_map(static fn ($w) => $w->getId(), $tui->getById('main')->all());
                    $this->assertSame(['editor-pane', 'separator', 'tree'], $ids, 'applyDirection mirrored the panes');

                    $menu = AnsiUtils::stripAnsiCodes(\implode('', Paint::lines($tui->getById('menu'), new RenderContext(120, 1))));
                    $this->assertStringContainsString('קובץ', $menu, 'the menu rebuilt in the new locale');
                    $this->assertStringNotContainsString('File', $menu, 'and dropped the English labels');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testRtlLocaleMirrorsTheMainLayoutSidebarToTheRight(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject([
            '.porta.json' => \json_encode(['settings' => ['language' => 'he']]),
            'a.php' => "<?php\n",
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui): void {
                    $ids = \array_map(static fn ($w) => $w->getId(), $tui->getById('main')->all());
                    $this->assertSame(['editor-pane', 'separator', 'tree'], $ids, 'RTL puts the file tree on the right');
                },
            ],
        );
    }

    public function testRtlSidebarResizesFromItsMirroredSeparator(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject([
            '.porta.json' => \json_encode(['settings' => ['language' => 'he']]),
            'a.php' => "<?php\n",
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function (Tui $tui): void {
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
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui): void {
                    $ids = \array_map(static fn ($w) => $w->getId(), $tui->getById('main')->all());
                    $this->assertSame(['tree', 'separator', 'editor-pane'], $ids, 'LTR keeps the file tree on the left');
                },
            ],
        );
    }

    public function testRtlEditorCodeIsNotTornOrMirrored(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject([
            '.porta.json' => \json_encode(['settings' => ['language' => 'he']]),
            'demo.php' => "<?php\necho \$name;\n",
        ]);

        $this->runIde(
            $project,
            $project->path('demo.php'),
            [
                300 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $this->awaitPainted($tui, $terminal, '<?php');
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

    public function testOnABidiApplyingTerminalEveryHebrewRowIsFrozen(): void
    {
        // On a terminal that runs UAX#9 itself, a row Porta has already ordered
        // is ordered a SECOND time unless it is frozen with an override. A row
        // carrying Hebrew and NO override is one the terminal will rearrange —
        // which is what the whole RTL investigation was about.
        //
        // Swept across the app's surfaces by hand at 226x67; this pins the
        // invariant for the two that render the most Hebrew.
        \putenv('PATH=/nonexistent');
        $project = new TempProject([
            '.porta.json' => \json_encode(['settings' => ['language' => 'he']]),
            'demo.php' => "<?php\necho \$name;\n",
        ]);

        $assertFrozen = function (object $tui, Ide $ide): void {
            $tui->tick();
            $renderer = Probe::get($tui, 'renderer');
            $root = Probe::get($tui, 'root');
            $segmenter = Services::resolve($ide, PaneSegmenter::class);
            $reorder = Services::resolve($ide, PaneReorder::class);
            $terminal = $tui->getTerminal();
            $columns = $terminal->getColumns();
            $rows = $terminal->getRows();
            $lines = $renderer->renderFrame($root, $columns, $rows)->toArray();
            $runs = $segmenter->segment($root, $columns, $rows);
            $regions = $segmenter->segmentRegions($columns, $rows);
            foreach ($lines as $row => $line) {
                $frozen = $reorder->freezeRow($reorder->reorderRow($line, $runs[$row], $regions[$row]));
                $this->assertSame($columns, AnsiUtils::visibleWidth($frozen), "row {$row} kept its width");
                if (1 === \preg_match('/[\x{0590}-\x{05FF}]/u', AnsiUtils::stripAnsiCodes($frozen))) {
                    $this->assertStringContainsString(
                        Bidi::LTR_OVERRIDE,
                        $frozen,
                        "row {$row} carries Hebrew and would be reordered again",
                    );
                }
            }
        };

        $this->runIde(
            $project,
            $project->path('demo.php'),
            [
                300 => static function ($tui, $terminal, Ide $ide) use ($assertFrozen): void {
                    $assertFrozen($tui, $ide);
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                },
                400 => static function ($tui, $terminal, Ide $ide) use ($assertFrozen): void {
                    $assertFrozen($tui, $ide);
                    // The help viewer renders prose against a left-hand border,
                    // so its rows do NOT begin with padding — the one surface
                    // the sweep found unfrozen, and the only one here that
                    // exercises the search for a later cell to spend.
                    Services::resolve($ide, CommandRegistry::class)->run('ide_help');
                },
                500 => static function ($tui, $terminal, $ide) use ($assertFrozen): void {
                    $assertFrozen($tui, $ide);
                },
            ],
        );
    }

    public function testRtlEveryPaneRowKeepsItsWidth(): void
    {
        // The garble that broke RTL was over-wide rows bleeding across panes.
        // Assert the per-pane reorder keeps every row exactly the terminal width,
        // in the editor view and the (also-reported) settings view.
        \putenv('PATH=/nonexistent');
        $project = new TempProject([
            '.porta.json' => \json_encode(['settings' => ['language' => 'he']]),
            'demo.php' => "<?php\necho \$name;\n",
        ]);

        $assertWidths = function (object $tui, Ide $ide): void {
            $tui->tick();
            $renderer = Probe::get($tui, 'renderer');
            $root = Probe::get($tui, 'root');
            $segmenter = Services::resolve($ide, PaneSegmenter::class);
            $reorder = Services::resolve($ide, PaneReorder::class);
            $terminal = $tui->getTerminal();
            $columns = $terminal->getColumns();
            $lines = $renderer->renderFrame($root, $columns, $terminal->getRows())->toArray();
            $runs = $segmenter->segment($root, $columns, $terminal->getRows());
            foreach ($lines as $row => $line) {
                $this->assertSame($columns, AnsiUtils::visibleWidth($reorder->reorderRow($line, $runs[$row])), "row {$row} kept its width");
            }
        };

        $this->runIde(
            $project,
            $project->path('demo.php'),
            [
                300 => static function ($tui, $terminal, Ide $ide) use ($assertWidths): void {
                    $assertWidths($tui, $ide); // editor view
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                },
                500 => static function ($tui, $terminal, $ide) use ($assertWidths): void {
                    $assertWidths($tui, $ide); // settings view
                },
            ],
        );
    }

    /**
     * A row BELOW the menu bar is segmented into a verbatim part and a mirrored
     * part — which is the whole claim this suite exists to support.
     *
     * Everything else here checks row 0, and row 0 is the menu bar: the one row
     * that survives the segmenter losing its leaf walk, because the menu bar is
     * the FIRST leaf. The width invariant next door cannot help either — one
     * full-width verbatim run per row measures exactly the terminal width, so
     * the degenerate output a broken walk produces satisfies it perfectly.
     *
     * Ten cases and 102 assertions passed with mirroring switched off for every
     * row but one (decisions.md, 2026-09-11). This is the case that notices.
     *
     * Asserted as "both kinds of run are present" rather than as a column
     * number, so it survives a layout change and still fails the moment a body
     * row stops being segmented at all.
     */
    public function testRtlSegmentsARowBelowTheMenuBar(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject([
            '.porta.json' => \json_encode(['settings' => ['language' => 'he']]),
            'demo.php' => "<?php\necho \$name;\n",
        ]);

        $this->runIde(
            $project,
            $project->path('demo.php'),
            [
                300 => function (object $tui, $terminal, Ide $ide): void {
                    $tui->tick();
                    $root = Probe::get($tui, 'root');
                    $segmenter = Services::resolve($ide, PaneSegmenter::class);
                    $this->assertInstanceOf(PaneSegmenter::class, $segmenter, 'the IDE exposes the segmenter');
                    $runs = $segmenter->segment($root, $terminal->getColumns(), $terminal->getRows());

                    // The editor body: code on one side, the mirrored sidebar on
                    // the other. Row 0 is the menu bar and row 1 the tab strip.
                    $body = $runs[3] ?? [];
                    $this->assertGreaterThan(1, \count($body), 'the row is split into panes, not one verbatim block');
                    $kinds = \array_unique(\array_column($body, 'ltr'));
                    \sort($kinds);
                    $this->assertSame([false, true], $kinds, 'a verbatim code run AND a mirrored chrome run');
                },
            ],
        );
    }

    /**
     * A settings value survives the whole mirroring pipeline, not just the
     * reorder called by hand.
     *
     * The widget renders in logical order and looks perfect there; the defect
     * only exists once the segmenter has split the row into panes and the
     * reorder has run on the settings pane's own column span. This drives the
     * real screen — the same route `testRtlEveryPaneRowKeepsItsWidth` takes,
     * asked a question about content rather than width.
     */
    /**
     * The menus learn the writing direction at boot, not just when it changes.
     *
     * `MenuPanel` lays the shortcut column out for the direction, and neither
     * menu widget carries a Translator — so the direction is pushed in, from
     * `WidgetHooks` at boot and from `MenuLayoutRefreshListener` on a locale
     * switch. A unit test of the panel cannot see whether either push happened.
     */
    public function testRtlMenusAreToldTheirDirectionAtBoot(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject([
            '.porta.json' => \json_encode(['settings' => ['language' => 'he']]),
            'demo.php' => "<?php\n",
        ]);

        $this->runIde(
            $project,
            $project->path('demo.php'),
            [
                300 => function ($tui, $terminal, Ide $ide): void {
                    $this->assertSame(
                        BaseDirection::Rtl,
                        Probe::get(Services::resolve($ide, MenuBarWidget::class), 'direction'),
                        'the menu bar was told the locale mirrors',
                    );
                    $this->assertSame(
                        BaseDirection::Rtl,
                        Probe::get(Services::resolve($ide, ContextMenu::class), 'direction'),
                        'and so was the context menu',
                    );
                },
            ],
        );
    }

    /**
     * A select's list drops UNDER the select on a mirrored screen.
     *
     * Two mirrors meet here: the select was reordered with its row inside the
     * form pane (beside the nav sidebar), and the floating list is its own
     * run, mirrored across the whole row. Anchored at the select's logical
     * column, the list landed on the far side of the pane.
     */
    public function testAMirroredSelectDropsItsListUnderItself(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject([
            '.porta.json' => \json_encode(['settings' => ['language' => 'he', 'theme' => 'dark']]),
            'demo.php' => "<?php\n",
        ]);

        $this->runIde(
            $project,
            $project->path('demo.php'),
            [
                300 => function (Tui $tui, $terminal, Ide $ide): void {
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                    $this->selectSettingsSection($this->settingsNav($tui), 'interface');
                },
                500 => static function (object $tui): void {
                    $tui->requestRender(force: true);
                    $tui->processRender(); // a frame: the reorder the list is anchored against
                    $form = $tui->getById('settings-form');
                    $tui->setFocus($form);
                    $form->handleInput("\x1b[B"); // Language
                    $form->handleInput("\x1b[B"); // Theme
                    $tui->requestRender(force: true);
                    $tui->processRender();
                    $form->handleInput("\r"); // drop its list
                },
                700 => function (object $tui, VirtualTerminal $terminal): void {
                    $screen = $this->screenGrid($tui, $terminal); // the frame as painted, reorder and all
                    $box = $this->rowWith($screen, 'dark ');
                    $list = $this->rowWith($screen, 'high-contrast');
                    $this->assertNotSame($box, $list, 'the list is open below the select');

                    // The select's heavy left rail and the list's stand in one column.
                    $this->assertSame(
                        \mb_strpos($screen[$box - 1], needle: '┏'),
                        \mb_strpos($screen[$list - 3], needle: '┏'),
                        "the list hangs under its select:\n".\implode("\n", \array_slice($screen, $box - 1, length: 8)),
                    );
                },
            ],
        );
    }

    /** @param list<string> $screen */
    private function rowWith(array $screen, string $needle): int
    {
        foreach ($screen as $row => $line) {
            if (\str_contains($line, $needle)) {
                return $row;
            }
        }

        self::fail("no row shows {$needle}");
    }

    public function testRtlSettingsValuesKeepTheirBrackets(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject([
            '.porta.json' => \json_encode(['settings' => ['language' => 'he', 'theme' => 'dark']]),
            'demo.php' => "<?php\n",
        ]);

        $this->runIde(
            $project,
            $project->path('demo.php'),
            [
                300 => function (Tui $tui, $terminal, Ide $ide): void {
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                    // The view opens on General; Theme lives on Interface.
                    $this->selectSettingsSection($this->settingsNav($tui), 'interface');
                },
                500 => function (object $tui, $terminal, Ide $ide): void {
                    $tui->tick();
                    $renderer = Probe::get($tui, 'renderer');
                    $root = Probe::get($tui, 'root');
                    $segmenter = Services::resolve($ide, PaneSegmenter::class);
                    $reorder = Services::resolve($ide, PaneReorder::class);
                    $this->assertInstanceOf(PaneSegmenter::class, $segmenter, 'the IDE exposes the segmenter');
                    $this->assertInstanceOf(PaneReorder::class, $reorder, 'the IDE exposes the reorder');

                    $columns = $terminal->getColumns();
                    $rows = $terminal->getRows();
                    $lines = $renderer->renderFrame($root, $columns, $rows)->toArray();
                    $runs = $segmenter->segment($root, $columns, $rows);
                    $screen = '';
                    foreach ($lines as $row => $line) {
                        $screen .= AnsiUtils::stripAnsiCodes($reorder->reorderRow($line, $runs[$row]))."\n";
                    }

                    $this->assertMatchesRegularExpression('/[│┃] dark\s+▾ [│┃]/u', $screen, 'the theme value reads as one intact select');
                    $this->assertStringNotContainsString("\u{200E}", $screen, 'and no directional mark reached the terminal');
                },
            ],
        );
    }

    public function testRtlSurvivesATerminalResize(): void
    {
        // RTL and resize meet in one place and nowhere else: `Tui::start()`
        // forces a FULL repaint on SIGWINCH (symfony/tui 8.1.6), and the frame
        // it forces goes through `BidiTui::processRender()` — an override that
        // reimplements the parent's private render step and re-mirrors every
        // row. Both halves are covered on their own (IdeResponsiveTest resizes
        // in LTR, the tests above mirror without resizing); the 100% line gate
        // cannot see that the COMBINATION was never run, because every line is
        // executed, just never together.
        \putenv('PATH=/nonexistent');
        $project = new TempProject([
            '.porta.json' => \json_encode(['settings' => ['language' => 'he']]),
            'demo.php' => "<?php\necho \$name;\n",
        ]);

        $this->runIde(
            $project,
            $project->path('demo.php'),
            [
                300 => function (Tui $tui, $terminal): void {
                    $this->frame($tui);
                    $terminal->simulateResize(120, 24);
                    $tui->requestRender();
                    $this->frame($tui);
                },
                500 => function (object $tui, $terminal, Ide $ide): void {
                    $ids = \array_map(static fn ($w) => $w->getId(), $tui->getById('main')->all());
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
                    $segmenter = Services::resolve($ide, PaneSegmenter::class);
                    $reorder = Services::resolve($ide, PaneReorder::class);
                    $this->assertSame(120, $terminal->getColumns());
                    $lines = $renderer->renderFrame($root, 120, $terminal->getRows())->toArray();
                    $runs = $segmenter->segment($root, 120, $terminal->getRows());
                    $last = \array_last($runs[0]);
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
        // End-to-end mouse chain in RTL: SGR click → MouseRouter → rect
        // hit-test → open, with the sidebar MIRRORED to the right.
        //
        // It does not test the bidi column map, though it used to say it did:
        // "a broken RTL column map would misroute the click out of the tree
        // pane" is false, and deleting that map leaves this green. The rows
        // here are Latin filenames, so every bidi run is left-to-right and
        // logicalColumn() is the identity. {@see
        // testRtlClickOnAMenuTitleOpensThatMenu()} is the one that bites.
        \putenv('PATH=/nonexistent');
        $project = new TempProject([
            '.porta.json' => \json_encode(['settings' => ['language' => 'he']]),
            'A.php' => "<?php\n",
            'B.php' => "<?php\n",
        ]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function (object $tui): void {
                    $this->frame($tui);
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

    /**
     * And a DRAG in the tree, in RTL, lands where the pointer is.
     *
     * The gesture added later, run against a MIRRORED layout: press, motion
     * and release all land in a tree pane that RTL has moved to the right, and
     * the drop resolves the row under the pointer there.
     *
     * Not a test of the bidi column map, and it would be dishonest to claim so
     * — deleting that map leaves this green, because a drop resolves by ROW and
     * these rows are Latin filenames whose runs map to themselves. {@see
     * testRtlClickOnAMenuTitleOpensThatMenu()} covers the map.
     */
    public function testRtlDragInTheTreeDropsOnTheFolderUnderThePointer(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject([
            '.porta.json' => \json_encode(['settings' => ['language' => 'he']]),
            'src/keep.php' => "<?php\n",
            'move.php' => "<?php\n",
        ]);

        $this->runIde(
            $project,
            $project->path('move.php'),
            [
                300 => function (object $tui): void {
                    $this->frame($tui);
                    $renderer = Probe::get($tui, 'renderer');
                    $rect = $renderer->getWidgetRect($tui->getById('tree'));
                    $this->assertNotNull($rect);
                    $this->assertGreaterThan(0, $rect->col, 'RTL puts the file tree on the right');
                    // Read the rows rather than assume them: directories sort
                    // first, so this fixture is 1 = src/, 2 = .porta.json,
                    // 3 = move.php. The menu bar sits above the tree, so a
                    // widget row N is SGR row N + 2.
                    $rows = \array_map(
                        AnsiUtils::stripAnsiCodes(...),
                        Paint::lines($tui->getById('tree'), new RenderContext(30, 12)),
                    );
                    $this->assertSame('▸ src/', \trim($rows[1] ?? ''), 'src/ is widget row 1');
                    $this->assertSame('move.php', \trim($rows[3] ?? ''), 'move.php is widget row 3');
                    $col = $rect->col + 6;
                    $tui->handleInput("\x1b[<0;{$col};5M");
                    // press move.php (widget row 3)
                    $tui->handleInput("\x1b[<32;{$col};3M");
                    // drag onto src/  (widget row 1)
                    $tui->handleInput("\x1b[<0;{$col};3m");

                    // release
                },
                500 => function ($tui) use ($project): void {
                    $this->assertFileExists($project->path('src/move.php'), 'the RTL drag dropped on the folder under the pointer');
                    $this->assertFileDoesNotExist($project->path('move.php'));
                },
            ],
        );
    }

    /**
     * A click on a menu title in RTL opens THAT menu, not its mirror image.
     *
     * The one thing in this file that actually exercises {@see
     * \Porta\Input\MouseRouter}'s bidi column map — and it was missing. Every
     * other RTL mouse test here clicks a row of Latin filenames, where every
     * bidi run is left-to-right and `logicalColumn()` is the identity, so
     * deleting the map outright left the whole file green (and the unit suite
     * too, all 5,727 of them).
     *
     * The menu bar is where the map bites: its titles are Hebrew, the row is
     * one right-to-left run, and visual column 0 maps to logical column 159 on
     * a 160-column terminal. A click near the RIGHT edge is the FIRST menu.
     */
    public function testRtlClickOnAMenuTitleOpensThatMenu(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject([
            '.porta.json' => \json_encode(['settings' => ['language' => 'he']]),
            'A.php' => "<?php\n",
        ]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui): void {
                    $tui->tick();
                    $bar = $tui->getById('menu');
                    $this->assertFalse($bar->isOpen(), 'nothing is open yet');

                    // The first title starts at logical column titleStart(0);
                    // ask the live map where that lands on screen, so the test
                    // states the RELATIONSHIP rather than a magic number.
                    $logical = $bar->titleStart(0) + 1;
                    $visual = null;
                    foreach (\range(0, end: 199) as $candidate) {
                        if ($logical !== $tui->logicalColumn(0, $candidate)) {
                            continue;
                        }

                        $visual = $candidate;
                        break;
                    }
                    $this->assertNotNull($visual, 'the first title is reachable on screen');
                    $this->assertNotSame($logical, $visual, 'and RTL puts it somewhere else entirely — otherwise this proves nothing');

                    $tui->handleInput("\x1b[<0;".($visual + 1).';1M');
                },
                500 => function ($tui): void {
                    $bar = $tui->getById('menu');
                    $this->assertTrue($bar->isOpen(), 'the click landed on a title');
                    $this->assertSame(0, $bar->openIndex(), 'and on the FIRST one, where the pointer was');
                },
            ],
        );
    }
}
