<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\MermaidDrawing;
use Porta\GraphViewer\Mermaid\MermaidMark;
use Porta\GraphViewer\Mermaid\MermaidStatement;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;
use Porta\GraphViewer\Mermaid\StateDiagram;
use Porta\GraphViewer\Mermaid\StateLayout;
use Porta\GraphViewer\Mermaid\StateLexer;
use Porta\GraphViewer\Mermaid\StateParser;

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

    public function testTransitionsDescriptionsAndScopedPseudostates(): void
    {
        $diagram = self::parse(<<<'MMD'
            %% intro
            stateDiagram-v2
            accTitle: Lifecycle
            accDescr {States and transitions}
            [*] --> Active
            state Active {
                [*] --> Idle
                Idle --> Busy : begin
                Busy --> [*] : finish
            }
            Active --> [*]
            Idle : Ready ❤
            Idle : entry / wait()
            state "Working" as Busy: task()
            hide empty description
            scale 300 width
            click Idle href "https://example.com"
            MMD);
        $this->assertArrayNotHasKey('Active', $diagram->graph->nodes);
        $this->assertArrayHasKey('Active', $diagram->graph->groups);
        $this->assertSame(['@start:', '@start:Active'], \array_keys(\array_filter($diagram->kinds, static fn (string $kind): bool => 'start' === $kind)));
        $this->assertSame(['@end:Active', '@end:'], \array_keys(\array_filter($diagram->kinds, static fn (string $kind): bool => 'end' === $kind)));
        $this->assertSame(['Active'], $diagram->graph->nodes['Idle']['groups']);
        $this->assertSame(['Ready ❤', 'entry / wait()'], \array_column($diagram->descriptions['Idle'], 'text'));
        $this->assertSame(['Working', 'task()'], \array_column($diagram->descriptions['Busy'], 'text'));
        $this->assertCount(5, $diagram->metadata);
        $work = new StateLayout()->layout($diagram);
        $this->assertGreaterThan(20, \count(\iterator_to_array($work)));
        $scene = $work->getReturn();
        $this->assertSame(7, $scene->graph->nodeCount());
        $this->assertCount(5, $scene->graph->edges);
        $idle = \array_find($scene->graph->nodes, static fn ($node): bool => 'Idle' === $node->name);
        $this->assertStringContainsString('Ready ❤', $idle->label);
        $this->assertStringContainsString('entry / wait()', $idle->label);
        $this->assertStringStartsWith('rd-card:', $scene->shapes[$idle->id]);
        $this->assertContains(11, \array_column($scene->legend, 'origin'));
        foreach ($scene->graph->nodes as $node) {
            if ('@start:' !== $node->name) {
                continue;
            }
            $this->assertSame([3.0, 3.0, '', '@specialStateColor'], [$node->w, $node->h, $node->drawLabel, $node->fillColor]);
        }
    }

    public function testNoteAssociationsUseTheSpaceAboveStateCards(): void
    {
        $work = new StateLayout()->layout(self::parse("stateDiagram-v2\ndirection LR\nA --> B\nnote right of A: Context"));
        \iterator_to_array($work);
        $graph = $work->getReturn()->graph;
        $edge = \array_find($graph->edges, static fn (GraphEdge $edge): bool => $edge->decoration);
        $state = $graph->nodes[$edge->head];
        $this->assertCount(5, $edge->points);
        $this->assertLessThan($state->y - ($state->h / 2), $edge->points[2][1]);
        $this->assertSame($state->x, $edge->points[3][0]);
        $this->assertSame([$state->x, $state->y - ($state->h / 2)], $edge->points[4]);
    }

    public function testConcurrentRegionsRetainNestedStatesAndSeparateStarts(): void
    {
        $diagram = self::parse(<<<'MMD'
            stateDiagram
            Outside
            state Active {
                state Inner {
                    [*] --> First
                }
                [*] --> A
                --
                [*] --> B
                --
                C
            }
            MMD);
        $this->assertSame(['Active', '@region:Active:0', 'Inner'], $diagram->graph->nodes['First']['groups']);
        $this->assertSame(['Active', '@region:Active:1'], $diagram->graph->nodes['B']['groups']);
        $this->assertSame(['Active', '@region:Active:2'], $diagram->graph->nodes['C']['groups']);
        $this->assertSame([], $diagram->graph->nodes['Outside']['groups']);
        $this->assertSame('@region:Active:0', $diagram->graph->groups['Inner']['parent']);
        $this->assertSame(['Active', '@region:Active:0', '@region:Active:1', '@region:Active:2', 'Inner'], \array_keys($diagram->graph->groups));
        $this->assertSame('start', $diagram->kinds['@start:@region:Active:1']);
        $work = new StateLayout()->layout($diagram);
        \iterator_to_array($work);
        $this->assertCount(5, $work->getReturn()->graph->clusters);
        $this->assertSame(3, $work->getReturn()->graph->edgeCount());
        $this->assertCount(2, \array_filter($work->getReturn()->graph->edges, static fn (GraphEdge $edge): bool => $edge->decoration && $edge->dashed));
        $this->assertStringNotContainsString('[ · ', \implode("\n", \array_column($work->getReturn()->legend, 'text')));
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
        $svg = NativeFixtureExport::render($source, GraphExportFormat::Svg);
        $this->assertStringContainsString('Floating', $svg);
        // Fork and join bars take the special state color: black under the default redux-color theme.
        $this->assertStringContainsString('fill="#000000"', $svg);
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, GraphExportFormat::Png));
        $vertical = self::parse("stateDiagram\nstate Fork <<fork>>\nstate Join <<join>>\nFork --> Join");
        $layout = new StateLayout()->layout($vertical);
        \iterator_to_array($layout);
        $this->assertSame([12.0, 1.5], [$layout->getReturn()->graph->nodes[0]->w, $layout->getReturn()->graph->nodes[0]->h]);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalid(): iterable
    {
        yield 'header' => ['flowchart TB', 'Expected stateDiagram'];
        yield 'close' => ["stateDiagram\n}", 'Unexpected composite'];
        yield 'unclosed' => ["stateDiagram\nstate A {\nB", 'Unclosed composite'];
        yield 'duplicate' => ["stateDiagram\nstate A {}\nstate A {}", 'Duplicate composite'];
        yield 'divider' => ["stateDiagram\n--", 'Concurrent regions require'];
        yield 'unclosed note' => ["stateDiagram\nnote left of A\nmissing", 'Invalid state'];
        yield 'bad declaration' => ["stateDiagram\nstate \"Name\" as", 'Invalid state'];
        yield 'nesting' => ["stateDiagram\n".\implode("\n", \array_map(static fn (int $i): string => 'state N'.$i.' {', \range(0, end: 128))), 'excessive state nesting'];
        yield 'nodes' => ["stateDiagram\n".\implode("\n", \array_map(static fn (int $i): string => 'N'.$i, \range(0, end: 4096))), 'node limit'];
        yield 'scopes' => ["stateDiagram\nstate A {\n".\str_repeat("--\n", times: 4096).'}', 'state scope limit'];
    }

    #[DataProvider('invalid')]
    public function testInvalidSyntaxIsLocated(string $source, string $message): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains($message);
        self::parse($source);
    }

    public function testDirectParserErrorsAndEmptyHeader(): void
    {
        foreach (['state A B', 'note wrong', 'A - invalid'] as $text) {
            $parser = new StateParser();
            $parser->accept(new MermaidStatement('stateDiagram', offset: 0, line: 0, column: 0));
            try {
                $parser->accept(new MermaidStatement($text, offset: 14, line: 1, column: 2));
                $this->fail('Invalid statement accepted');
            } catch (MermaidSyntaxError $error) {
                $this->assertSame([14, 1, 2], [$error->offset, $error->sourceLine, $error->column]);
            }
        }
        $diagram = self::parse("stateDiagram; A\nA : Caption");
        $this->assertSame('Caption', $diagram->descriptions['A'][0]['text']);
        $this->expectException(MermaidSyntaxError::class);
        new StateParser()->finish();
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
        $this->assertStringContainsString('Parent label', NativeFixtureExport::render($source, GraphExportFormat::Svg));
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

    public function testHorizontalConcurrentRegionsAndEndMarkerHaveTheirOwnGeometry(): void
    {
        $work = new StateLayout()->layout(self::parse("stateDiagram-v2\ndirection LR\nstate Parent {\nA\n--\nB\n}\nParent --> [*]"));
        \iterator_to_array($work);
        $scene = $work->getReturn();
        $divider = \array_find($scene->graph->edges, static fn (GraphEdge $edge): bool => $edge->decoration);
        $this->assertSame($divider->points[0][1], $divider->points[1][1]);
        $this->assertLessThan($divider->points[1][0], $divider->points[0][0]);
        $end = \array_find($scene->graph->nodes, static fn (GraphNode $node): bool => '@end:' === $node->name);
        $drawing = MermaidDrawing::graph($scene->graph, $scene->shapes);
        \iterator_to_array($drawing);
        $dots = \array_filter(
            $drawing->getReturn()->marks,
            static fn (MermaidMark $mark): bool => (
                'polygon' === $mark->kind
                && '#222222' === $mark->fill
                && $mark->points[0][0] > ($end->x - ($end->w / 2))
                && $mark->points[0][0] < ($end->x + ($end->w / 2))
            ),
        );
        $this->assertCount(1, $dots, 'the final-state marker has a filled inner dot');
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
        $this->assertStringContainsString('</svg>', NativeFixtureExport::render($source, GraphExportFormat::Svg));
    }
}
