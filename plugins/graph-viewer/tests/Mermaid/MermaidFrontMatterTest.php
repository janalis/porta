<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphCluster;
use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Graph\GraphFont;
use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\MermaidFrontMatter;
use Porta\GraphViewer\Mermaid\MermaidLexer;
use Porta\GraphViewer\Mermaid\MermaidScene;
use Porta\GraphViewer\Mermaid\MermaidStatement;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;
use Porta\GraphViewer\Mermaid\MermaidTitle;

final class MermaidFrontMatterTest extends TestCase
{
    public function testMaskedPrefixPreservesBytesLinesAndTitleOrigin(): void
    {
        $source = "  ---\r\n  author: 用户\r\n  'title': 'Front #35; title'\r\n  ---\r\nflowchart LR\r\nA-->B";
        $front = MermaidFrontMatter::parse($source);
        $this->assertSame(\strlen($source), \strlen($front->source));
        $this->assertSame('Front # title', $front->title->text);
        $this->assertSame(2, $front->title->line);
        $this->assertSame(2, $front->title->column);
        $this->assertSame(\strpos($source, needle: "'title'"), $front->title->offset);
        $statements = \iterator_to_array(new MermaidLexer($front->source)->statements());
        $this->assertSame([4, 5], \array_column($statements, 'line'));
        $this->assertSame(\strpos($source, needle: 'flowchart'), $statements[0]->offset);
        $this->assertSame('flowchart LR', $statements[0]->text);
        $this->assertSame('graph LR; A---B', MermaidFrontMatter::parse('graph LR; A---B')->source);
        foreach (["---\n---\ninfo", "---\ntitle: ''\n---\ninfo", "---\ntitle: null\n---\ninfo"] as $text) {
            $this->assertNull(MermaidFrontMatter::parse($text)->title);
        }
    }

    public function testIndentedScalarFenceIsNotTheClosingFence(): void
    {
        $front = MermaidFrontMatter::parse("---\ntitle: |-\n  Before\n  ---\n  After\n---\npie\n\"One\": 1");
        $this->assertSame("Before\n---\nAfter", $front->title->text);
        $this->assertStringEndsWith("pie\n\"One\": 1", $front->source);
    }

    public function testMappingIndentMayExceedFenceIndentAfterCommentsAndBlankLines(): void
    {
        foreach (['', '  '] as $fence) {
            $indent = $fence.'  ';
            $source = $fence."---\n".$fence."# comment\n\n".$indent."author: Other\n".$indent."title: Indented root\n".$fence."---\nflowchart LR; A";
            $front = MermaidFrontMatter::parse($source);
            $this->assertSame('Indented root', $front->title->text);
            $this->assertSame(4, $front->title->line);
            $this->assertSame(\strlen($indent), $front->title->column);
            $this->assertSame(\strpos($source, needle: 'title:'), $front->title->offset);
            $this->assertSame(\strlen($source), \strlen($front->source));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function families(): iterable
    {
        yield 'flowchart' => ['flowchart LR; subgraph Team; A-->B; end'];
        yield 'sequence' => ['sequenceDiagram; A->>B: Hello'];
        yield 'pie' => ["pie showData\n\"One\": 1"];
        yield 'ER' => ["erDiagram\nA ||--o{ B : owns"];
        yield 'class' => ["classDiagram\nA <|-- B"];
        yield 'railroad' => ["railroad-beta\nx=terminal(\"A\");"];
        yield 'railroad EBNF' => ["railroad-ebnf-beta\nx=\"A\";"];
        yield 'railroad ABNF' => ["railroad-abnf-beta\nx=\"A\";"];
        yield 'railroad PEG' => ["railroad-peg-beta\nx<-\"A\";"];
        yield 'venn' => ["venn-beta\nset A"];
        yield 'eventmodeling' => ["eventmodeling\ntf 01 ui Screen"];
        yield 'architecture' => ["architecture-beta\nservice a[First]"];
        yield 'usecase' => ["usecase-beta\nactor A"];
        yield 'gantt' => ["gantt\nA:2024-01-01, 1d"];
        yield 'agentflow' => ["agentflow-beta LR\nflow Agent\nA[\"First\"]@{shape: task}\nend"];
        yield 'swimlane' => ["swimlane-beta LR\nsubgraph Team\nA[\"A\"]\nend"];
        yield 'block' => ["block\nA[\"A\"]"];
        yield 'c4' => ["C4Context\nSystem(a,\"A\")"];
        yield 'wardley' => ["wardley-beta\ncomponent First [0.8,0.2]"];
        yield 'git' => ["gitGraph\ncommit id:'A'"];
        yield 'state' => ["stateDiagram-v2\n[*] --> A\nA --> [*]"];
        yield 'timeline' => ["timeline\nNow : Event"];
        yield 'journey' => ["journey\nTask: 5: Actor"];
        yield 'mindmap' => ["mindmap\n  root((Root))\n    Child"];
        yield 'requirement' => ["requirementDiagram\nrequirement Safe {}"];
        yield 'cynefin' => ["cynefin-beta\ncomplex\n\"Explore\""];
        yield 'radar' => ["radar-beta\naxis A, B, C\ncurve S{1,2,3}"];
        yield 'ishikawa' => ["ishikawa\nEffect\nCause"];
        yield 'tree view' => ["treeView-beta\nRoot/\n  Child"];
        yield 'treemap' => ["treemap\n\"Root\"\n  \"Leaf\":4"];
        yield 'sankey' => ["sankey\nSource,Target,10"];
        yield 'kanban' => ["kanban\nTodo\n  First"];
        yield 'packet' => ["packet\n0-15: \"Source\""];
        yield 'xy' => ["xychart\nx-axis [A, B]\nline [10,20]"];
        yield 'quadrant' => ["quadrantChart\nA: [0.75, 0.93]"];
    }

    #[DataProvider('families')]
    public function testFrontMatterTitleRendersWithoutBecomingSelectable(string $body): void
    {
        $source = "---\ntitle: Shared caption\n---\n".$body;
        $rows = NativeFixtureRows::render($source, width: 30);
        $this->assertStringContainsString('Shared caption', \implode("\n", $rows->rows));
        $this->assertContains(1, $rows->origins);
        $svg = NativeFixtureExport::render($source, GraphExportFormat::Svg);
        $this->assertStringContainsString('Shared caption', $svg, 'SVG contains the front matter title');
    }

    public function testBodyTitlesOverrideFrontMatterAndInfoKeepsItsDiagnostic(): void
    {
        foreach (["pie title Body caption\n\"One\":1", 'sequenceDiagram; title Body caption; A->>B: Hi'] as $body) {
            $rows = NativeFixtureRows::render("---\ntitle: Front caption\n---\n".$body, width: 50);
            $text = \implode("\n", $rows->rows);
            $this->assertStringContainsString('Body caption', $text);
            $this->assertStringNotContainsString('Front caption', $text);
        }
        $rows = NativeFixtureRows::render("---\ntitle: Front caption\n---\ninfo", width: 50);
        $this->assertStringNotContainsString('Front caption', \implode("\n", $rows->rows));
    }

    public function testCaptionMovesEveryPrimitiveAndPreservesSelectionAndPresentation(): void
    {
        $node = new GraphNode(
            7,
            'A',
            'Alias',
            5,
            6,
            8,
            4,
            '#123456',
            '#abcdef',
            dashed: true,
            background: true,
            strokeWidth: 0.3,
            drawLabel: 'Draw',
            textColor: '#112233',
            font: GraphFont::BoldItalic,
        );
        $edge = new GraphEdge(7, 7, [[1.0, 2.0], [3.0, 4.0]], [3.0, 4.0], 1, 2, 3, 4, '#445566', 'Loop', [2.0, 3.0], dashed: true, ribbon: [[1.0, 2.0], [3.0, 4.0], [1.0, 4.0]], decoration: true);
        $cluster = new GraphCluster('team', 'Team', 0, 0, 10, 10, '#654321', [5.0, 1.0], font: GraphFont::Italic);
        $scene = new MermaidScene(
            new GraphLayout(
                10,
                12,
                [7 => $node],
                [$edge, new GraphEdge(7, 7, [], null, 0, 0, 0, 0, null, null, null, dashed: false)],
                [$cluster, new GraphCluster('empty', '', 0, 0, 0, 0, null, null)],
            ),
            [12, 13],
            [['text' => 'Existing', 'origin' => 12]],
            [7 => 12],
            [7 => 'diamond'],
        );
        $work = MermaidTitle::apply($scene, new MermaidStatement('A long caption', offset: 0, line: 1, column: 0));
        \iterator_to_array($work);
        $result = $work->getReturn();
        $dy = $result->graph->height - $scene->graph->height;
        $dx = ($result->graph->width - $scene->graph->width) / 2;
        $this->assertGreaterThan(0, $dx);
        $this->assertSame(\array_replace(\get_object_vars($node), ['x' => $node->x + $dx, 'y' => $node->y + $dy]), \get_object_vars($result->graph->nodes[7]));
        $moved = $result->graph->edges[0];
        $this->assertSame([[1.0 + $dx, 2.0 + $dy], [3.0 + $dx, 4.0 + $dy]], $moved->points);
        $this->assertSame([3.0 + $dx, 4.0 + $dy], $moved->arrowTip);
        $this->assertSame([2.0 + $dx, 3.0 + $dy], $moved->labelPos);
        $this->assertSame([[1.0 + $dx, 2.0 + $dy], [3.0 + $dx, 4.0 + $dy], [1.0 + $dx, 4.0 + $dy]], $moved->ribbon);
        $this->assertSame([1.0 + $dx, 2.0 + $dy, 3.0 + $dx, 4.0 + $dy], [$moved->minX, $moved->minY, $moved->maxX, $moved->maxY]);
        $this->assertSame(['Loop', '#445566', true, 7, 7], [$moved->label, $moved->color, $moved->dashed, $moved->tail, $moved->head]);
        $this->assertTrue($moved->decoration);
        $this->assertSame(1, $result->graph->edgeCount());
        $this->assertSame(\array_replace(\get_object_vars($cluster), [
            'x0' => $dx,
            'x1' => 10 + $dx,
            'y0' => $dy,
            'y1' => 10 + $dy,
            'labelPos' => [5.0 + $dx, 1.0 + $dy],
        ]), \get_object_vars($result->graph->clusters[0]));
        $this->assertNull($result->graph->clusters[1]->labelPos);
        $this->assertNull($result->graph->edges[1]->arrowTip);
        $this->assertNull($result->graph->edges[1]->labelPos);
        $this->assertNull($result->graph->edges[1]->ribbon);
        $this->assertSame([7 => 12], $result->elements);
        $this->assertSame([7], \array_keys($result->selectionGraph->nodes));
        $this->assertSame('text', $result->shapes[8]);
        $this->assertNull($result->selectable(8));
        $this->assertSame([12, 13], \array_slice($result->origins, offset: (int) ($dy / 2)));
        $this->assertSame(1, $result->origins[0]);
        $this->assertSame([['text' => 'A long caption', 'origin' => 1], ['text' => 'Existing', 'origin' => 12]], $result->legend);
        $empty = MermaidTitle::apply(new MermaidScene(new GraphLayout(0, 0, [], [], []), [], []), new MermaidStatement('Empty', offset: 0, line: 1, column: 0));
        \iterator_to_array($empty);
        $this->assertSame('Empty', $empty->getReturn()->graph->nodes[0]->label);
    }

    public function testLongTitlesWrapCooperatively(): void
    {
        $text = \str_repeat('用户', times: 200);
        $work = MermaidTitle::apply(new MermaidScene(new GraphLayout(10, 10, [], [], []), [], []), new MermaidStatement($text, offset: 0, line: 1, column: 0));
        $this->assertTrue($work->valid(), 'long title wrapping yields before completion');
        \iterator_to_array($work);
        $caption = $work->getReturn()->graph->nodes[0];
        $this->assertStringContainsString("\n", $caption->label);
        $this->assertSame($text, \str_replace("\n", replace: '', subject: $caption->label));
        $this->assertLessThanOrEqual(120, $caption->w);
        $this->assertGreaterThan(6, $caption->h);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalid(): iterable
    {
        yield 'unclosed' => ["---\ntitle: missing\nflowchart LR; A", 'Unclosed Mermaid front matter'];
        yield 'size' => ["---\ntitle: ".\str_repeat('x', times: 65_536)."\n---\ninfo", 'Front matter exceeds the size limit'];
        yield 'yaml' => ["---\ntitle: [\n---\ninfo", 'Invalid Mermaid YAML front matter'];
        yield 'root list' => ["---\n- item\n---\ninfo", 'must be a mapping'];
        yield 'title type' => ["---\ntitle: 12\n---\ninfo", 'title must be a string'];
        yield 'display mode' => ["---\ndisplayMode: 1\n---\ninfo", 'displayMode must be text'];
        yield 'reference' => ["---\nother: &name Test\ntitle: *name\n---\ninfo", 'Invalid Mermaid YAML front matter'];
    }

    #[DataProvider('invalid')]
    public function testInvalidMetadataIsLocatedAndNeverIgnored(string $source, string $message): void
    {
        try {
            MermaidFrontMatter::parse($source);
            $this->fail('Invalid front matter accepted');
        } catch (MermaidSyntaxError $error) {
            $this->assertStringContainsString($message, $error->getMessage());
            $this->assertSame(0, $error->sourceLine);
        }
    }
}
