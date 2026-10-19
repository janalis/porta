<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Mermaid\FlowchartLayout;
use Janalis\Viz\Mermaid\FlowchartParser;
use Janalis\Viz\Mermaid\FlowchartShapes;
use Janalis\Viz\Mermaid\MermaidLexer;
use Janalis\Viz\Mermaid\MermaidOutline;
use Janalis\Viz\Mermaid\MermaidScene;
use Janalis\Viz\Render\Format;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FlowchartModernShapesTest extends TestCase
{
    private static function scene(string $source): MermaidScene
    {
        $parser = new FlowchartParser();
        foreach (new MermaidLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }
        $work = new FlowchartLayout()->layout($parser->finish());
        \iterator_to_array($work);

        return $work->getReturn();
    }

    public static function shapes(): iterable
    {
        $rows = \json_decode(\file_get_contents(__DIR__.'/../fixtures/mermaid/flowchart-shapes.json'), associative: true, flags: \JSON_THROW_ON_ERROR);
        foreach ($rows as $row) {
            foreach (\array_unique([$row['name'], ...$row['aliases']]) as $alias) {
                yield $alias => [$row['name'], $alias];
            }
        }
    }

    #[DataProvider('shapes')]
    public function testEveryPinnedNameUsesItsRegisteredOutlineAndPreservesAuthoredPaint(string $name, string $alias): void
    {
        $this->assertTrue(FlowchartShapes::known($alias));
        $canonical = MermaidOutline::canonical($name);
        $this->assertSame($canonical, MermaidOutline::canonical($alias));
        $prefix = 'flowchart LR; A@{shape:';
        $suffix = ',label:"Sample"} --> B[End]; style A fill:#fff,stroke:#123456,stroke-width:3';
        $source = $prefix.$alias.$suffix;
        $scene = self::scene($source);
        $node = $scene->graph->nodes[0];
        $this->assertSame('Sample', $node->label);
        $this->assertSame(0, $scene->origin($node->id));
        $this->assertSame('#ffffff', $node->fillColor);
        $this->assertSame('#123456', $node->color);
        $this->assertEqualsWithDelta(0.3, $node->strokeWidth, delta: 1.0e-12);
        $marks = MermaidOutline::marks($node, $alias);
        $this->assertEquals(MermaidOutline::marks($node, $name), $marks);
        $this->assertSame(NativeFixtureExport::render($prefix.$name.$suffix, Format::Svg), NativeFixtureExport::render($source, Format::Svg));
        $this->assertFalse(MermaidOutline::contains($node, $alias, $node->x + $node->w, $node->y));
        $this->assertNotEmpty(NativeFixtureRows::render($source, width: 100)->rows);
    }
}
