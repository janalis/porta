<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Porta\Ui\PaneSegmenter;
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

        $runs = PaneSegmenter::runsForRow($leaves, [], 0, 20);

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

        $runs = PaneSegmenter::runsForRow($leaves, [], 0, 20);

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

        $runs = PaneSegmenter::runsForRow($leaves, [], 0, 12);

        $this->assertRuns(
            $runs,
            ['start' => 0, 'width' => 4, 'ltr' => true], // gap (verbatim)
            ['start' => 4, 'width' => 4, 'ltr' => false], // pane
            ['start' => 8, 'width' => 4, 'ltr' => true], // gap
        );
    }

    public function testADropdownRegionIsTopmostAndSplitsAnLtrPane(): void
    {
        $leaves = [
            ['rect' => new WidgetRect(row: 0, col: 0, columns: 20, rows: 3), 'ltr' => true], // LTR editor
        ];
        $regions = [['top' => 0, 'col' => 5, 'width' => 5, 'count' => 2]];

        $runs = PaneSegmenter::runsForRow($leaves, $regions, 0, 20);

        $this->assertRuns(
            $runs,
            ['start' => 0, 'width' => 5, 'ltr' => true], // editor
            ['start' => 5, 'width' => 5, 'ltr' => false], // dropdown (RTL, on top)
            ['start' => 10, 'width' => 10, 'ltr' => true], // editor
        );
    }

    public function testARowOutsideAPaneIsNotOwnedByIt(): void
    {
        $leaves = [
            ['rect' => new WidgetRect(row: 2, col: 0, columns: 10, rows: 1), 'ltr' => false],
        ];

        // Row 0 is above the pane → the whole row is an uncovered verbatim run.
        $this->assertRuns(
            PaneSegmenter::runsForRow($leaves, [], 0, 10),
            ['start' => 0, 'width' => 10, 'ltr' => true],
        );
    }

    public function testARegionOutsideTheRowIsIgnored(): void
    {
        $leaves = [
            ['rect' => new WidgetRect(row: 0, col: 0, columns: 10, rows: 3), 'ltr' => false],
        ];
        $regions = [['top' => 5, 'col' => 2, 'width' => 3, 'count' => 2]]; // rows 5-6 only

        // Row 0 is above the dropdown → the region owns nothing here.
        $this->assertRuns(
            PaneSegmenter::runsForRow($leaves, $regions, 0, 10),
            ['start' => 0, 'width' => 10, 'ltr' => false],
        );
    }

    public function testSegmentSkipsAContextlessWidget(): void
    {
        // A freshly built (unrendered) root has no render context, so the walk
        // collects no leaves — mirroring HitTester's detached-widget guard.
        $root = new ContainerWidget();
        $segmenter = new PaneSegmenter(new Renderer(new StyleSheet()), new class implements \Porta\Overlay\FloatingRegions {
            public function regions(): array
            {
                return [];
            }
        });

        $byRow = $segmenter->segment($root, 6, 2);

        $this->assertSame([['start' => 0, 'width' => 6, 'ltr' => true]], $byRow[0]);
        $this->assertSame([['start' => 0, 'width' => 6, 'ltr' => true]], $byRow[1]);
    }
}
