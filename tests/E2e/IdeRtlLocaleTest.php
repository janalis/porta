<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Tui;

/**
 * RTL locale mirroring: layout, per-pane row reordering, and
 * click routing under a mirrored sidebar.
 * Split out of the oversized IdeEdgeCasesTest.
 */
final class IdeRtlLocaleTest extends TestCase
{
    use RunsIde;

    private string $originalPath;

    private ?string $portaHomeToRestore = null;

    protected function setUp(): void
    {
        $this->originalPath = PORTA_TEST_PRISTINE_PATH;
        putenv('PORTA_LSP');
    }

    protected function tearDown(): void
    {
        if (null !== $this->portaHomeToRestore) {
            putenv('PORTA_HOME='.$this->portaHomeToRestore);
            $this->portaHomeToRestore = null;
        }
        putenv('PATH='.$this->originalPath);
        putenv('PORTA_LSP');
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
            $renderer = new \ReflectionProperty(\Symfony\Component\Tui\Tui::class, 'renderer')->getValue($tui);
            $root = new \ReflectionProperty(\Symfony\Component\Tui\Tui::class, 'root')->getValue($tui);
            $segmenter = $ide->resolve(\Porta\Ui\PaneSegmenter::class);
            $reorder = $ide->resolve(\Porta\I18n\PaneReorder::class);
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
                    $ide->resolve(CommandRegistry::class)->run('ide_settings');
                },
                500 => static function ($tui, $terminal, $ide) use ($assertWidths): void {
                    $assertWidths($tui, $ide); // settings view
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
                    $renderer = new \ReflectionProperty(\Symfony\Component\Tui\Tui::class, 'renderer')->getValue($tui);
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
