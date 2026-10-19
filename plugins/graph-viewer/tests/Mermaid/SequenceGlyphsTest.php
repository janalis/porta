<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\MermaidDrawing;
use Porta\GraphViewer\Mermaid\MermaidMark;
use Porta\GraphViewer\Mermaid\MermaidOutline;
use Porta\GraphViewer\Mermaid\MermaidScene;

/** Pinned participant glyphs and box-less message text in native sequence pictures. */
final class SequenceGlyphsTest extends TestCase
{
    private const string TYPES = <<<'MMD'
        sequenceDiagram
        participant P as Plain
        actor A as Actor
        participant B@{ "type": "boundary" }
        participant C@{ "type": "control" }
        participant E@{ "type": "entity" }
        participant D@{ "type": "database" }
        participant L@{ "type": "collections" }
        participant Q@{ "type": "queue" }
        P->>Q: hi
        Note over P: aside
        MMD;

    public function testEachParticipantTypeTakesItsPinnedShapeOnHeaderAndFooter(): void
    {
        $scene = NativeFixtureScene::render(self::TYPES);
        $shapes = [];
        foreach ($scene->graph->nodes as $node) {
            $shapes[(string) $node->name][] = $scene->shapes[$node->id] ?? 'rect';
        }
        $expected = ['seq-actor', 'seq-boundary', 'seq-control', 'seq-entity', 'seq-database', 'st-rect', 'h-cyl'];
        foreach (['A', 'B', 'C', 'E', 'D', 'L', 'Q'] as $index => $actor) {
            $this->assertSame([$expected[$index], $expected[$index]], $shapes[$actor], $actor);
        }
        $this->assertSame(['rect', 'rect'], $shapes['P']);
        $label = self::node($scene, 'hi');
        $this->assertSame('seq-message', $scene->shapes[$label->id]);
        $this->assertArrayNotHasKey(self::node($scene, 'aside')->id, $scene->shapes, 'notes keep their box');
        $this->assertSame(7.4, self::node($scene, 'Actor')->h, 'a glyph header holds the glyph above a one-line label');
        $this->assertSame(6.0, self::node(NativeFixtureScene::render("sequenceDiagram\nparticipant Q@{ \"type\": \"queue\" }"), 'Q')->h, 'boxed types keep the label box');
    }

    public function testGlyphsStandAboveTheirLabelWithinTheHeader(): void
    {
        $scene = NativeFixtureScene::render(self::TYPES);
        $drawing = self::drawing($scene);
        foreach (['Actor' => 4, 'B' => 3, 'C' => 2, 'E' => 2, 'D' => 2] as $label => $count) {
            $node = self::node($scene, $label);
            $marks = MermaidOutline::marks($node, $scene->shapes[$node->id]);
            $this->assertCount($count, $marks, $label);
            $top = $node->y - ($node->h / 2);
            foreach ($marks as $mark) {
                foreach ($mark->points as [$x, $y]) {
                    $this->assertGreaterThan($top - 0.1, $y, $label);
                    $this->assertLessThanOrEqual($top + 4.4 + 1e-9, $y, $label.' leaves the last line to the label');
                    $this->assertLessThan(3.7, \abs($x - $node->x), $label);
                }
            }
            $text = \array_find($drawing->marks, static fn (MermaidMark $mark): bool => 'text' === $mark->kind && $label === $mark->text && \abs($mark->points[0][1] - $node->y) < ($node->h / 2));
            $this->assertInstanceOf(MermaidMark::class, $text);
            $this->assertEqualsWithDelta($node->y + ($node->h / 2) - 1.0, $text->points[0][1], 1e-9, $label.' sits on the header\'s last line');
        }
        $boundary = self::node($scene, 'B');
        $bar = MermaidOutline::marks($boundary, 'seq-boundary')[0];
        $this->assertEqualsWithDelta([[$boundary->x - 3.6, $boundary->y - 2.5], [$boundary->x - 3.6, $boundary->y - 0.5]], $bar->points, 1e-9);
        $database = MermaidOutline::marks(self::node($scene, 'D'), 'seq-database');
        $this->assertSame(['polygon', 'polyline'], [$database[0]->kind, $database[1]->kind]);
        $this->assertCount(26, $database[0]->points, 'the rim\'s back half and the base');
        $control = MermaidOutline::marks(self::node($scene, 'C'), 'seq-control');
        $this->assertSame($control[1]->stroke, $control[1]->fill, 'the control arrowhead is solid');
    }

    public function testTwoLineLabelsKeepTheirLastLineAtTheHeaderFoot(): void
    {
        $scene = NativeFixtureScene::render("sequenceDiagram\nactor A as Two<br>lines");
        $node = self::node($scene, "Two\nlines");
        $this->assertSame(9.4, $node->h);
        $texts = \array_values(\array_filter(self::drawing($scene)->marks, static fn (MermaidMark $mark): bool => 'text' === $mark->kind && 'lines' === $mark->text));
        $this->assertEqualsWithDelta($node->y + ($node->h / 2) - 1.0, $texts[0]->points[0][1], 1e-9);
    }

    public function testMessageTextHasNoBoxAndStandsOnItsArrow(): void
    {
        $scene = NativeFixtureScene::render("sequenceDiagram\nA->>B: hello");
        $label = self::node($scene, 'hello');
        $this->assertSame([], MermaidOutline::marks($label, 'seq-message'));
        $drawing = self::drawing($scene);
        $inside = static fn (MermaidMark $mark): bool => 'polygon' === $mark->kind && \abs($mark->points[0][1] - $label->y) < ($label->h / 2) && \abs($mark->points[0][0] - $label->x) < $label->w;
        $this->assertSame([], \array_values(\array_filter($drawing->marks, $inside)));
        $text = \array_find($drawing->marks, static fn (MermaidMark $mark): bool => 'text' === $mark->kind && 'hello' === $mark->text);
        $this->assertEqualsWithDelta($label->y + ($label->h / 2) - 1.0, $text->points[0][1], 1e-9);
        $svg = NativeFixtureExport::render("sequenceDiagram\nactor A\nparticipant D@{ \"type\": \"database\" }\nA->>D: hello", GraphExportFormat::Svg);
        $this->assertStringContainsString('>hello</text>', $svg);
    }

    private static function drawing(MermaidScene $scene): MermaidDrawing
    {
        $work = MermaidDrawing::scene($scene);
        \iterator_to_array($work);

        return $work->getReturn();
    }

    private static function node(MermaidScene $scene, string $label): GraphNode
    {
        $node = \array_find($scene->graph->nodes, static fn (GraphNode $node): bool => $label === $node->label);
        self::assertInstanceOf(GraphNode::class, $node, $label);

        return $node;
    }
}
