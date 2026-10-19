<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\FlowchartParser;
use Porta\GraphViewer\Mermaid\MermaidDrawing;
use Porta\GraphViewer\Mermaid\MermaidLexer;
use Porta\GraphViewer\Mermaid\MermaidOutline;
use Porta\GraphViewer\Mermaid\MermaidScene;
use Porta\GraphViewer\Mermaid\RankedGraphLayout;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class FlowchartTriangleTest extends TestCase
{
    private static function scene(string $source): MermaidScene
    {
        $parser = new FlowchartParser();
        foreach (new MermaidLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }
        $work = new RankedGraphLayout()->layout($parser->finish());
        \iterator_to_array($work);

        return $work->getReturn();
    }

    /** @return iterable<string, array{string, list<array{float, float}>, float}> */
    public static function shapes(): iterable
    {
        foreach (['tri', 'triangle', 'extract'] as $shape) {
            yield $shape => [$shape, [[0.0, -6.0], [10.0, 6.0], [-10.0, 6.0]], -5.0];
        }
        foreach (['flip-tri', 'flipped-triangle', 'manual-file'] as $shape) {
            yield $shape => [$shape, [[-10.0, -6.0], [10.0, -6.0], [0.0, 6.0]], 5.0];
        }
    }

    /** @param list<array{float, float}> $expected */
    #[DataProvider('shapes')]
    public function testAliasesShareThreeSidedOutlinesAndExcludeEmptyCorners(string $shape, array $expected, float $emptyY): void
    {
        $node = new GraphNode(0, 'A', 'A', 0.0, 0.0, 20.0, 12.0, null, null, dashed: false);
        $this->assertSame($expected, MermaidOutline::points($node, $shape));
        $this->assertFalse(MermaidOutline::contains($node, $shape, -9.0, $emptyY));
        $this->assertTrue(MermaidOutline::contains($node, $shape, -8.0, -$emptyY));
        $this->assertTrue(MermaidOutline::contains($node, $shape, 0.0, 0.0));
        $this->assertSame([5.0, 0.0], MermaidOutline::boundary($node, $shape, 100.0, 0.0));
        $mark = MermaidOutline::marks($node, $shape)[0];
        $this->assertSame('polygon', $mark->kind);
        $this->assertSame($expected, $mark->points);
    }

    public function testCompoundPlacementFitsMultilineLabelsAndRoutesToTriangleBorders(): void
    {
        $scene = self::scene('flowchart LR; subgraph G[Steps]; A@{shape: extract, label: "Extract<br>漢字"} --> B@{shape: manual-file, label: "File<br>records"}; end; style A fill:yellow,stroke:red');
        $this->assertCount(1, $scene->graph->clusters);
        $this->assertCount(2, $scene->elements);
        [$a, $b] = $scene->graph->nodes;
        // Pinned `triangle`: a square of the padded label width plus the label height.
        $this->assertSame([15.0, 15.0], [$a->w, $a->h]);
        $this->assertSame([15.0, 15.0], [$b->w, $b->h]);
        $this->assertSame("Extract\n漢字", $a->label);
        $this->assertSame('#ffff00', $a->fillColor);
        $edge = $scene->graph->edges[0];
        $this->assertSame($a->x + ($a->w / 4), $edge->points[0][0]);
        $this->assertSame($b->x - ($b->w / 4), $edge->arrowTip[0]);
        $drawing = MermaidDrawing::graph($scene->graph, $scene->shapes);
        \iterator_to_array($drawing);
        foreach ($drawing->getReturn()->marks as $mark) {
            if ('text' !== $mark->kind || !\in_array($mark->text, ['Extract', '漢字', 'File', 'records'], strict: true)) {
                continue;
            }
            $node = \in_array($mark->text, ['Extract', '漢字'], strict: true) ? $a : $b;
            $shape = $scene->shapes[$node->id];
            [$x, $y] = $mark->points[0];
            $half = AnsiUtils::visibleWidth($mark->text) / 2;
            $this->assertTrue(MermaidOutline::contains($node, $shape, $x - $half, $y - 1.5), $mark->text.' fits left');
            $this->assertTrue(MermaidOutline::contains($node, $shape, $x + $half, $y), $mark->text.' fits right');
            $this->assertSame($node === $a ? $a->y + 4.5 : $b->y - 4.5, $y - ('Extract' === $mark->text || 'File' === $mark->text ? -0.5 : 1.5));
        }
    }

    public function testNativeExportsTitlesAndNarrowRowsPreserveTriangleSources(): void
    {
        $source = "---\ntitle: Triangular steps\n---\nflowchart TD\nA@{shape: triangle, label: Extract} --> B@{shape: flipped-triangle, label: File}\nstyle A fill:yellow,stroke:red";
        $svg = NativeFixtureExport::render($source, GraphExportFormat::Svg);
        $this->assertStringContainsString('>Triangular steps</text>', $svg);
        $this->assertStringContainsString('stroke="#ff0000" fill="#ffff00"', $svg);
        $this->assertStringContainsString('>Extract</text>', $svg);
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, GraphExportFormat::Png));
        $rows = NativeFixtureRows::render($source, width: 24);
        $this->assertStringContainsString('Extract', \implode("\n", $rows->rows));
        $this->assertContains(4, $rows->origins);
    }
}
