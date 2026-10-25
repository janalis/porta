<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Layout\GraphLayout;
use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\Mermaid\MermaidScene;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\MermaidSceneRows;
use Porta\Help\Fence\FenceResult;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * A diagram as a fence's rows: the drawing fitted into the width it is given,
 * a row for every two points of its height up to a cap, each row naming the
 * source line it shows, then the legend wrapped beneath.
 */
final class MermaidSceneRowsTest extends TestCase
{
    /** Every row names the line its middle shows: here a point a column, so drawing row i is source row i. */
    public function testEachRowNamesTheSourceLineItShows(): void
    {
        $result = self::rows(self::scene(40.0, 20.0), 40);

        $this->assertSame([10, 11, 12, 13, 14, 15, 16, 17, 18, 19], $result->origins);
    }

    /**
     * Fitted into half its width, the drawing is half as tall and centred:
     * the blank rows above it read as its first line, those below it as no
     * line of it at all (0).
     */
    public function testANarrowerPaneScalesTheRowsItMapsBack(): void
    {
        $result = self::rows(self::scene(40.0, 20.0), 20);

        $this->assertSame([10, 10, 10, 11, 13, 15, 17, 19, 0, 0], $result->origins);
    }

    /** At a scale with fractions, a row is the source row its top half-row starts in, rounded down. */
    public function testAFractionalScaleRoundsTheRowDown(): void
    {
        $result = self::rows(self::scene(40.4, 20.4), 30);

        $this->assertSame([10, 10, 10, 12, 13, 14, 16, 17, 18, 20, 0], $result->origins);
    }

    /** As many rows as two points of height make, rounded up, never more than 24; as wide as asked, between 1 and 200. */
    public function testTheRowsAreAsTallAndAsWideAsTheDrawingAllows(): void
    {
        $this->assertSame([16, 7], self::extent(self::rows(self::scene(14.0, 30.4), 7)));
        $this->assertSame([24, 200], self::extent(self::rows(self::scene(400.0, 100.0), 300)));
        $this->assertSame([1, 1], self::extent(self::rows(self::scene(4.0, 1.0), 0)));
    }

    public function testTheLegendIsWrappedToTheWidthUnderTheDrawingWithItsOwnLine(): void
    {
        $scene = new MermaidScene(self::scene(10.0, 2.0)->graph, [10], [['text' => 'alpha beta', 'origin' => 7]]);

        $result = self::rows($scene, 6);

        $this->assertSame(['alpha', 'beta'], \array_map(static fn (string $row): string => \rtrim(AnsiUtils::stripAnsiCodes($row)), \array_slice($result->rows, offset: 1)));
        $this->assertSame([10, 7, 7], $result->origins);
    }

    /** One origin per two points of height, numbered from 10, and a node to draw. */
    private static function scene(float $width, float $height): MermaidScene
    {
        $graph = new GraphLayout($width, $height, [0 => new GraphNode(0, 'n', 'n', $width / 2, $height / 2, 2.0, 2.0, null, null, false)], [], []);

        return new MermaidScene($graph, \range(10, 9 + (int) \ceil($height / 2)), []);
    }

    private static function rows(MermaidScene $scene, int $width): FenceResult
    {
        $rows = MermaidSceneRows::rows($scene, $width, Styles::dark());
        \iterator_to_array($rows);
        $result = $rows->getReturn();
        self::assertInstanceOf(FenceResult::class, $result);

        return $result;
    }

    /** @return array{int, int} rows, and the widest row's width */
    private static function extent(FenceResult $result): array
    {
        return [\count($result->rows), \max(\array_map(AnsiUtils::visibleWidth(...), $result->rows))];
    }
}
