<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\MermaidOutline;

final class FlowchartShapeAliasesTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function aliases(): iterable
    {
        foreach ([
            'fr-rect' => 'subroutine',
            'framed-rectangle' => 'subroutine',
            'pill' => 'stadium',
            'terminal' => 'stadium',
            'question' => 'diamond',
            'lean-right' => 'lean-r',
            'in-out' => 'lean-r',
            'lean-left' => 'lean-l',
            'out-in' => 'lean-l',
            'priority' => 'trap-b',
            'trapezoid-bottom' => 'trap-b',
            'trapezoid' => 'trap-b',
            'manual' => 'trap-t',
            'trapezoid-top' => 'trap-t',
            'inv-trapezoid' => 'trap-t',
            'double-circle' => 'doublecircle',
        ] as $alias => $shape) {
            yield $alias => [$alias, $shape];
        }
    }

    #[DataProvider('aliases')]
    public function testModernNamesMatchExistingOutlinesLayoutAndRouting(string $alias, string $shape): void
    {
        $node = new GraphNode(0, 'A', 'Label', 0.0, 0.0, 20.0, 12.0, null, null, dashed: false);
        $this->assertSame($shape, MermaidOutline::canonical($alias));
        $this->assertSame(MermaidOutline::points($node, $shape), MermaidOutline::points($node, $alias));
        $this->assertEquals(MermaidOutline::marks($node, $shape), MermaidOutline::marks($node, $alias));
        $prefix = 'flowchart LR; A@{shape:';
        $suffix = ',label:"Longer label"}-->B[Next]';
        $this->assertSame(NativeFixtureExport::render($prefix.$shape.$suffix, GraphExportFormat::Svg), NativeFixtureExport::render($prefix.$alias.$suffix, GraphExportFormat::Svg));
    }

    public function testInternalBordersFollowTheAuthoredStrokeAndDashStyle(): void
    {
        foreach (['fr-rect' => 3, 'double-circle' => 2, 'cyl' => 2] as $shape => $count) {
            $node = new GraphNode(0, 'A', 'Styled', 10.0, 10.0, 20.0, 12.0, '#ff0000', '#ffff00', dashed: true, strokeWidth: 0.4);
            $marks = MermaidOutline::marks($node, $shape);
            $this->assertCount($count, $marks);
            foreach ($marks as $mark) {
                $this->assertSame('#ff0000', $mark->stroke);
                $this->assertSame(0.4, $mark->strokeWidth);
                $this->assertTrue($mark->dashed);
            }
            $plain = MermaidOutline::marks(new GraphNode(0, 'B', 'Plain', 10.0, 10.0, 20.0, 12.0, null, null, dashed: false), $shape);
            $this->assertSame('#333333', $plain[1]->stroke);
            $this->assertSame(0.12, $plain[1]->strokeWidth);
            $this->assertFalse($plain[1]->dashed);
            $hidden = MermaidOutline::marks(new GraphNode(0, 'C', 'Hidden', 10.0, 10.0, 20.0, 12.0, 'none', null, dashed: false, strokeWidth: 0.0), $shape);
            $this->assertSame('none', $hidden[1]->stroke);
            $this->assertSame(0.0, $hidden[1]->strokeWidth);
        }
    }

    public function testStyledAliasesReachPngAndNarrowSourceLinkedRows(): void
    {
        $source = "---\ntitle: Modern names\n---\nflowchart TD\nA@{shape:fr-rect,label:Frame}-->B@{shape:pill,label:Terminal}\nstyle A stroke:red,stroke-width:4px,stroke-dasharray:5 5";
        $svg = NativeFixtureExport::render($source, GraphExportFormat::Svg);
        $this->assertSame(3, \substr_count($svg, needle: 'stroke-dasharray='));
        $this->assertStringContainsString('>Modern names</text>', $svg);
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, GraphExportFormat::Png));
        $rows = NativeFixtureRows::render($source, width: 24);
        $this->assertStringContainsString('Frame', \implode("\n", $rows->rows));
        $this->assertContains(4, $rows->origins);
    }
}
