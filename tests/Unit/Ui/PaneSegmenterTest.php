<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Porta\Overlay\Region\DropdownRegion;
use Porta\Overlay\Region\FloatingRegions;
use Porta\Ui\LtrPane;
use Porta\Ui\PaneSegmenter;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\DockTabsWidget;
use Porta\Widget\EditorPaneWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\TerminalPanelWidget;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Widget\ContainerWidget;

final class PaneSegmenterTest extends TestCase
{
    /** @param array{start: int, width: int, ltr: bool} ...$expected */
    private function assertRuns(array $actual, array ...$expected): void
    {
        $this->assertSame($expected, $actual);
    }

    public function testTwoPanesWithAnLtrRightPane(): void
    {
        $leaves = [
            ['rect' => new WidgetRect(row: 0, col: 0, columns: 10, rows: 5), 'ltr' => false], // chrome
            ['rect' => new WidgetRect(row: 0, col: 10, columns: 10, rows: 5), 'ltr' => true], // code
        ];

        $runs = PaneSegmenter::runsForRow($leaves, 0, 20);

        $this->assertRuns(
            $runs,
            ['start' => 0, 'width' => 10, 'ltr' => false],
            ['start' => 10, 'width' => 10, 'ltr' => true],
        );
    }

    public function testALaterLeafPaintsOverAnEarlierOne(): void
    {
        $leaves = [
            ['rect' => new WidgetRect(row: 0, col: 0, columns: 20, rows: 1), 'ltr' => true], // background pane
            ['rect' => new WidgetRect(row: 0, col: 5, columns: 6, rows: 1), 'ltr' => false], // painted on top
        ];

        $runs = PaneSegmenter::runsForRow($leaves, 0, 20);

        $this->assertRuns(
            $runs,
            ['start' => 0, 'width' => 5, 'ltr' => true],
            ['start' => 5, 'width' => 6, 'ltr' => false],
            ['start' => 11, 'width' => 9, 'ltr' => true],
        );
    }

    public function testUncoveredColumnsBecomeVerbatimRuns(): void
    {
        $leaves = [
            ['rect' => new WidgetRect(row: 0, col: 4, columns: 4, rows: 1), 'ltr' => false],
        ];

        $runs = PaneSegmenter::runsForRow($leaves, 0, 12);

        $this->assertRuns(
            $runs,
            ['start' => 0, 'width' => 4, 'ltr' => true], // gap (verbatim)
            ['start' => 4, 'width' => 4, 'ltr' => false], // pane
            ['start' => 8, 'width' => 4, 'ltr' => true], // gap
        );
    }

    public function testAFloatingRegionLeavesThePaneUnderItWhole(): void
    {
        // It used to SPLIT the pane into [0,5) [5,10) [10,20). That is what
        // broke the Hebrew settings screen: reordering a pane over a span
        // shortened by whatever floats above it re-aligns the pane's whole row.
        // The surface is a separate layer now; the pane stays one run.
        $leaves = [
            ['rect' => new WidgetRect(row: 0, col: 0, columns: 20, rows: 3), 'ltr' => true], // LTR editor
        ];

        $this->assertRuns(
            PaneSegmenter::runsForRow($leaves, 0, 20),
            ['start' => 0, 'width' => 20, 'ltr' => true],
        );
        $this->assertRuns(
            PaneSegmenter::regionRunsForRow([new DropdownRegion(0, 5, 5, 2)], 0, 20),
            ['start' => 5, 'width' => 5, 'ltr' => false],
        );
    }

    /**
     * A region's first and last rows may be spans of their own (a shadowed
     * box's title row and band), and a row narrower than its box says where
     * the box is: a verbatim box moves as one block.
     */
    public function testARegionsEdgeRowsRunTheirOwnSpansAndCarryTheirBox(): void
    {
        $region = new DropdownRegion(0, 3, 11, 3, ltr: true, firstRow: [3, 10], lastRow: [4, 10]);

        $this->assertSame([['start' => 3, 'width' => 10, 'ltr' => true, 'box' => [3, 11]]], PaneSegmenter::regionRunsForRow([$region], 0, 20));
        $this->assertSame([['start' => 3, 'width' => 11, 'ltr' => true]], PaneSegmenter::regionRunsForRow([$region], 1, 20), 'a body row is its box');
        $this->assertSame([['start' => 4, 'width' => 10, 'ltr' => true, 'box' => [3, 11]]], PaneSegmenter::regionRunsForRow([$region], 2, 20));
        $this->assertSame([['start' => 4, 'width' => 8, 'ltr' => true, 'box' => [3, 9]]], PaneSegmenter::regionRunsForRow([$region], 2, 12), 'both clipped to the screen');
    }

    public function testARowOutsideAPaneIsNotOwnedByIt(): void
    {
        $leaves = [
            ['rect' => new WidgetRect(row: 2, col: 0, columns: 10, rows: 1), 'ltr' => false],
        ];

        // Row 0 is above the pane → the whole row is an uncovered verbatim run.
        $this->assertRuns(
            PaneSegmenter::runsForRow($leaves, 0, 10),
            ['start' => 0, 'width' => 10, 'ltr' => true],
        );
    }

    public function testARegionOutsideTheRowIsIgnored(): void
    {
        $leaves = [
            ['rect' => new WidgetRect(row: 0, col: 0, columns: 10, rows: 3), 'ltr' => false],
        ];
        $regions = [new DropdownRegion(5, 2, 3, 2)]; // rows 5-6 only

        // Row 0 is above the dropdown → nothing floats here, and the pane is whole.
        $this->assertRuns(
            PaneSegmenter::runsForRow($leaves, 0, 10),
            ['start' => 0, 'width' => 10, 'ltr' => false],
        );
        $this->assertSame([], PaneSegmenter::regionRunsForRow($regions, 0, 10));
    }

    /**
     * A leaf that misses the row is SKIPPED, not a reason to stop looking.
     *
     * The two cases above each hand the walk a single element, where skipping
     * it and abandoning the walk produce the same answer. The real screen never
     * looks like that. Measured from a running IDE at 160x24, the leaves arrive
     * in paint order and the first one is the menu bar:
     *
     *     leaf 0  row 0   1 row   cols 0..159   RTL   (menu bar)
     *     leaf 1  row 1   1 row   cols 0..128   RTL   (tab strip)
     *     leaf 2  row 2  21 rows  cols 0..128   LTR   (code)
     *     leaf 3  row 1  22 rows  col  129      RTL   (splitter)
     *     leaf 4  row 1  22 rows  cols 130..159 RTL   (sidebar)
     *     leaf 5  row 23  1 row   cols 0..159   RTL   (status bar)
     *
     * So leaf 0 misses every row but the first. Stop there and rows 1..23 are
     * owned by nothing at all — one uncovered verbatim run apiece, which is
     * the whole UI below the menu bar rendered unmirrored under an RTL locale.
     * Row 2 is the sharpest: two runs (LTR code, RTL sidebar) collapse into one
     * verbatim run and the sidebar stops mirroring.
     *
     * The RTL E2e suite does not notice — its ten cases pass with this walk
     * abandoned after leaf 0 — so this unit case is the only thing holding it.
     */
    public function testALeafThatMissesTheRowDoesNotStopTheWalk(): void
    {
        $leaves = [
            ['rect' => new WidgetRect(row: 0, col: 0, columns: 10, rows: 1), 'ltr' => false], // menu bar, row 0 only
            ['rect' => new WidgetRect(row: 1, col: 0, columns: 10, rows: 4), 'ltr' => false], // the RTL pane below it
        ];

        $this->assertRuns(
            PaneSegmenter::runsForRow($leaves, 1, 10),
            ['start' => 0, 'width' => 10, 'ltr' => false],
        );
    }

    /** The region walk has the same shape, and the same hole. */
    public function testARegionPastTheRightEdgeIsSkipped(): void
    {
        $regions = [
            new DropdownRegion(0, 20, 5, 1), // columns 20-24 on a 12-column screen
            new DropdownRegion(0, 2, 3, 1),
        ];

        // Nothing of the first is on screen; the walk goes on to the second.
        $this->assertRuns(
            PaneSegmenter::regionRunsForRow($regions, 0, 12),
            ['start' => 2, 'width' => 3, 'ltr' => false],
        );
    }

    public function testARegionThatMissesTheRowDoesNotStopTheWalk(): void
    {
        $leaves = [
            ['rect' => new WidgetRect(row: 0, col: 0, columns: 12, rows: 3), 'ltr' => true],
        ];
        $regions = [
            new DropdownRegion(5, 0, 12, 2), // rows 5-6: not this row
            new DropdownRegion(0, 4, 4, 2), // rows 0-1: this one owns four columns
        ];

        // The region that misses the row is skipped; the one that covers it is
        // still found, and the pane beneath both stays whole.
        $this->assertRuns(
            PaneSegmenter::regionRunsForRow($regions, 0, 12),
            ['start' => 4, 'width' => 4, 'ltr' => false],
        );
        $this->assertRuns(
            PaneSegmenter::runsForRow($leaves, 0, 12),
            ['start' => 0, 'width' => 12, 'ltr' => true],
        );
    }

    /**
     * Exactly these panes stay verbatim under a right-to-left locale.
     *
     * {@see LtrPane} is an empty marker, and `PaneSegmenter` reads it as the
     * whole of the mirror/verbatim decision — so the set IS the contract, and
     * until now nothing held it. Removing the marker from `CodeEditorWidget`
     * failed no test, and source code would have been bidi-reordered on the
     * next frame.
     *
     * Derived from the tree rather than asserted class by class: FQCN follows
     * path in this repo, so a new pane that marks itself appears here the moment
     * it is written. Adding one is then a deliberate edit to this list, which is
     * the point — a pane joins or leaves the verbatim set on purpose.
     */
    public function testTheVerbatimPaneSetIsExactlyThese(): void
    {
        $expected = [
            CodeEditorWidget::class,
            DebugPanelWidget::class,
            DockTabsWidget::class,
            EditorPaneWidget::class,
            ResultsPanelWidget::class,
            TerminalPanelWidget::class,
            'Porta\\GitPlugin\\Widget\\DiffViewWidget',
        ];
        \sort($expected);

        $this->assertSame($expected, self::declaredVerbatimPanes());
    }

    /**
     * Every class in the tree that marks ITSELF verbatim. Subclasses inherit
     * the marker and are deliberately not listed — the decision is made once,
     * where it is declared.
     *
     * @return list<string>
     */
    private static function declaredVerbatimPanes(): array
    {
        $found = [];
        foreach ([__DIR__.'/../../../src', __DIR__.'/../../../plugins'] as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                    continue;
                }
                $code = (string) \file_get_contents($file->getPathname());
                if (1 !== \preg_match('/implements[^{]*\bLtrPane\b/', $code) || 1 !== \preg_match('/^namespace\s+([^;]+);/m', $code, $namespace)) {
                    continue;
                }

                $found[] = $namespace[1].'\\'.$file->getBasename('.php');
            }
        }
        \sort($found);

        return $found;
    }

    public function testSegmentSkipsAContextlessWidget(): void
    {
        // A freshly built (unrendered) root has no render context, so the walk
        // collects no leaves — mirroring HitTester's detached-widget guard.
        $root = new ContainerWidget();
        $segmenter = new PaneSegmenter(new Renderer(new StyleSheet()), new class implements FloatingRegions {
            public function regions(): array
            {
                return [];
            }
        });

        $byRow = $segmenter->segment($root, 6, 2);

        $this->assertSame([['start' => 0, 'width' => 6, 'ltr' => true]], $byRow[0]);
        $this->assertSame([['start' => 0, 'width' => 6, 'ltr' => true]], $byRow[1]);
    }

    public function testSegmentKeepsThePaneWholeAndReportsRegionsSeparately(): void
    {
        // The regression guard for the Hebrew settings screen. A floating
        // surface must NOT join the pane runs: merged in it owns its columns,
        // which truncates the pane beneath, and reordering the shortened span
        // slides the pane's whole row sideways.
        $root = new ContainerWidget();
        $region = new DropdownRegion(0, 2, 3, 2);
        $segmenter = new PaneSegmenter(new Renderer(new StyleSheet()), new readonly class($region) implements FloatingRegions {
            public function __construct(
                private DropdownRegion $region,
            ) {}

            public function regions(): array
            {
                return [$this->region];
            }
        });

        $panes = $segmenter->segment($root, 6, 2);
        $regions = $segmenter->segmentRegions(6, 2);

        $this->assertSame([['start' => 0, 'width' => 6, 'ltr' => true]], $panes[0], 'the pane spans the row, undisturbed');
        $this->assertSame([['start' => 2, 'width' => 3, 'ltr' => false]], $regions[0], 'the surface is its own layer');
    }
}
