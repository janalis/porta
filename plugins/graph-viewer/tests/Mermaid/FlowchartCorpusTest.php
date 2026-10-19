<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Mermaid\FlowchartLayout;
use Porta\GraphViewer\Mermaid\FlowchartParser;
use Porta\GraphViewer\Mermaid\MermaidCardRoutes;
use Porta\GraphViewer\Mermaid\MermaidColor;
use Porta\GraphViewer\Mermaid\MermaidGraph;
use Porta\GraphViewer\Mermaid\MermaidGraphEdges;
use Porta\GraphViewer\Mermaid\MermaidLexer;
use Porta\GraphViewer\Mermaid\MermaidStatement;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;
use Porta\GraphViewer\Mermaid\RankedGraphLayout;

final class FlowchartCorpusTest extends TestCase
{
    private static function parse(string $source): MermaidGraph
    {
        $parser = new FlowchartParser();
        foreach (new MermaidLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    public static function fixtures(): iterable
    {
        foreach (\glob(__DIR__.'/../fixtures/mermaid/flowchart/*.mmd') as $path) {
            yield \basename($path) => [$path];
        }
    }

    #[DataProvider('fixtures')]
    public function testPinnedBodiesRenderToRowsAndSvg(string $path): void
    {
        $source = \file_get_contents($path);
        $source = \preg_replace('/\A---\R.*?\R---\R/s', replacement: '', subject: $source);
        $source = \preg_replace('/%%\{.*?\}%%/s', replacement: '', subject: $source);
        $this->assertNotEmpty(NativeFixtureRows::render($source, width: 100)->rows);
        $this->assertStringContainsString('<svg', NativeFixtureExport::render($source, GraphExportFormat::Svg));
    }

    public function testHeaderDefaultsRootDirectionsAndLabelsOnOpenOrCompactLinks(): void
    {
        $graph = self::parse(<<<'MMD'
            flowchart
            direction LR
            A -- open label --- B
            A --"`Quoted **label**`"-->C
            B .-> C
            C <.-> D
            D ======> E
            E ~~~ F
            MMD);
        $this->assertSame('LR', $graph->direction);
        $this->assertSame(['open label', 'Quoted label', '', '', '', ''], \array_column($graph->edges, 'label'));
        $this->assertSame(['---', '-->', '.->', '<.->', '======>', '~~~'], \array_column($graph->edges, 'arrow'));
        $work = new RankedGraphLayout()->layout($graph);
        \iterator_to_array($work);
        $scene = $work->getReturn();
        $this->assertSame(5, $scene->graph->edgeCount());
        $this->assertNull($scene->graph->edges[0]->arrowTip);
        $this->assertNotNull($scene->graph->edges[1]->arrowTip);
        $nodes = \array_column($scene->graph->nodes, null, 'name');
        $this->assertGreaterThan($nodes['E']->x, $nodes['F']->x, 'Invisible links still constrain placement');
        $this->assertSame('TB', self::parse("graph\nA")->direction);
        $this->assertSame('TB', self::parse("flowchart-elk TD\nA")->direction);
    }

    public function testMultilineLinksKeepTheirCurrentSourceStatement(): void
    {
        $graph = self::parse("graph TB\nA[\"漢字\"]\n  --> B[\"More\"]\n  --> C");
        $this->assertSame(['A', 'B'], \array_column($graph->edges, 'from'));
        $this->assertSame(['B', 'C'], \array_column($graph->edges, 'to'));
        $this->assertSame([2, 3], \array_column($graph->edges, 'origin'));
        $this->assertSame([1, 2, 3], \array_column($graph->nodes, 'origin'));
        $this->expectException(MermaidSyntaxError::class);
        self::parse("graph TB\n--> A");
    }

    public function testRepeatedGroupsMergeAndNestedRedeclarationsFlatten(): void
    {
        $graph = self::parse(<<<'MMD'
            flowchart LR
            subgraph G[First]
            direction TB
            A
            end
            subgraph G[Second]
            direction RL
            subgraph Child
            B
            end
            C
            end
            MMD);
        $this->assertSame(['G', 'Child'], \array_keys($graph->groups));
        $this->assertSame('First', $graph->groups['G']['label']);
        $this->assertSame('TB', $graph->groups['G']['direction']);
        $this->assertSame(1, $graph->groups['G']['origin']);
        $this->assertSame(['G'], $graph->nodes['A']['groups']);
        $this->assertSame(['G', 'Child'], $graph->nodes['B']['groups']);
        $this->assertSame(['G'], $graph->nodes['C']['groups']);
        $nested = self::parse("graph TB\nsubgraph G[Outer]\nA\nsubgraph G[Inner]\nB\nend\nC\nend");
        $this->assertCount(1, $nested->groups);
        $this->assertSame('Inner', $nested->groups['G']['label'], 'The first closed declaration owns the drawn record');
        $this->assertNull($nested->groups['G']['parent']);
        $this->assertSame([['G'], ['G'], ['G']], \array_column($nested->nodes, 'groups'));
    }

    public function testFontSizesResizeCardsFramesAndCaptionsAndSurviveTitles(): void
    {
        $source = <<<'MMD'
            graph LR
            classDef large font-size:32px,font-weight:bold
            subgraph G[Scaled header]
            A["Wide label<br>Second"]:::large
            B["Wide label<br>Second"]
            A -->|Caption| B
            end
            style G font-size:24px
            linkStyle 0 font-size:20px
            MMD;
        $work = new RankedGraphLayout()->layout(self::parse($source));
        \iterator_to_array($work);
        $scene = $work->getReturn();
        $nodes = \array_column($scene->graph->nodes, null, 'name');
        $this->assertSame(2.0, $nodes['A']->fontScale);
        $this->assertSame(1.0, $nodes['B']->fontScale);
        $this->assertSame((($nodes['B']->w - 4) * 2) + 4, $nodes['A']->w);
        $this->assertSame((($nodes['B']->h - 4) * 2) + 4, $nodes['A']->h);
        $this->assertSame(1.5, $scene->graph->clusters[0]->fontScale);
        $this->assertSame(1.25, $scene->graph->edges[0]->fontScale);
        $smaller = new RankedGraphLayout()->layout(self::parse(\str_replace('style G font-size:24px', replace: 'style G font-size:16px', subject: $source)));
        \iterator_to_array($smaller);
        $frame = $scene->graph->clusters[0];
        $smallFrame = $smaller->getReturn()->graph->clusters[0];
        $this->assertEqualsWithDelta(1.0, $frame->y1 - $frame->y0 - ($smallFrame->y1 - $smallFrame->y0), delta: 0.001);

        $svg = NativeFixtureExport::render("---\ntitle: Caption above scene\n---\n".$source, GraphExportFormat::Svg);
        foreach (['3.2px', '2.4px', '2px'] as $size) {
            $this->assertStringContainsString('style="font-size:'.$size.'"', $svg);
        }
        $loop = new RankedGraphLayout()->layout(self::parse("graph LR\nA -->|Larger loop label| A\nlinkStyle 0 font-size:32px"));
        \iterator_to_array($loop);
        $routes = MermaidCardRoutes::layout($loop->getReturn()->graph);
        \iterator_to_array($routes);
        $graph = $routes->getReturn();
        $edge = $graph->edges[0];
        $this->assertSame(2.0, $edge->fontScale);
        $this->assertNotNull($edge->labelPos);
        $this->assertGreaterThan($edge->labelPos[0] + \strlen($edge->label), $graph->width);
        $this->assertGreaterThan($edge->labelPos[1] + 2, $graph->height);
    }

    public function testNativeRowsPreserveBothArrowDirections(): void
    {
        foreach (['LR' => ['▸', '◂'], 'RL' => ['▸', '◂'], 'TB' => ['▾', '▴'], 'BT' => ['▾', '▴']] as $direction => $glyphs) {
            $rows = NativeFixtureRows::render('graph '.$direction."\nA <--> B", width: 80);
            $text = \implode("\n", $rows->rows);
            foreach ($glyphs as $glyph) {
                $this->assertStringContainsString($glyph, $text, $direction);
            }
        }
    }

    public function testEndpointGlyphsPreserveAuthoredEdgeStylesAndRelationshipCounts(): void
    {
        $source = "graph LR\nA o--o B\nA x--x C\nB <--> C\nC ==>|Styled| D\nlinkStyle 3 stroke-width:5px,color:red,font-size:20px";
        $work = new FlowchartLayout()->layout(self::parse($source));
        \iterator_to_array($work);
        $graph = $work->getReturn()->graph;
        $this->assertSame(4, $graph->edgeCount());
        $glyphs = \array_values(\array_filter($graph->edges, static fn (GraphEdge $edge): bool => $edge->decoration));
        $edges = \array_values(\array_filter($graph->edges, static fn (GraphEdge $edge): bool => !$edge->decoration));
        $this->assertCount(7, $glyphs);
        $this->assertCount(25, $glyphs[0]->points);
        $this->assertCount(2, $glyphs[2]->points);
        $this->assertNotNull($glyphs[6]->arrowTip);
        $this->assertNotSame($glyphs[6]->arrowTip, $glyphs[6]->points[1]);
        $this->assertLessThanOrEqual(
            1.000_001,
            \hypot($glyphs[6]->points[0][0] - $glyphs[6]->arrowTip[0], $glyphs[6]->points[0][1] - $glyphs[6]->arrowTip[1]),
            'An arrow-only glyph must not repaint a dashed shaft',
        );

        $this->assertNull($edges[0]->arrowTip);
        $this->assertNull($edges[1]->arrowTip);
        $this->assertNotNull($edges[2]->arrowTip);
        $this->assertNotSame($edges[2]->arrowTip, $edges[2]->points[\count($edges[2]->points) - 1]);
        $this->assertSame(0.5, $edges[3]->strokeWidth);
        $this->assertSame('#ff0000', $edges[3]->textColor);
        $this->assertSame(1.25, $edges[3]->fontScale);
        $svg = NativeFixtureExport::render($source, GraphExportFormat::Svg);
        $this->assertStringContainsString('stroke-width="0.5"', $svg);
        $points = \implode(' ', \array_map(static fn (array $point): string => $point[0].','.$point[1], $glyphs[0]->points));
        $this->assertStringContainsString('<polyline points="'.$points.'"', $svg, 'Native dispatch must retain the circular endpoint scene');
        $thick = new RankedGraphLayout()->layout(self::parse('graph LR; A ==> B'));
        \iterator_to_array($thick);
        $this->assertSame(0.4, $thick->getReturn()->graph->edges[0]->strokeWidth);

        $this->assertStringContainsString('fill="#ff0000" style="font-size:2px">Styled</text>', $svg);
    }

    public function testExtendedLinksReserveAdditionalRanksInEveryDirection(): void
    {
        foreach (['LR', 'RL', 'TB', 'BT'] as $direction) {
            $distances = [];
            foreach (['-->', '---->', '-- label ---->', '~~~~~', '-...->'] as $arrow) {
                $work = new RankedGraphLayout()->layout(self::parse('graph '.$direction."\nA ".$arrow.' B'));
                \iterator_to_array($work);
                $nodes = \array_column($work->getReturn()->graph->nodes, null, 'name');
                $distances[] = \hypot($nodes['B']->x - $nodes['A']->x, $nodes['B']->y - $nodes['A']->y);
            }
            $this->assertGreaterThan($distances[0], $distances[1], $direction);
            $this->assertSame($distances[1], $distances[2], 'A label opener does not lengthen its link');
            $this->assertSame($distances[1], $distances[3]);
            $this->assertSame($distances[1], $distances[4]);
        }
        $this->assertSame(1, MermaidGraphEdges::rankSpan('<-->'));
    }

    public function testLargeEdgeCaptionsGrowTheCanvasInEveryDirection(): void
    {
        $work = new RankedGraphLayout()->layout(self::parse("graph TB\nA -->|A large edge caption| B\nlinkStyle 0 font-size:64px"));
        \iterator_to_array($work);
        $graph = $work->getReturn()->graph;
        $edge = $graph->edges[0];
        $this->assertNotNull($edge->labelPos);
        $half = (\strlen($edge->label) * $edge->fontScale) / 2;
        $this->assertGreaterThanOrEqual(0, $edge->labelPos[0] - $half);
        $this->assertLessThanOrEqual($graph->width, $edge->labelPos[0] + $half);
        $this->assertGreaterThanOrEqual(0, $edge->labelPos[1] - $edge->fontScale);
        $this->assertLessThanOrEqual($graph->height, $edge->labelPos[1] + $edge->fontScale);
    }

    public function testUniqueGroupLimitStillAppliesWhenRepeatedDeclarationsCanMerge(): void
    {
        $source = "graph TB\n".\implode("\n", \array_map(static fn (int $index): string => 'subgraph group'.$index."\nend", \range(0, end: 4096)));
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('group limit');
        self::parse($source);
    }

    public function testCssNamesAndBackgroundAliasesReachExportedGraphics(): void
    {
        $source = "graph LR\nA --> B\nstyle A background:darkred,border:2px solid silver\nstyle B background-color:greenyellow,border-color:rebeccapurple,border-width:3px";
        $svg = NativeFixtureExport::render($source, GraphExportFormat::Svg);
        foreach (['#8b0000', '#c0c0c0', '#adff2f', '#663399'] as $color) {
            $attribute = \in_array($color, ['#8b0000', '#adff2f'], strict: true) ? 'fill' : 'stroke';
            $this->assertStringContainsString($attribute.'="'.$color.'"', $svg);
        }
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, GraphExportFormat::Png));
        $statement = new MermaidStatement('', offset: 0, line: 0, column: 0);
        $this->assertSame('#ffefd5', MermaidColor::parse('fill', ' PapayaWhip ', $statement, family: 'flowchart'));
        $this->assertSame('#00ffff', MermaidColor::parse('color', 'aqua', $statement, family: 'flowchart'));
    }
}
