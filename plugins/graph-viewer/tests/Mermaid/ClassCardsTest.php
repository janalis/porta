<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphFont;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\ClassMarkers;
use Porta\GraphViewer\Mermaid\ClassMembers;
use Porta\GraphViewer\Mermaid\ErMarkers;
use Porta\GraphViewer\Mermaid\MermaidMark;
use Porta\GraphViewer\Mermaid\MermaidOutline;
use Porta\GraphViewer\Mermaid\MermaidPolyline;

/** Pinned class cards: member display and typography, notes, and markers on curved edges. */
final class ClassCardsTest extends TestCase
{
    private const string REFERENCE = __DIR__.'/../fixtures/mermaid/class-members/members-reference.json';

    #[DataProvider('members')]
    public function testMembersDisplayAsThePinnedClassMemberDoes(string $input, string $text, string $style): void
    {
        [$display, $classifier] = ClassMembers::display($input);
        $this->assertSame([$text, $style], [$display, ['$' => 'text-decoration:underline;', '*' => 'font-style:italic;', '' => ''][$classifier]]);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function members(): iterable
    {
        /** @var array{cases: list<array{input: string, text: string, style: string}>} $reference */
        $reference = \json_decode((string) \file_get_contents(self::REFERENCE), associative: true, flags: \JSON_THROW_ON_ERROR);
        foreach ($reference['cases'] as $case) {
            yield $case['input'] => [$case['input'], $case['text'], $case['style']];
        }
    }

    public function testCardsSetTheNameInBoldAndMembersLeftAlignedWithTheirClassifierFaces(): void
    {
        $scene = NativeFixtureScene::render("classDiagram\nclass Animal{\n<<interface>>\n+int age\n+isMammal()\$\n+mate()*\n}");
        $animal = \array_find($scene->graph->nodes, static fn (GraphNode $node): bool => 'Animal' === $node->name);
        $runs = $scene->runs['node:'.$animal->id];
        $this->assertSame([['«interface»', GraphFont::Regular]], $runs[0]);
        $this->assertSame([['Animal', GraphFont::Bold]], $runs[1]);
        $this->assertSame(
            [
                ['+int age', GraphFont::Regular],
                ['   ',      GraphFont::Regular],
            ],
            $runs[3],
            'members pad to the widest, +isMammal()',
        );
        $this->assertSame(
            [
                ['+mate()', GraphFont::Italic],
                ['    ',    GraphFont::Regular],
            ],
            $runs[6],
        );
        $this->assertSame(
            [
                ['+isMammal()', GraphFont::Regular],
                ['',            GraphFont::Regular],
            ],
            $runs[5],
        );
        $this->assertStringEndsWith(';u=11=5', $scene->shapes[$animal->id]);
        $underline = \array_last(MermaidOutline::marks($animal, $scene->shapes[$animal->id]));
        $top = $animal->y - ($animal->h / 2);
        $this->assertEqualsWithDelta([[$animal->x - 5.5, $top + 13.8], [$animal->x + 5.5, $top + 13.8]], $underline->points, 1e-9, 'the static member is underlined');
        $this->assertSame([], ClassMembers::underlines($animal, 'er-card:2'));
        $statics = NativeFixtureScene::render("classDiagram\nclass A{\n+a()\$\n+b()\$\n+ab".\str_repeat('word ', times: 12)."()\$\n}");
        $node = \array_find($statics->graph->nodes, static fn (GraphNode $node): bool => 'A' === $node->name);
        $marks = MermaidOutline::marks($node, $statics->shapes[$node->id]);
        $dividers = \array_filter($marks, static fn (MermaidMark $mark): bool => 'polyline' === $mark->kind && \abs($mark->points[1][0] - $mark->points[0][0] - $node->w) < 1e-9);
        $this->assertCount(1, $dividers, 'one compartment, one divider: underlined rows never add any');
        $lines = \explode("\n", $node->label);
        $underlines = \array_values(\array_filter($marks, static fn (MermaidMark $mark): bool => 'polyline' === $mark->kind && 0.08 === $mark->strokeWidth));
        $this->assertSame(
            \array_map(static fn (string $line): int => \mb_strlen(\rtrim($line)), \array_slice($lines, offset: 2)),
            \array_map(static fn (MermaidMark $mark): int => (int) \round($mark->points[1][0] - $mark->points[0][0]), $underlines),
            'a wrapped line is underlined to its last word',
        );
        $this->assertStringEndsWith(' ', $lines[\count($lines) - 2]);
        $two = NativeFixtureScene::render("classDiagram\nclass B{\n<<interface>>\n<<service>>\n}");
        $this->assertSame(
            [['«interface»', GraphFont::Regular]],
            $two->runs['node:'.\array_find($two->graph->nodes, static fn (GraphNode $node): bool => 'B' === $node->name)->id][0],
            'only the first annotation shows, as pinned',
        );
        $styled = NativeFixtureScene::render("classDiagram\nclass A{\n+x\n}\nstyle A font-style:italic");
        $this->assertSame([], $styled->runs, 'an authored font style sets the whole card');
    }

    public function testNotesDrawAsNoteBoxesLinkedByDottedLinesAfterTheClasses(): void
    {
        $scene = NativeFixtureScene::render("classDiagram\nnote \"Free\"\nnote for Duck \"can fly\\ncan swim\"\nnote for Ghost \"nobody\"\nclass Duck\nclass Goose");
        $notes = \array_values(\array_filter($scene->graph->nodes, static fn (GraphNode $node): bool => \str_starts_with($node->name, 'class-note-')));
        $this->assertSame(['Free', "can fly\ncan swim", 'nobody'], \array_column($notes, 'label'));
        $this->assertSame(['#facc15', '#fff5ad', '#28253d'], [$notes[0]->color, $notes[0]->fillColor, $notes[0]->textColor], 'the pinned note colors of the default theme');
        $links = \array_values($scene->graph->relationships);
        $this->assertCount(1, $links, 'a note for an unknown class floats free');
        $this->assertTrue($links[0]->dashed);
        $this->assertNull($links[0]->arrowTip);
        $this->assertSame([1, 2, 3], \array_values(\array_map(static fn (GraphNode $node): int => $scene->elements[$node->id], $notes)), 'each note leads to its own line');
        $classes = \array_values(\array_filter($scene->graph->nodes, static fn (GraphNode $node): bool => !\str_starts_with($node->name, 'class-note-')));
        $plain = NativeFixtureScene::render("classDiagram\nclass Duck\nclass Goose");
        $fills = \array_column($classes, 'fillColor', 'name');
        \ksort($fills);
        $expected = \array_column($plain->graph->nodes, 'fillColor', 'name');
        \ksort($expected);
        $this->assertSame($expected, $fills, 'notes leave the classes their color slots');
    }

    public function testMarkersOnCurvedEdgesTakeTheirSizeAndDirectionAlongTheLine(): void
    {
        $curve = [[0.0, 0.0], [0.0, 0.5], [0.0, 1.0], [0.0, 1.5], [0.0, 2.0], [0.0, 6.0], [0.0, 12.0]];
        $bent = new GraphEdge(0, 1, [[0.0, 0.0], [0.5, 0.0], [0.5, 3.0], [0.5, 12.0]], null, 0.0, 0.0, 0.5, 12.0, '#000000', null, null, dashed: false);
        $aimed = ClassMarkers::forEdge($bent, '<|', '')[0];
        [$toward] = MermaidPolyline::along($bent->points, 3.0);
        $length = \hypot($toward[0], $toward[1]);
        $this->assertEqualsWithDelta(
            [(2.4 * $toward[0]) / $length, (2.4 * $toward[1]) / $length],
            [($aimed->points[1][0] + $aimed->points[2][0]) / 2, ($aimed->points[1][1] + $aimed->points[2][1]) / 2],
            1e-9,
            'a glyph aims three units along the line, not along a stray first step',
        );
        $edge = new GraphEdge(0, 1, $curve, null, 0.0, 0.0, 0.0, 12.0, '#000000', null, null, dashed: false);
        $triangle = ClassMarkers::forEdge($edge, '<|', '')[0];
        $this->assertEqualsWithDelta(2.4, \max(\array_column($triangle->points, 1)), 1e-9, 'full size, though the first segment is half a unit');
        $this->assertEqualsWithDelta(2.4, \max(\array_column($triangle->points, 0)) - \min(\array_column($triangle->points, 0)), 1e-9);
        $shaft = ClassMarkers::shaft($edge, '<|', '|>');
        $this->assertEquals([[0.0, 2.4], [0.0, 6.0], [0.0, 9.6]], $shaft->points, 'the shaft stops at both bases, along the curve');
        $this->assertSame($edge->points, ClassMarkers::shaft($edge, '<', '>')->points);
        $short = new GraphEdge(0, 1, [[0.0, 0.0], [0.0, 4.0]], null, 0.0, 0.0, 0.0, 4.0, '#000000', null, null, dashed: false);
        $this->assertEqualsWithDelta(1.2, \max(\array_column(ClassMarkers::forEdge($short, '<|', '')[0]->points, 1)), 1e-9, 'a short edge halves its glyph');
        $bars = ErMarkers::forEdge($edge, '1', '1');
        $this->assertEqualsWithDelta([[0.8, 0.6], [-0.8, 0.6]], $bars[0]->points, 1e-9, 'ER bars keep their full width on a curve');
        $this->assertSame(12.0, MermaidPolyline::length($curve));
        $this->assertSame([[0.0, 4.0], [[0.0, 6.0], [0.0, 12.0]]], MermaidPolyline::along($curve, 4.0));
        $this->assertSame([[0.0, 12.0], []], MermaidPolyline::along($curve, 20.0));
        $this->assertSame([[0.0, 0.0], [[0.0, 0.5], [0.0, 1.0], [0.0, 1.5], [0.0, 2.0], [0.0, 6.0], [0.0, 12.0]]], MermaidPolyline::along($curve, 0.0));
    }
}
