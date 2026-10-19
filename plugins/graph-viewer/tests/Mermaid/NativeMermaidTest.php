<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\FlowchartParser;
use Porta\GraphViewer\Mermaid\MermaidGraph;
use Porta\GraphViewer\Mermaid\MermaidLexer;
use Porta\GraphViewer\Mermaid\MermaidScene;
use Porta\GraphViewer\Mermaid\MermaidStatement;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\GraphViewer\Mermaid\RankedGraphLayout;
use Porta\GraphViewer\Render\GraphRenderer;
use Porta\Help\Fence\FenceResult;
use Porta\ImageViewer\ZoomPanModel;
use Porta\Tests\Support\FakeScheduler;
use Porta\Ui\Theme\Styles;

final class NativeMermaidTest extends TestCase
{
    private static function graph(string $source): MermaidGraph
    {
        $parser = new FlowchartParser();
        foreach (new MermaidLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    private static function scene(string $source): MermaidScene
    {
        $work = new RankedGraphLayout()->layout(self::graph($source));
        \iterator_to_array($work);

        return $work->getReturn();
    }

    public function testLexicalPositionsCommentsQuotedSeparatorsAndMultilineLabels(): void
    {
        $statements = \iterator_to_array(new MermaidLexer("%% comment\n  graph TD; A[\"漢字; %% \\\"quote\\\"\nline\"] --> B %% trailing\nC\n")->statements());
        $this->assertCount(3, $statements);
        $this->assertSame('graph TD', $statements[0]->text);
        $this->assertSame([13, 1, 2], [$statements[0]->offset, $statements[0]->line, $statements[0]->column]);
        $this->assertStringContainsString('漢字; %%', $statements[1]->text);
        $this->assertSame('C', $statements[2]->text);
        $this->assertSame(3, $statements[2]->line);
        $error = $statements[1]->error('bad', relative: (int) \strpos($statements[1]->text, needle: 'line'));
        $this->assertSame(2, $error->sourceLine);
        $this->assertSame(0, $error->column);
        $this->assertStringContainsString('column 1', $error->getMessage());
        $error = new MermaidStatement('漢字 bad', offset: 10, line: 2, column: 3)->error('bad', relative: 7);
        $this->assertSame([17, 2, 6], [$error->offset, $error->sourceLine, $error->column]);
        $this->assertCount(0, \iterator_to_array(new MermaidLexer('%% last comment')->statements()));
    }

    public function testShapesChainedGroupsLabelsIconsAndExplicitDecoration(): void
    {
        $graph = self::graph(<<<'MMD'
            flowchart LR
            A["Start ❤"] & B((Other)) -->|"go; now"| C{Done} & D>Stop]
            C --> E@{ shape: rect, label: "Unicode 漢字", icon: "fa:cloud" }
            E -. retry .-> A
            A --- B
            A <--> C
            A e1@==> B
            A:::start
            A["Updated #quot;label#quot; #9829;"]
            classDef start fill:#f9f,stroke:#333
            class A,B start
            style C stroke:#333
            linkStyle 0 stroke:blue
            click A href "https://example.com" "A tooltip" _blank
            MMD);
        $this->assertSame('LR', $graph->direction);
        $this->assertCount(5, $graph->nodes);
        $this->assertCount(9, $graph->edges);
        $this->assertSame('Updated "label" ♥', $graph->nodes['A']['label']);
        $this->assertSame('Unicode 漢字', $graph->nodes['E']['label'], 'the icon draws as a tile, never as label text');
        $this->assertSame('fc-icon:b:4.8:4.8', $graph->shapes['E']);
        $this->assertSame('go; now', $graph->edges[0]['label']);
        $this->assertSame('retry', $graph->edges[5]['label']);
        $this->assertSame('.->', $graph->edges[5]['arrow']);
        $this->assertSame(8, $graph->nodes['A']['origin']);
        foreach (['([label])', '[[label]]', '[(label)]', '(((label)))', '{{label}}', '(label)', '[/label\\]', '[\\label/]', '["`label`"]'] as $shape) {
            $this->assertSame('label', self::graph('graph TB; N'.$shape)->nodes['N']['label']);
        }
        $this->assertSame('N', self::graph('graph TB; N@{shape: rect}')->nodes['N']['label']);
    }

    public function testHeaderBodyAndDisconnectedCycles(): void
    {
        $graph = self::graph('graph TB A-->B');
        $this->assertCount(2, $graph->nodes);
        $scene = self::scene('graph TB; A-->B; B-->A; C-->D; D-->C');
        $this->assertSame(4, $scene->graph->nodeCount());
        $this->assertSame(4, $scene->graph->edgeCount());
        $parser = new FlowchartParser();
        $parser->accept(new MermaidStatement('graph TB', offset: 0, line: 0, column: 0));
        $this->expectExceptionMessageIsOrContains('Line 3, column 7: Unclosed ]');
        $parser->accept(new MermaidStatement('A[broken', offset: 9, line: 2, column: 4));
    }

    public function testCompactLinksAndEdgeTextKeepRelationships(): void
    {
        foreach (['---', '--o', '--x', '-.->', '-->'] as $arrow) {
            $graph = self::graph('graph LR; A'.$arrow.'B');
            $this->assertSame(['A', 'B'], \array_keys($graph->nodes));
            $this->assertSame($arrow, $graph->edges[0]['arrow']);
        }
        $graph = self::graph('graph TB; A -->|x > y [units]| B; subgraph S; A; end');
        $this->assertSame('x > y [units]', $graph->edges[0]['label']);
        $this->assertSame(['S'], $graph->nodes['A']['groups']);
    }

    public function testNestingCyclesDisconnectedNumericNodesAndDirectionsTerminate(): void
    {
        $source = "graph TD\nsubgraph outer[Outer]\nsubgraph inner[Inner]\ndirection LR\nA-->B\nB-->A\nend\nend\n1-->2\nZ\nsubgraph empty\nend\nA-->A";
        $graph = self::graph($source);
        $this->assertSame(['outer', 'inner'], $graph->nodes['A']['groups']);
        $this->assertSame('outer', $graph->groups['inner']['parent']);
        $this->assertSame('LR', $graph->groups['inner']['direction']);
        $scene = self::scene($source);
        $this->assertSame(5, $scene->graph->nodeCount());
        $this->assertSame(4, $scene->graph->edgeCount());
        $this->assertCount(3, $scene->graph->clusters);
        $this->assertCount(12, $scene->legend);
        $this->assertGreaterThan(0, $scene->graph->width);
        $this->assertGreaterThan(0, $scene->graph->height);
        foreach (['TB', 'BT', 'LR', 'RL'] as $direction) {
            $nodes = self::scene('graph '.$direction.'; A-->B')->graph->nodes;
            $this->assertTrue(match ($direction) {
                'TB' => $nodes[0]->y < $nodes[1]->y,
                'BT' => $nodes[0]->y > $nodes[1]->y,
                'LR' => $nodes[0]->x < $nodes[1]->x,
                'RL' => $nodes[0]->x > $nodes[1]->x,
            });
        }
        $this->assertSame(0, self::scene('graph TB')->graph->nodeCount());
    }

    public function testNestedSubgraphFramesDirectionsAndForwardLinks(): void
    {
        $source = <<<'MMD'
            flowchart LR
            Before --> Parent --> After
            subgraph Parent[Parent frame]
                direction TB
                subgraph West[West frame]
                    direction RL
                    W1 --> W2
                end
                subgraph South[South frame]
                    direction BT
                    S1 --> S2
                end
                West --> South
            end
            MMD;
        $graph = self::graph($source);
        $this->assertSame(['Before', 'After', 'W1', 'W2', 'S1', 'S2'], \array_keys($graph->nodes));
        $scene = self::scene($source)->graph;
        $nodes = \array_column($scene->nodes, column_key: null, index_key: 'name');
        $groups = \array_column($scene->clusters, column_key: null, index_key: 'name');
        $this->assertSame(6, $scene->nodeCount());
        $this->assertSame(5, $scene->edgeCount());
        $this->assertGreaterThan($nodes['W2']->x, $nodes['W1']->x);
        $this->assertGreaterThan($nodes['S2']->y, $nodes['S1']->y);
        $this->assertLessThan($groups['South']->y0, $groups['West']->y1);
        $this->assertLessThan($groups['Parent']->x0, $nodes['Before']->x);
        $this->assertGreaterThan($groups['Parent']->x1, $nodes['After']->x);
        foreach (['West', 'South'] as $id) {
            $this->assertGreaterThan($groups['Parent']->x0, $groups[$id]->x0);
            $this->assertGreaterThan($groups['Parent']->y0, $groups[$id]->y0);
            $this->assertLessThan($groups['Parent']->x1, $groups[$id]->x1);
            $this->assertLessThan($groups['Parent']->y1, $groups[$id]->y1);
        }
        $this->assertSame($groups['Parent']->x0, $scene->edges[0]->arrowTip[0], 'incoming group link lands on its frame');
        $this->assertSame($groups['Parent']->x1, $scene->edges[1]->points[0][0], 'outgoing group link starts on its frame');
    }

    public function testCrossBoundaryNodeLinksOverrideLocalDirectionButGroupLinksDoNot(): void
    {
        $scene = self::scene(<<<'MMD'
            graph LR
            subgraph Direct
                direction TB
                D1 --> D2
            end
            subgraph Linked
                direction TB
                L1 --> L2
            end
            Outside --> Direct
            Outside --> L1
            MMD)->graph;
        $nodes = \array_column($scene->nodes, column_key: null, index_key: 'name');
        $this->assertLessThan($nodes['D2']->y, $nodes['D1']->y);
        $this->assertSame($nodes['D1']->x, $nodes['D2']->x);
        $this->assertLessThan($nodes['L2']->x, $nodes['L1']->x);
        $this->assertSame($nodes['L1']->y, $nodes['L2']->y);
        // Crossing in either direction inherits the final parent direction.
        $scene = self::scene('graph RL; subgraph G; direction TB; A-->B; end; B-->Outside')->graph;
        $nodes = \array_column($scene->nodes, column_key: null, index_key: 'name');
        $this->assertGreaterThan($nodes['B']->x, $nodes['A']->x);
    }

    public function testEmptyNumericGroupsInheritanceAndFirstClosedMembership(): void
    {
        $scene = self::scene('graph TB; subgraph Outer; subgraph Inner; A-->B; end; direction LR; end; subgraph 1[Empty]; end; 1-->Outer')->graph;
        $nodes = \array_column($scene->nodes, column_key: null, index_key: 'name');
        $groups = \array_column($scene->clusters, column_key: null, index_key: 'name');
        $this->assertLessThan($nodes['B']->x, $nodes['A']->x);
        $this->assertSame(2, $scene->nodeCount());
        $this->assertCount(3, $scene->clusters);
        $this->assertGreaterThan($groups[1]->x0, $groups[1]->x1);
        $graph = self::graph('graph TB; subgraph First; A; end; subgraph Second; A; end; A-->B');
        $this->assertSame(['First'], $graph->nodes['A']['groups']);
        $graph = self::graph('graph TB; subgraph Outer; A; subgraph Inner; A; end; A; end');
        $this->assertSame(['Outer', 'Inner'], $graph->nodes['A']['groups']);
    }

    public function testSiblingOrderContainedLinksAndGroupSourceOrigins(): void
    {
        $scene = self::scene(<<<'MMD'
            graph TB
            subgraph First
                A
            end
            subgraph Second
                B
            end
            subgraph Empty
            end
            First -->|in| A
            B -->|out| Second
            Empty -->|loop| Empty
            MMD);
        $groups = \array_column($scene->graph->clusters, column_key: null, index_key: 'name');
        $nodes = \array_column($scene->graph->nodes, column_key: null, index_key: 'name');
        $this->assertLessThan($groups['Second']->x0, $groups['First']->x1);
        $this->assertLessThan($groups['Empty']->x0, $groups['Second']->x1);
        $first = $scene->graph->edges[0];
        $this->assertSame($groups['First']->y1, $first->points[0][1]);
        $this->assertSame($nodes['A']->y + ($nodes['A']->h / 2), $first->arrowTip[1]);
        $second = $scene->graph->edges[1];
        $this->assertSame($groups['Second']->y1, $second->arrowTip[1]);
        $this->assertSame($nodes['B']->y + ($nodes['B']->h / 2), $second->points[0][1]);
        $this->assertSame(7, $scene->origins[(int) \floor($groups['Empty']->y0 / 2)]);
        foreach ($scene->graph->edges[2]->points as $point) {
            $this->assertTrue($point[0] >= $groups['Empty']->x1 || $point[1] <= $groups['Empty']->y0, 'A self-loop must stay outside its group frame');
        }
        $this->assertSame([$nodes['A']->x, ($groups['First']->y1 + $nodes['A']->y + ($nodes['A']->h / 2)) / 2], $first->labelPos);
        $this->assertGreaterThan($groups['Empty']->x1, $scene->graph->edges[2]->labelPos[0]);
        $this->assertSame('1', self::scene('graph TB; 1')->legend[0]['text']);
        $scene = self::scene('graph TB; subgraph Outer; subgraph Inner; A-->B; end; direction LR; end');
        $this->assertSame('[Inner · LR]', $scene->legend[1]['text']);
        $scene = self::scene('graph LR; subgraph Local; direction TB; A-->B; end; A-->Outside');
        $this->assertSame('[Local · LR]', $scene->legend[0]['text']);
    }

    public function testNativeArrowheadsFollowTheirRenderedDirection(): void
    {
        foreach (['LR' => '▸', 'RL' => '◂', 'TB' => '▾', 'BT' => '▴'] as $direction => $glyph) {
            $graph = self::scene('graph '.$direction.'; A-->B')->graph;
            $view = new ZoomPanModel((int) $graph->width, (int) $graph->height, [1.0]);
            $rows = GraphRenderer::render($graph, $view, null, 60, 30, Styles::dark());
            $this->assertStringContainsString($glyph, \implode("\n", $rows), $direction);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalid(): iterable
    {
        yield 'unknown family' => ['sequenceDiagram', 'Expected flowchart'];
        yield 'unclosed shape' => ['graph TD; A[broken', 'Unclosed'];
        yield 'unclosed quote' => ['graph TD; A["broken]', 'Unclosed'];
        yield 'wrong delimiter' => ['graph TD; A[text}', 'Unexpected closing'];
        yield 'unclosed group' => ["graph TD\nsubgraph A", 'Unclosed subgraph'];
        yield 'unexpected end' => ['graph TD; end', 'Unexpected end'];
        yield 'invalid group' => ['graph TD; subgraph []', 'Invalid subgraph'];
        yield 'unclosed repeated group' => ['graph TD; subgraph A; end; subgraph A', 'Unclosed subgraph'];
        yield 'invalid direction' => ['graph TD; direction XX', 'Expected direction'];
        yield 'no node' => ['graph TD; -->A', 'requires a preceding node'];
        yield 'invalid node identifier' => ['graph TB; ?bad', 'Expected a node identifier'];
        yield 'no edge' => ['graph TD; A nonsense B', 'Expected a flowchart link'];
        yield 'bad class' => ['graph TD; A:::', 'Expected a class'];
        yield 'unclosed pipe' => ['graph TD; A-->|bad B', 'Unclosed |'];
        yield 'bad attributes' => ['graph TD; A@{shape rect}', 'Invalid shape property'];
        yield 'missing comma' => ['graph TD; A@{shape: rect label: Other}', 'Invalid shape property'];
        yield 'bad declaration' => ['graph TD; style A nonsense', 'Expected flowchart style'];
        yield 'empty source' => ['', 'Expected a diagram header'];
        yield 'invalid UTF8' => ["graph TD\n\xFF", 'Not a UTF-8'];
        yield 'too much nesting' => ['graph TD; A'.\str_repeat('[', times: 129), 'depth limit'];
        yield 'subgraph nesting' => ['graph TD; '.\implode(';', \array_map(static fn (int $i): string => 'subgraph G'.$i, \range(0, end: 128))), 'Subgraph nesting'];
        yield 'oversized statement' => ['graph TD; A['.\str_repeat('x', times: 65_537).']', 'Statement exceeds'];
        yield 'oversized source' => [\str_repeat('x', times: 10_485_761), 'source size limit'];
    }

    #[DataProvider('invalid')]
    public function testInvalidSyntaxHasALocatedError(string $source, string $message): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains($message);
        self::graph($source);
    }

    public function testGraphLimitsRejectRatherThanDroppingItems(): void
    {
        $graph = new MermaidGraph();
        $statement = new MermaidStatement('N', offset: 0, line: 0, column: 0);
        for ($i = 0; $i < MermaidGraph::MAX_ITEMS; ++$i) {
            $graph->node('N'.$i, null, $statement, []);
            $graph->edge('N0', 'N0', '', '-->', $statement);
        }
        try {
            $graph->node('overflow', null, $statement, []);
            $this->fail('node limit ignored');
        } catch (MermaidSyntaxError $error) {
            $this->assertStringContainsString('node limit', $error->getMessage());
        }
        $this->expectExceptionMessageIsOrContains('edge limit');
        $graph->edge('N0', 'N0', '', '-->', $statement);
    }

    public function testScheduledBatchesCancellationAndNarrowRowsPreserveSemantics(): void
    {
        $scheduler = new FakeScheduler();
        $renderer = new NativeMermaidRenderer(new MermaidTask($scheduler));
        $source = 'graph TD; '.\implode('; ', \array_map(static fn (int $i): string => 'N'.$i.'-->N'.($i + 1), \range(0, end: 200)));
        $scene = null;
        $cancel = $renderer->render(
            $source,
            static function (MermaidScene $value) use (&$scene): void {
                $scene = $value;
            },
            static function (string $error): never {
                self::fail($error);
            },
        );
        $stale = \array_first($scheduler->scheduled);
        $scheduler->fire();
        $this->assertNull($scene, 'large diagrams yield before completion');
        $cancel();
        $stale();
        $this->assertSame([], $scheduler->scheduled);
        $result = null;
        $renderer->rows(
            'graph LR; A[漢字]-->|message|B',
            width: 4,
            success: static function (FenceResult $value) use (&$result): void {
                $result = $value;
            },
            error: static function (string $error): never {
                self::fail($error);
            },
        );
        $cancel();
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $this->assertInstanceOf(FenceResult::class, $result);
        $this->assertStringContainsString('message', \str_replace("\n", replace: '', subject: \implode("\n", $result->rows)));
        $this->assertContains(0, $result->origins);
        $error = null;
        $renderer->render(
            'graph TD; A[',
            static function (): never {
                self::fail('invalid diagram accepted');
            },
            static function (string $value) use (&$error): void {
                $error = $value;
            },
        );
        $scheduler->fire();
        $this->assertStringContainsString('Unclosed', $error);
        $renderer->cancel();
    }
}
