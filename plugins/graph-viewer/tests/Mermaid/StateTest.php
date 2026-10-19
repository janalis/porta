<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Layout\GraphEdge;
use Janalis\Viz\Mermaid\StateDiagram;
use Janalis\Viz\Mermaid\StateLayout;
use Janalis\Viz\Mermaid\StateLexer;
use Janalis\Viz\Mermaid\StateParser;
use Janalis\Viz\Render\Format;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StateTest extends TestCase
{
    private static function parse(string $source): StateDiagram
    {
        $parser = new StateParser();
        foreach (new StateLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    public function testChoicesForksNotesDirectionsStylesAndExports(): void
    {
        $source = <<<'MMD'
            stateDiagram-v2
            # comment
            direction LR
            state Fork <<fork>>
            state Join [[join]]
            state Choice <<choice>>
            [*] --> Fork
            Fork --> A:::hot : one
            Fork --> B
            A --> Join
            B --> Join
            Join --> Choice
            Choice --> [*] : done
            state Nested {
                direction TB
                N1 --> N2
            }
            Nested --> A
            N1 --> A
            classDef hot fill:#ff0000,color:#000000
            style A stroke:#0000ff
            class B hot
            note left of A: Left note
            note right of B
                Multiline
                note : with {braces}; punctuation
            end note
            note "Floating" as Float
            MMD;
        $diagram = self::parse($source);
        $this->assertSame('LR', $diagram->graph->direction);
        $this->assertSame('TB', $diagram->graph->groups['Nested']['direction']);
        $this->assertSame('choice', $diagram->kinds['Choice']);
        $this->assertSame('note', $diagram->kinds['Float']);
        $work = new StateLayout()->layout($diagram);
        \iterator_to_array($work);
        $scene = $work->getReturn();
        $byName = [];
        foreach ($scene->graph->nodes as $node) {
            $byName[$node->name] = $node;
        }
        $this->assertSame([1.5, 12.0, ''], [$byName['Fork']->w, $byName['Fork']->h, $byName['Fork']->drawLabel]);
        $this->assertSame('#ff0000', $byName['A']->fillColor);
        $this->assertSame('#0000ff', $byName['A']->color);
        $this->assertSame('diamond', $scene->shapes[$byName['Choice']->id]);
        $this->assertSame('@noteBkgColor', $byName['Float']->fillColor);
        $leftNote = \array_find($scene->graph->nodes, static fn ($node): bool => 'Left note' === $node->label);
        $this->assertLessThan($byName['A']->x - ($byName['A']->w / 2), $leftNote->x + ($leftNote->w / 2));
        $rightNote = \array_find($scene->graph->nodes, static fn ($node): bool => \str_contains($node->label, 'Multiline'));
        $this->assertGreaterThan($byName['B']->x + ($byName['B']->w / 2), $rightNote->x - ($rightNote->w / 2));
        $this->assertCount(2, \array_filter($scene->graph->edges, static fn (GraphEdge $edge): bool => $edge->decoration));
        $this->assertGreaterThan($byName['N1']->y, $byName['N2']->y);
        $this->assertSame($byName['N1']->x, $byName['N2']->x);
        $this->assertNotNull($scene->graph->edges[0]->arrowTip);
        $rows = NativeFixtureRows::render($source, width: 28);
        $this->assertStringContainsString('Left note', \implode("\n", $rows->rows));
        $svg = NativeFixtureExport::render($source, Format::Svg);
        $this->assertStringContainsString('Floating', $svg);
        // Fork and join bars take the special state color: black under the default redux-color theme.
        $this->assertStringContainsString('fill="#000000"', $svg);
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, Format::Png));
        $vertical = self::parse("stateDiagram\nstate Fork <<fork>>\nstate Join <<join>>\nFork --> Join");
        $layout = new StateLayout()->layout($vertical);
        \iterator_to_array($layout);
        $this->assertSame([12.0, 1.5], [$layout->getReturn()->graph->nodes[0]->w, $layout->getReturn()->graph->nodes[0]->h]);
    }

    public function testCompositeLabelsLocalForkSizingAndIndependentNotes(): void
    {
        $source = <<<'MMD'
            stateDiagram-v2
            direction LR
            state "Parent label" as Parent {
                direction TB
                state F <<fork>>
                [*] --> F
                F --> Child
            }
            Parent : Additional description
            note left of Parent: First
            state Removed {}
            note right of Parent: Second
            MMD;
        $diagram = self::parse($source);
        $this->assertSame('First', $diagram->graph->nodes['@note:0']['label']);
        $this->assertSame('Second', $diagram->graph->nodes['@note:1']['label']);
        $work = new StateLayout()->layout($diagram);
        \iterator_to_array($work);
        $scene = $work->getReturn();
        $fork = \array_find($scene->graph->nodes, static fn ($node): bool => 'F' === $node->name);
        $this->assertSame([12.0, 1.5], [$fork->w, $fork->h]);
        $parent = \array_find($scene->graph->nodes, static fn ($node): bool => 'Parent' === $node->name);
        $this->assertStringContainsString('Parent label', $parent->label);
        $this->assertStringContainsString('Additional description', $parent->label);
        $this->assertSame(2, $scene->elements[$parent->id]);
        $this->assertSame('text', $scene->shapes[$parent->id]);
        $this->assertSame(6.0, $parent->h);
        $this->assertStringContainsString('Parent label', NativeFixtureExport::render($source, Format::Svg));
        $rows = NativeFixtureRows::render($source, width: 60);
        $this->assertStringContainsString('Additional description', \implode("\n", $rows->rows));
    }

    public function testSelfTransitionsLeaveTheFullStateAndKeepTheirArrowheads(): void
    {
        $source = "stateDiagram-v2\nA --> A : repeat\nstate Parent {\nChild\n}\nParent --> Parent : retry";
        $work = new StateLayout()->layout(self::parse($source));
        \iterator_to_array($work);
        $scene = $work->getReturn();
        foreach ($scene->graph->edges as $edge) {
            $node = $scene->graph->nodes[$edge->tail];
            $right = $node->x + ($node->w / 2);
            $bottom = $node->y + ($node->h / 2);
            if ('Parent' === $node->name) {
                $cluster = $scene->graph->clusters[0];
                $right = $cluster->x1;
                $bottom = $cluster->y1;
            }
            $this->assertGreaterThan($right, $edge->maxX);
            $this->assertGreaterThan($bottom, $edge->maxY);
            $this->assertSame([$node->x, $bottom], $edge->arrowTip);
            $this->assertGreaterThan($edge->arrowTip[1], \array_last($edge->points)[1]);
            $this->assertGreaterThan($edge->maxY, $scene->graph->height);
        }
        $this->assertStringContainsString('▴', \implode("\n", NativeFixtureRows::render($source, width: 80)->rows));
    }

    /** @return iterable<string, array{string}> */
    public static function pinnedBodies(): iterable
    {
        $files = \glob(__DIR__.'/../fixtures/mermaid/state/*.mmd');
        foreach (false === $files ? [] : $files as $file) {
            $source = (string) \file_get_contents($file);
            // This corpus grades family syntax/layout separately from global configuration.
            $source = \preg_replace('/\A---\r?\n.*?\r?\n---\r?\n/s', replacement: '', subject: $source) ?? $source;
            $source = \preg_replace('/%%\{.*?\}%%/s', replacement: '', subject: $source) ?? $source;
            yield \basename($file) => [$source];
        }
    }

    #[DataProvider('pinnedBodies')]
    public function testPinnedStateBodiesRenderToCellsAndSvg(string $source): void
    {
        $diagram = self::parse($source);
        $this->assertTrue([] !== $diagram->graph->nodes || [] !== $diagram->graph->groups);
        $rows = NativeFixtureRows::render($source, width: 48);
        $this->assertNotEmpty($rows->rows);
        $this->assertCount(\count($rows->rows), $rows->origins);
        $this->assertStringContainsString('</svg>', NativeFixtureExport::render($source, Format::Svg));
    }
}
