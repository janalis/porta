<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\AgentflowDiagram;
use Porta\GraphViewer\Mermaid\AgentflowLayout;
use Porta\GraphViewer\Mermaid\AgentflowLexer;
use Porta\GraphViewer\Mermaid\AgentflowMetadata;
use Porta\GraphViewer\Mermaid\AgentflowParser;
use Porta\GraphViewer\Mermaid\MermaidOutline;
use Porta\GraphViewer\Mermaid\MermaidScene;
use Porta\GraphViewer\Mermaid\MermaidStatement;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;

final class AgentflowTest extends TestCase
{
    private static function parse(string $source): AgentflowDiagram
    {
        $parser = new AgentflowParser();
        foreach (new AgentflowLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    private static function scene(AgentflowDiagram $diagram): MermaidScene
    {
        $work = new AgentflowLayout()->layout($diagram);
        \iterator_to_array($work);

        return $work->getReturn();
    }

    public static function fixtures(): iterable
    {
        foreach (\glob(__DIR__.'/../fixtures/mermaid/agentflow/*.mmd') as $path) {
            yield \basename($path) => [$path];
        }
    }

    #[DataProvider('fixtures')]
    public function testPinnedBodiesRender(string $path): void
    {
        $source = \preg_replace('/\A---\R.*?\R---\R/s', replacement: '', subject: \file_get_contents($path));
        $this->assertNotEmpty(NativeFixtureRows::render($source, width: 100)->rows);
    }

    public function testGlobalsRemainRootAndNestedFlowKeepsItsChildren(): void
    {
        $diagram = self::parse("agentflow-beta\nflow outer\n flow inner\n A-->B\n end\nend\nglobal\n A\n flow shared\n C-->D\n end\nend\nflow consumer\n A-->C\nend");
        $this->assertSame([], $diagram->graph->nodes['A']['groups']);
        $this->assertSame(['outer', 'inner'], $diagram->graph->nodes['B']['groups']);
        $this->assertSame(['shared'], $diagram->graph->nodes['C']['groups']);
        $this->assertNull($diagram->graph->groups['shared']['parent']);
    }

    public function testMetadataQuotingMergingAndLocations(): void
    {
        $diagram = self::parse(
            "agentflow-beta LR\n A[\"Input\"]@{ shape: input, description: 'literal } ; %%', nested: { constructor: bad, keep: yes } }-->B@{shape: task}\n A@{ label: 'New', model: x }",
        );
        $this->assertSame('lean-right', $diagram->graph->shapes['A']);
        $this->assertSame('New', $diagram->graph->nodes['A']['label']);
        $this->assertSame('literal } ; %%', $diagram->metadata['A']['description']);
        $this->assertSame(['keep' => 'yes'], $diagram->metadata['A']['nested']);
        $this->assertSame('x', $diagram->metadata['A']['model']);
        $this->assertSame('roundedRect', $diagram->graph->shapes['B']);
        $this->assertCount(1, $diagram->graph->edges);
    }

    public function testCollapseRedirectsCrossingsAndPreservesRealLoops(): void
    {
        $diagram = self::parse("agentflow-beta\nflow outer\n flow inner\n A-->B\n A-->A\n end\nend\nouter@{view: collapsed}\nX-->A\nB-->Y");
        $scene = self::scene($diagram);
        $labels = \array_column($scene->graph->nodes, 'label');
        $this->assertContains('outer', $labels);
        $this->assertContains('X', $labels);
        $this->assertNotContains('A', $labels);
        $this->assertNotContains('inner', $labels);
        $this->assertSame(3, $scene->graph->edgeCount());
        $pairs = [];
        foreach ($scene->graph->edges as $edge) {
            if ($edge->decoration) {
                continue;
            }
            $pairs[] = [$scene->graph->nodes[$edge->tail]->name, $scene->graph->nodes[$edge->head]->name];
        }
        $this->assertSame([['outer', 'outer'], ['X', 'outer'], ['outer', 'Y']], $pairs);
        $this->assertCount(2, $diagram->graph->groups);
        $this->assertArrayHasKey('A', $diagram->graph->nodes);
    }

    public function testNamedEdgeMetadataDoesNotCreateAVertex(): void
    {
        $diagram = self::parse("agentflow-beta\n A&B edge@-->|caption|C&D\nedge@{ description: 'contract' }\nedge@{ retry: 2 }");
        $this->assertSame(['edge' => 2], $diagram->graph->edgeIds);
        $this->assertArrayNotHasKey('edge', $diagram->graph->nodes);
        $this->assertSame([2 => ['description' => 'contract', 'retry' => 2]], $diagram->edgeMetadata);
    }

    public function testInlineFlowMetadataAppliesAfterClosingAndGlobalsReleaseNestedFlows(): void
    {
        $diagram = self::parse(
            "agentflow-beta\n flow parent\n global\n flow inner@{model: header}\n A-->B\n inner@{model: body}\n end\n end\n end\nflow connectors@{view: collapsed}\n X\nend\nconnectors@{description: owned}",
        );
        $this->assertSame('header', $diagram->metadata['inner']['model']);
        $this->assertNull($diagram->graph->groups['inner']['parent']);
        $this->assertSame(['inner'], $diagram->graph->nodes['A']['groups']);
        $this->assertSame('owned', $diagram->metadata['connectors']['description']);
        $scene = self::scene($diagram);
        $this->assertNotEmpty($scene->elements);
    }

    public function testAnonymousFlowsStylesAndEmptyStatements(): void
    {
        $parser = new AgentflowParser();
        $parser->accept(new MermaidStatement('', 0, 0, 0));
        $diagram = self::parse(
            "agentflow-beta TD\nflow\n direction LR\n A-->B\n classDef hot fill:red\n class A hot\n style B stroke:blue\n linkStyle 0 stroke:green\nend\nconnectors@{ignored: true}",
        );
        $this->assertSame('TB', $diagram->graph->direction);
        $this->assertSame('LR', $diagram->graph->groups['__agentflow_1']['direction']);
        $this->assertArrayNotHasKey('connectors', $diagram->metadata);
        $scene = self::scene($diagram);
        $this->assertContains('#ff0000', \array_column($scene->graph->nodes, 'fillColor'));
        $this->assertSame('#008000', $scene->graph->edges[0]->color);
    }

    public function testTrailingCommasPreserveQuotedFlowAndBlockContent(): void
    {
        $source = <<<'MMD'
            agentflow-beta
            A@{
              note: 'first,
                second',
              tools: ["read",
                "write"],
              instruction: |
                Do a thing,
                then stop
              protocol: "mcp", # comment
            }
            MMD;
        $metadata = self::parse($source)->metadata['A'];
        $this->assertSame('first, second', $metadata['note']);
        $this->assertSame(['read', 'write'], $metadata['tools']);
        $this->assertSame("Do a thing,\nthen stop\n", $metadata['instruction']);
        $this->assertSame('mcp', $metadata['protocol']);
    }

    public static function malformed(): iterable
    {
        yield 'empty' => ['', 'header'];
        yield 'header' => ['agentflow-beta XX', 'optional direction'];
        yield 'end' => ["agentflow-beta\nend", 'Unexpected end'];
        yield 'unclosed' => ["agentflow-beta\nflow f\nA", 'Unclosed'];
        yield 'metadata target' => ["agentflow-beta\n@{a: b}", 'requires'];
        yield 'global metadata' => ["agentflow-beta\nglobal\n@{a: b}\nend", 'requires'];
        yield 'nonstring shape' => ["agentflow-beta\nA@{shape: 2}", 'lower-case'];
        yield 'uppercase shape' => ["agentflow-beta\nA@{shape: Tool}", 'lower-case'];
        yield 'unknown shape' => ["agentflow-beta\nA@{shape: unknown}", 'Unknown'];
        yield 'yaml' => ["agentflow-beta\nA@{value: *missing}", 'Invalid agentflow metadata'];
        yield 'yaml retry' => ["agentflow-beta\nA@{\n value: *missing,\n}", 'Invalid agentflow metadata'];
        yield 'nonmap' => ["agentflow-beta\nA@{\n - item\n}", 'mapping'];
        yield 'nesting' => ["agentflow-beta\n".\str_repeat("global\n", times: 129), 'depth limit'];
    }

    #[DataProvider('malformed')]
    public function testMalformedInputsAreLocated(string $source, string $message): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains($message);
        self::parse($source);
    }

    public function testMetadataResourceLimits(): void
    {
        $statement = new MermaidStatement('@{items: ['.\str_repeat('x,', times: 4096).'x]}', 0, 0, 0);
        $this->expectExceptionMessageIsOrContains('item limit');
        AgentflowMetadata::parse($statement);
    }

    public function testMetadataDepthLimit(): void
    {
        $body = '@{nested: '.\str_repeat('[', times: 130).'x'.\str_repeat(']', times: 130).'}';
        $this->expectExceptionMessageIsOrContains('nesting depth');
        AgentflowMetadata::parse(new MermaidStatement($body, 0, 0, 0));
    }

    public function testNestedCollapsedSummaryStaysInsideItsExpandedParent(): void
    {
        $diagram = self::parse("agentflow-beta\nflow parent\n flow child\n A-->B\n end\nend\nchild@{view: collapsed}\nX-->B");
        $scene = self::scene($diagram);
        $this->assertContains('child', \array_column($scene->graph->nodes, 'label'));
        $this->assertContains('parent', \array_column($scene->graph->clusters, 'label'));
        $this->assertNotContains('A', \array_column($scene->graph->nodes, 'label'));
    }

    public function testHeadersAreCompactSelectableAndLinkedToDeclarations(): void
    {
        $scene = self::scene(self::parse("agentflow-beta\nflow Team\n A-->B\nend"));
        $header = $scene->graph->nodes[-1];
        $this->assertSame('Team', $header->label);
        $this->assertSame('', $header->drawLabel);
        $this->assertSame(1, $scene->origin(-1));
        $this->assertSame(-1, $scene->onLine(1));
        $this->assertSame('text', $scene->shapes[-1]);
        $this->assertLessThan($scene->graph->clusters[0]->y1 - $scene->graph->clusters[0]->y0, $header->h);
    }

    public function testReferenceDocumentHasALinedWaveOutline(): void
    {
        $scene = self::scene(self::parse("agentflow-beta\n A@{shape: refdoc}"));
        $node = \array_first($scene->graph->nodes);
        $points = MermaidOutline::points($node, $scene->shapes[$node->id]);
        $this->assertCount(35, $points);
        $this->assertGreaterThan($points[1][1], $points[9][1]);
        $this->assertLessThan($points[1][1], $points[25][1]);
        $this->assertCount(2, MermaidOutline::marks($node, 'lin-doc'));
    }
}
