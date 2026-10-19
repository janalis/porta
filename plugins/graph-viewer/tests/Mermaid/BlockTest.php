<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Mermaid\BlockArrows;
use Porta\GraphViewer\Mermaid\BlockDiagram;
use Porta\GraphViewer\Mermaid\BlockLayout;
use Porta\GraphViewer\Mermaid\BlockLexer;
use Porta\GraphViewer\Mermaid\BlockParser;
use Porta\GraphViewer\Mermaid\MermaidOutline;
use Porta\GraphViewer\Mermaid\MermaidScene;
use Porta\GraphViewer\Mermaid\MermaidStatement;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;

final class BlockTest extends TestCase
{
    private static function parse(string $source): BlockDiagram
    {
        $parser = new BlockParser();
        foreach (new BlockLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    private static function scene(string $source): MermaidScene
    {
        $work = new BlockLayout()->layout(self::parse($source));
        \iterator_to_array($work);

        return $work->getReturn();
    }

    public static function fixtures(): iterable
    {
        foreach (\glob(__DIR__.'/../fixtures/mermaid/block/*.mmd') as $path) {
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

    public function testGridSpansSpacesNestedWidthsAndDeclarationOrder(): void
    {
        $source = "block-beta\ncolumns 3\nA[\"Alpha\"]:2 space\nblock:g[\"Group\"]:2\ncolumns 2\nB C\nend\nD\nA --> D\n";
        $diagram = self::parse($source);
        $this->assertSame(['A', '@space:6:0', 'g', 'D'], $diagram->children['']);
        $this->assertSame(['B', 'C'], $diagram->children['g']);
        $scene = self::scene($source);
        $nodes = \array_column($scene->graph->nodes, null, 'name');
        $this->assertGreaterThan($nodes['D']->w, $nodes['A']->w);
        $this->assertEqualsWithDelta($nodes['A']->w, $nodes['g']->w, delta: 0.001);
        $this->assertGreaterThan($nodes['A']->y, $nodes['D']->y);
        $this->assertSame(1, $scene->graph->edgeCount());
        $frame = $scene->graph->clusters[0];
        foreach (['B', 'C'] as $id) {
            $this->assertGreaterThanOrEqual($frame->x0, $nodes[$id]->x - ($nodes[$id]->w / 2));
            $this->assertLessThanOrEqual($frame->x1, $nodes[$id]->x + ($nodes[$id]->w / 2));
        }
    }

    public function testLabelsStylesOriginsAndArrowOutlines(): void
    {
        $scene = self::scene("block\ncolumns 2\nA<[\"Go\"]>(x,up) B[\"Beta\"]\nclassDef blue fill:blue,color:white\nclass A blue\nstyle B fill:red\nA -- \"uses\" --> B");
        $nodes = \array_column($scene->graph->nodes, null, 'name');
        $this->assertSame('Go', $nodes['A']->label);
        $this->assertSame('#0000ff', $nodes['A']->fillColor);
        $this->assertSame('#ff0000', $nodes['B']->fillColor);
        $this->assertSame(2, $scene->origin($nodes['A']->id));
        $this->assertSame('uses', $scene->graph->edges[0]->label);
        $points = MermaidOutline::points($nodes['A'], $scene->shapes[$nodes['A']->id]);
        $this->assertEqualsWithDelta($nodes['A']->x + ($nodes['A']->w / 2), \max(\array_column($points, 0)), delta: 0.001);
        $this->assertEqualsWithDelta($nodes['A']->y - ($nodes['A']->h / 2), \min(\array_column($points, 1)), delta: 0.001);
        $this->assertTrue(MermaidOutline::contains($nodes['A'], $scene->shapes[$nodes['A']->id], $nodes['A']->x, $nodes['A']->y));
        $this->assertTrue(MermaidOutline::contains($nodes['A'], $scene->shapes[$nodes['A']->id], $nodes['A']->x + (0.25 * $nodes['A']->w), $nodes['A']->y + (0.28 * $nodes['A']->h)));
        $this->assertFalse(MermaidOutline::contains($nodes['A'], $scene->shapes[$nodes['A']->id], $nodes['A']->x + ($nodes['A']->w / 2), $nodes['A']->y - ($nodes['A']->h / 2)));
    }

    public static function arrowDirections(): iterable
    {
        yield ['right', 9];
        yield ['left', 7];
        yield ['up', 7];
        yield ['down', 7];
        yield ['x', 10];
        yield ['y', 10];
        yield ['right,up', 3];
        yield ['right,down', 3];
        yield ['left,up', 3];
        yield ['left,down', 3];
        yield ['x,up', 6];
        yield ['x,down', 4];
        yield ['right,y', 4];
        yield ['left,y', 4];
        yield ['x,y,right', 16];
    }

    #[DataProvider('arrowDirections')]
    public function testPinnedDirectionFactoriesKeepTheirDistinctOutlines(string $directions, int $vertices): void
    {
        $scene = self::scene('block A<["Arrow"]>('.$directions.')');
        $node = $scene->graph->nodes[0];
        $points = MermaidOutline::points($node, $scene->shapes[0]);
        $this->assertCount($vertices, $points);
        $this->assertEqualsWithDelta($node->x - ($node->w / 2), \min(\array_column($points, 0)), delta: 0.001);
        $this->assertEqualsWithDelta($node->x + ($node->w / 2), \max(\array_column($points, 0)), delta: 0.001);
        $this->assertEqualsWithDelta($node->y - ($node->h / 2), \min(\array_column($points, 1)), delta: 0.001);
        $this->assertEqualsWithDelta($node->y + ($node->h / 2), \max(\array_column($points, 1)), delta: 0.001);
    }

    public function testUnknownArrowDirectionsAreNotSilentlyPainted(): void
    {
        $node = self::scene('block A')->graph->nodes[0];
        $this->expectException(\InvalidArgumentException::class);
        BlockArrows::points($node, 'block-arrow:invalid');
    }

    public function testEndpointKindsThicknessAndInvisibleLinks(): void
    {
        $scene = self::scene("block\ncolumns 2\nA B\nA --- B\nA <==> B\nA o--o B\nA x--x B\nA -.-> B\nA ~~~ B");
        $this->assertSame(5, $scene->graph->edgeCount());
        $edges = \array_values(\array_filter($scene->graph->edges, static fn (GraphEdge $edge): bool => !$edge->decoration));
        $this->assertNull($edges[0]->arrowTip);
        $this->assertNotNull($edges[1]->arrowTip);
        $this->assertSame(0.4, $edges[1]->strokeWidth);
        $this->assertNull($edges[2]->arrowTip);
        $this->assertNull($edges[3]->arrowTip);
        $this->assertTrue($edges[4]->dashed);
        $decorations = \array_values(\array_filter($scene->graph->edges, static fn (GraphEdge $edge): bool => $edge->decoration));
        $this->assertCount(7, $decorations);
        $this->assertNotNull($decorations[0]->arrowTip);
        $this->assertCount(25, $decorations[1]->points);
        $this->assertCount(2, $decorations[3]->points);
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render("block\nA<[\"Both\"]>(x,y) B", GraphExportFormat::Png));
    }

    public function testResourceLimitsAndLocatedErrors(): void
    {
        $cases = [
            "block\n".\str_repeat("A\n", times: 16_384) => 'declaration limit',
            "block\n".\implode("\n", \array_map(static fn (int $i): string => 'node'.$i, \range(0, end: 4096))) => 'item limit',
            "block\n".\str_repeat("space:256\n", times: 17) => 'item limit',
            "block\n".\implode("\n", \array_map(static fn (int $i): string => 'block:g'.$i, \range(0, end: 128))) => 'nesting limit',
            "block\nblock:g\nA\nend\nblock:g\nB\nend" => 'Duplicate',
            "block\n[\"Missing\"]" => 'identifier',
            "block\nA --> space B" => 'source',
        ];
        foreach ($cases as $source => $message) {
            try {
                self::parse($source);
                $this->fail('Resource limit accepted');
            } catch (MermaidSyntaxError $error) {
                $this->assertStringContainsString($message, $error->getMessage());
            }
        }
        $parser = new BlockParser();
        $this->expectException(MermaidSyntaxError::class);
        $parser->accept(new MermaidStatement('bad', offset: 5, line: 2, column: 1));
    }

    public static function invalid(): iterable
    {
        foreach ([
            'block',
            "block\nend",
            "block\nblock:",
            "block\nblock:g\nend",
            "block\nA -->",
            "block\n--> A",
            "block\n:2",
            "block\nA:0",
            "block\nA:257",
            "block\nspace:257",
            "block\ncolumns 257\nA",
            "block\nA[\"bad\"",
        ] as $source) {
            yield [$source];
        }
    }

    #[DataProvider('invalid')]
    public function testMalformedAndUnboundedDeclarationsFail(string $source): void
    {
        $this->expectException(MermaidSyntaxError::class);
        self::parse($source);
    }
}
