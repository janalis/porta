<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\MermaidConfiguration;
use Porta\GraphViewer\Mermaid\MermaidGraph;
use Porta\GraphViewer\Mermaid\MermaidLexer;
use Porta\GraphViewer\Mermaid\MermaidPalette;
use Porta\GraphViewer\Mermaid\MermaidScene;
use Porta\GraphViewer\Mermaid\MermaidStatement;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\MermaidText;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\GraphViewer\Mermaid\SequenceArrowheads;
use Porta\GraphViewer\Mermaid\SequenceDiagram;
use Porta\GraphViewer\Mermaid\SequenceLayout;
use Porta\GraphViewer\Mermaid\SequenceParser;
use Porta\GraphViewer\Render\GraphCellGrid;
use Porta\GraphViewer\Render\GraphRenderer;
use Porta\Help\Fence\FenceResult;
use Porta\ImageViewer\ZoomPanModel;
use Porta\Tests\Support\FakeScheduler;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class SequenceTest extends TestCase
{
    private static function parse(string $source): SequenceDiagram
    {
        $parser = new SequenceParser();
        foreach (new MermaidLexer($source, textual: true)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    /** A scene without mirrored footers, so each case reads its timeline alone. */
    private static function scene(string $source): MermaidScene
    {
        $work = new SequenceLayout(new MermaidConfiguration(mirrorActors: false))->layout(self::parse($source));
        \iterator_to_array($work);

        return $work->getReturn();
    }

    /** @return array<string, GraphEdge> each participant's lifeline */
    private static function lifelines(MermaidScene $scene): array
    {
        $lines = [];
        foreach ($scene->graph->edges as $edge) {
            $header = \array_find($scene->graph->nodes, static fn (GraphNode $node): bool => $node->x === $edge->minX && \abs($node->y + ($node->h / 2) - $edge->minY) < 1e-9);
            if (!$edge->decoration && $edge->minX === $edge->maxX && null !== $header) {
                $lines[(string) $header->name] = $edge;
            }
        }

        return $lines;
    }

    public function testNestedControlsAndActivationIntervals(): void
    {
        $source = 'sequenceDiagram; activate A; participant A; participant B; loop retry; alt ready; A->>+B: request; activate B; B-->>-A: response; deactivate B; else wait; opt maybe; end; end; end; par jobs; and other; end; critical section; option fallback; end; rect blue; break stop; end; end; par_over overlap; end';
        $diagram = self::parse($source);
        $this->assertCount(8, $diagram->frames);
        $this->assertCount(3, $diagram->activations);
        $this->assertNull($diagram->activations[0]['end']);
        $this->assertSame(['A', 'B'], \array_keys($diagram->participants));
        $this->assertSame(1, $diagram->activations[2]['depth']);
        $this->assertGreaterThan($diagram->activations[2]['end'], $diagram->activations[1]['end']);
        $scene = self::scene($source);
        $this->assertCount(8, $scene->graph->clusters);
        $bars = \array_values(\array_filter($scene->graph->nodes, static fn (GraphNode $node): bool => \str_starts_with($node->name, 'activation')));
        $this->assertCount(3, $bars);
        $this->assertSame($bars[1]->x + 2, $bars[2]->x);
        $this->assertSame($scene->graph->height - 2, $bars[0]->y + ($bars[0]->h / 2));
        $this->assertStringContainsString('A->>+B: request', \implode("\n", \array_column($scene->legend, 'text')));
        $empty = self::scene('sequenceDiagram; loop; end');
        $this->assertCount(1, $empty->graph->clusters);
    }

    public function testControlOriginsAndMessageAnchoredBars(): void
    {
        $source = "sequenceDiagram\nparticipant A\nparticipant B\nloop outer\nalt inner\nA->>+B: request\nB-->>-A: reply\nelse wait\nend\nend\nactivate A\ndeactivate A";
        $diagram = self::parse($source);
        $frames = \array_values($diagram->frames);
        $this->assertSame([0, 1], \array_column($frames, 'depth'));
        $this->assertSame([3, 4], \array_column($frames, 'origin'));
        $this->assertSame('B', $diagram->activations[0]['actor']);
        $this->assertSame(2, $diagram->activations[0]['start']);
        $this->assertSame(4, $diagram->activations[0]['end']);
        $scene = self::scene($source);
        [$outer, $inner] = $scene->graph->clusters;
        $this->assertLessThan($inner->x0, $outer->x0);
        $this->assertGreaterThan($inner->x1, $outer->x1);
        $this->assertLessThan($inner->y0, $outer->y0);
        $this->assertGreaterThan($inner->y1, $outer->y1);
        $bars = \array_values(\array_filter($scene->graph->nodes, static fn (GraphNode $node): bool => \str_starts_with($node->name, 'activation')));
        $this->assertSame($scene->graph->edges[0]->points[0][1], $bars[0]->y - ($bars[0]->h / 2));
        $this->assertSame($scene->graph->edges[1]->points[0][1], $bars[0]->y + ($bars[0]->h / 2));
        $this->assertSame(2.0, $bars[1]->h);
        $this->assertSame(3, $scene->origins[(int) (($outer->y0 + 2) / 2)]);
        $numbered = self::parse('sequenceDiagram; A->B: hidden; autonumber; A->B: shown');
        $this->assertSame('2. shown', $numbered->events[1]['label']);
    }

    public function testDeepStacksFitAndSelfActivationUsesReceivingEndpoint(): void
    {
        $scene = self::scene('sequenceDiagram; participant A; participant B; loop deep; '.\str_repeat('activate B; ', times: 128).'A->>B: active; end');
        $frame = $scene->graph->clusters[0];
        foreach ($scene->graph->nodes as $node) {
            if (!\str_starts_with($node->name, 'activation')) {
                continue;
            }
            $this->assertLessThan($frame->x1, $node->x + ($node->w / 2));
            $this->assertLessThan($scene->graph->width, $node->x + ($node->w / 2));
        }
        $self = self::scene('sequenceDiagram; A->>+A: start; A->>-A: stop');
        $bar = \array_first($self->graph->nodes);
        $this->assertSame($self->graph->edges[0]->arrowTip[1], $bar->y - ($bar->h / 2));
        $this->assertSame($self->graph->edges[1]->points[0][1], $bar->y + ($bar->h / 2));
    }

    public function testDeepControlFramesContainParticipantsAndReverseSelfAnchors(): void
    {
        $scene = self::scene('sequenceDiagram; participant A; participant B; '.\str_repeat('loop; ', times: 128).'A->>B: inner; '.\str_repeat('end; ', times: 128));
        $inner = \array_last($scene->graph->clusters);
        foreach ($scene->graph->nodes as $node) {
            $this->assertGreaterThan($inner->x0, $node->x - ($node->w / 2));
            $this->assertLessThan($inner->x1, $node->x + ($node->w / 2));
        }
        $self = self::scene('sequenceDiagram; A/|-+A: start; A/|---A: stop');
        $bar = \array_first($self->graph->nodes);
        // A half-arrow has no filled tip: the line's end marks the arrival.
        $lines = \array_values(\array_filter($self->graph->edges, static fn (GraphEdge $edge): bool => !$edge->decoration));
        $this->assertEqualsWithDelta(\array_last($lines[0]->points)[1], $bar->y - ($bar->h / 2), 1e-9);
        $this->assertSame($lines[1]->points[0][1], $bar->y + ($bar->h / 2));
    }

    public function testControlBordersMapToOpeningAndClosingSourceStatements(): void
    {
        $scene = self::scene("sequenceDiagram\nparticipant A\nloop retry\nA->>A: message\nend");
        $frame = $scene->graph->clusters[0];
        $this->assertSame(2, $scene->origins[(int) ($frame->y0 / 2)]);
        $this->assertSame(4, $scene->origins[(int) ($frame->y1 / 2)]);
        $this->assertCount((int) ($scene->graph->height / 2), $scene->origins);
        $this->assertSame(1, \array_last(self::scene("sequenceDiagram\nparticipant A")->origins));
    }

    public function testActivationBackgroundPreservesMessageLineAndArrow(): void
    {
        $scene = self::scene('sequenceDiagram; participant A; participant B; activate B; A->>B: one; A->>B: two')->themed(MermaidPalette::defaults('sequenceDiagram'));
        $view = new ZoomPanModel((int) $scene->graph->width, (int) $scene->graph->height, [1.0]);
        $rows = \array_map(AnsiUtils::stripAnsiCodes(...), GraphRenderer::render($scene->graph, $view, null, 100, 60, Styles::dark()));
        $grid = new GraphCellGrid(100, 60, $view, null);
        foreach (\array_slice($scene->graph->edges, offset: 0, length: 2) as $edge) {
            $row = $grid->rowOf($edge->arrowTip[1]);
            $column = $grid->colOf($edge->arrowTip[0]);
            $this->assertSame('▸', \mb_substr($rows[$row], $column, length: 1));
            $this->assertSame('─', \mb_substr($rows[$row], $column - 1, length: 1));
        }
    }

    #[DataProvider('invalidControls')]
    public function testInvalidControls(string $body, string $error): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains($error);
        self::parse('sequenceDiagram; '.$body);
    }

    public static function invalidControls(): iterable
    {
        yield ['nonsense', 'Expected a sequence'];
        yield ['end', 'Unexpected sequence end'];
        yield ['else other', 'Sequence branch does not belong'];
        yield ['loop; and other', 'Sequence branch does not belong'];
        yield ['activate Missing', 'Unknown sequence activation participant'];
        yield [\str_repeat('loop; ', times: 129), 'depth limit'];
        yield ['participant A; '.\str_repeat('activate A; ', times: 129), 'depth limit'];
    }

    public function testMetadataPreservesLiteralAccessibilityTextAndOrigins(): void
    {
        $source = <<<'MMD'
            sequenceDiagram
            title first
            title: final 漢字
            accTitle: heading; # literal %% text
            accDescr {
            "unclosed quote; { literal
            # and %% stay
            }
            participant A
            A->>A: message
            MMD;
        $diagram = self::parse($source);
        $this->assertSame('final 漢字', $diagram->metadata['title']['label']);
        $this->assertSame('heading; # literal %% text', $diagram->metadata['acctitle']['label']);
        $this->assertSame("\"unclosed quote; { literal\n# and %% stay", $diagram->metadata['accdescr']['label']);
        $this->assertSame([2, 3, 4], \array_column($diagram->metadata, 'origin'));
        $scene = self::scene($source);
        $nodes = \array_values($scene->graph->nodes);
        $this->assertSame('title', $nodes[0]->name);
        $this->assertSame('A', $nodes[3]->name);
        $this->assertGreaterThan($nodes[2]->y + ($nodes[2]->h / 2), $nodes[3]->y - ($nodes[3]->h / 2));
        $this->assertSame(4, $scene->origins[(int) ($nodes[2]->y / 2)]);
        $single = self::parse("sequenceDiagram\naccDescr: text; # literal\n# comment\naccTitle:");
        $this->assertSame('text; # literal', $single->metadata['accdescr']['label']);
        $this->assertSame('', $single->metadata['acctitle']['label']);
    }

    public function testLifetimesAnchorBirthDeathAndOpenActivation(): void
    {
        $source = 'sequenceDiagram; participant A; create actor B as Born; A->>+B: hello; B->>A: reply; destroy B; A--xB: bye';
        $diagram = self::parse($source);
        $this->assertSame(['create' => 1, 'destroy' => 5], $diagram->lifetimes['B']);
        $scene = self::scene($source)->themed(MermaidPalette::defaults('sequenceDiagram'));
        $nodes = \array_values($scene->graph->nodes);
        $born = \array_find($nodes, static fn (GraphNode $node): bool => 'B' === $node->name);
        $reply = \array_find($nodes, static fn (GraphNode $node): bool => 'reply' === $node->label);
        $this->assertGreaterThan($born->y + ($born->h / 2), $reply->y - ($reply->h / 2));
        $dead = \array_last(\array_filter($nodes, static fn (GraphNode $node): bool => 'B' === $node->name));
        $this->assertNotSame($born, $dead, 'a destroyed participant gets its footer where it dies, as pinned');
        $bar = $nodes[0];
        $hello = $scene->graph->edges[0];
        // The creating message meets the side of the header centered on it.
        $this->assertSame([$born->x - ($born->w / 2), $born->y], $hello->arrowTip);
        $bye = $scene->graph->edges[2];
        $this->assertNull($bye->arrowTip, 'a cross has no filled tip');
        $this->assertSame($bye->points[0][1], $bar->y + ($bar->h / 2));
        $this->assertSame($bye->points[0][1], $dead->y);
        $this->assertEqualsWithDelta($dead->x - ($dead->w / 2) - 0.32, \array_last($bye->points)[0], 1e-9, 'the line stops short of the cross');
        $lifeline = self::lifelines($scene)['B'];
        $this->assertEqualsWithDelta($born->y + ($born->h / 2), $lifeline->minY, 1e-9);
        $this->assertSame($dead->y - ($dead->h / 2), $lifeline->maxY);
        $this->assertFalse($lifeline->dashed, 'lifelines are solid, as pinned');
        $this->assertLessThan($scene->graph->height, $dead->y + ($dead->h / 2));
        $view = new ZoomPanModel((int) $scene->graph->width, (int) $scene->graph->height, [1.0]);
        $rows = \array_map(AnsiUtils::stripAnsiCodes(...), GraphRenderer::render($scene->graph, $view, null, 100, 60, Styles::dark()));
        $grid = new GraphCellGrid(100, 60, $view, null);
        $this->assertStringContainsString('▸', \mb_substr($rows[$grid->rowOf($born->y)], $grid->colOf($born->x - ($born->w / 2)) - 1, length: 2));
        $simultaneous = self::scene('sequenceDiagram; create participant 0; destroy 0; 1->>0: fleeting');
        $headers = \array_values(\array_filter($simultaneous->graph->nodes, static fn (GraphNode $node): bool => '0' === (string) $node->name));
        $this->assertCount(2, $headers);
        $this->assertSame($headers[0]->y + $headers[0]->h + 2, $headers[1]->y, 'one message creating and destroying stacks both headers');
        $this->assertLessThan($simultaneous->graph->height, \max(\array_map(static fn (GraphNode $node): float => $node->y + ($node->h / 2), $simultaneous->graph->nodes)));
        $sender = self::scene('sequenceDiagram; destroy A; A->>B: last');
        $footer = \array_last(\array_filter($sender->graph->nodes, static fn (GraphNode $node): bool => 'A' === $node->name));
        $this->assertSame($sender->graph->edges[0]->points[0][1], $footer->y);
        $this->assertSame($footer->x + ($footer->w / 2), $sender->graph->edges[0]->points[0][0], 'a message from a dying participant leaves its footer');
        $this->assertSame($footer->y - ($footer->h / 2), self::lifelines($sender)['A']->maxY);
        $this->assertCount(1, self::parse('sequenceDiagram; create participant X; Note over A: pause; A->>X: create')->lifetimes);
        $self = self::scene('sequenceDiagram; participant A; destroy A; A/|-A: end');
        $selfFooter = \array_last(\array_filter($self->graph->nodes, static fn (GraphNode $node): bool => 'A' === $node->name));
        $this->assertSame(\array_last($self->graph->edges[0]->points)[1], $selfFooter->y, 'a self message never moves to a header side');
    }

    public function testTallLifecycleHeadersReserveTheirLowerHalf(): void
    {
        $tall = 'One<br>Two<br>Three<br>Four';
        foreach ([
            'created' => "sequenceDiagram; participant A; create participant B as {$tall}; A->>B: make; A->>A: next",
            'destroyed' => "sequenceDiagram; participant A; participant B as {$tall}; destroy B; A->>B: end; A->>A: next",
            'dying sender' => "sequenceDiagram; participant A; participant B as {$tall}; destroy B; B->>A: end; A->>A: next",
            'both' => "sequenceDiagram; participant A; create participant B as {$tall}; destroy B; A->>B: brief; A->>A: next",
        ] as $case => $source) {
            $scene = self::scene($source);
            $headers = \array_filter($scene->graph->nodes, static fn (GraphNode $node): bool => 'B' === $node->name);
            $lowest = \max(\array_map(static fn (GraphNode $node): float => $node->y + ($node->h / 2), $headers));
            $next = \array_find($scene->graph->nodes, static fn (GraphNode $node): bool => 'next' === $node->label);
            $this->assertGreaterThan($lowest, $next->y - ($next->h / 2), $case.': the next row starts below the header');
        }
    }

    #[DataProvider('invalidLifecycle')]
    public function testInvalidLifecycleAndMetadata(string $body, string $error): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains($error);
        self::parse('sequenceDiagram; '.$body);
    }

    public static function invalidLifecycle(): iterable
    {
        yield ['create participant A', 'requires an associated message'];
        yield ['destroy A', 'requires an associated message'];
        yield ['create participant A; create participant B; C->>B: second', 'before another directive'];
        yield ['participant A; create participant A; A->>A: duplicate', 'existing sequence participant'];
        yield ['create participant A; B->>C: wrong', 'does not match'];
        yield ['destroy A; B->>C: wrong', 'does not match'];
        yield ['destroy A; A->B: end; destroy A; A->B: again', 'twice'];
        yield ['accDescr { unclosed', 'Unclosed accessibility description'];
        yield ['accDescr {first} trailing}', 'Expected a sequence'];
    }

    public function testParticipantsMessagesNotesAndTextualEntities(): void
    {
        $diagram = self::parse(<<<'MMD'
            %% comment
            sequenceDiagram
            participant B as Bob<br/>漢字 # declaration comment
            actor A as Alice
            A->>B: "Hello" | [unclosed ( #59; #9829; #infin;
            B-->>A: nowrap: reply # message comment
            Note right of B: right
            Note left of A: left
            Note over A,B: pair<br/>line
            Note over C: implicit
            participant B as Updated
            participant A
            A->C:
            MMD);
        $this->assertSame(['B', 'A', 'C'], \array_keys($diagram->participants));
        $this->assertSame('Updated', $diagram->participants['B']['label']);
        $this->assertSame('Alice', $diagram->participants['A']['label']);
        $this->assertCount(7, $diagram->events);
        $this->assertSame('"Hello" | [unclosed ( ; ♥ ∞', $diagram->events[0]['label']);
        $this->assertSame('reply', $diagram->events[1]['label']);
        $this->assertSame("pair\nline", $diagram->events[4]['label']);
        $this->assertSame('', $diagram->events[6]['label']);
        $this->assertSame(4, $diagram->events[0]['origin']);
        $this->assertSame('#unknown; #1114112; &', MermaidText::decode('#unknown; #1114112; #amp;'));
        $longEntity = '#'.\str_repeat('9', times: 40).';';
        $this->assertSame($longEntity, self::parse('sequenceDiagram; A->B: '.$longEntity)->events[0]['label']);
        $this->assertCount(0, self::parse('sequenceDiagram')->participants);
        $this->assertSame(0, self::scene('sequenceDiagram')->graph->nodeCount());
    }

    public function testChronologyNotesAndMultilineLabelsInSharedScene(): void
    {
        $scene = self::scene(<<<'MMD'
            sequenceDiagram
            participant 1 as 漢字<br/>one
            participant 2
            1->>2: first<br/>second
            Note over 1,2: span
            Note left of 1: left
            Note right of 2: right
            2-->1: return
            1->>1: self
            MMD);
        $this->assertSame(['1', '2'], \array_map(static fn (GraphNode $node): string => $node->name, \array_slice($scene->graph->nodes, offset: 0, length: 2)));
        $nodes = \array_values($scene->graph->nodes);
        $this->assertLessThan($nodes[1]->x, $nodes[0]->x);
        $this->assertSame("漢字\none", $nodes[0]->label);
        $this->assertSame("first\nsecond", $nodes[2]->label);
        $this->assertGreaterThan($nodes[2]->y, $nodes[3]->y);
        $this->assertGreaterThan($nodes[1]->x - $nodes[0]->x, $nodes[3]->w);
        $this->assertLessThan($nodes[0]->x, $nodes[4]->x);
        $this->assertGreaterThan($nodes[1]->x, $nodes[5]->x);
        $this->assertSame(1, $scene->origins[0]);
        $this->assertSame(8, $scene->origins[(int) ($nodes[7]->y / 2)]);
        $this->assertCount(5, $scene->graph->edges);
        $this->assertNull($scene->graph->edges[1]->arrowTip, 'open arrow has no arrowhead');
        $this->assertCount(13, $scene->graph->edges[2]->points, 'a self message curves around its lifeline, as pinned');
        $this->assertStringContainsString('Note over 1,2: span', $scene->legend[3]['text']);
    }

    public function testAllPinnedArrowTokensAndCentralConnections(): void
    {
        foreach (SequenceParser::ARROWS as $arrow) {
            $scene = self::scene('sequenceDiagram; A'.$arrow.'B: message');
            $this->assertSame('A'.$arrow.'B: message', $scene->legend[2]['text']);
            $this->assertSame(\str_contains($arrow, '--'), $scene->graph->edges[0]->dashed);
            $this->assertSame(\str_starts_with($arrow, '<<') ? 4 : 3, $scene->graph->edgeCount());
            $forward = SequenceArrowheads::forward($arrow);
            $receiver = $scene->graph->nodes[$forward === $arrow ? 1 : 0]->x;
            $tip = $scene->graph->edges[0]->arrowTip;
            $this->assertSame(\str_ends_with($arrow, '>>') ? $receiver : null, $tip[0] ?? null, $arrow);
            $marks = \array_values(\array_filter($scene->graph->edges, static fn (GraphEdge $edge): bool => $edge->decoration));
            $expected = match (true) {
                \str_ends_with($forward, '>'), \str_ends_with($forward, '>>') => 0,
                \str_ends_with($forward, 'x') => 2,
                default => 1,
            };
            $this->assertCount($expected, $marks, $arrow);
            foreach ($marks as $mark) {
                $this->assertLessThan(1.5, \max(\abs($mark->minX - $receiver), \abs($mark->maxX - $receiver)), $arrow.' marks its receiver');
                $this->assertSame(\str_ends_with($forward, 'x') || \str_ends_with($forward, '\\\\') || \str_ends_with($forward, '//'), null === $mark->ribbon, $arrow);
            }
        }
        foreach (['-|\\' => -1, '-\\\\' => -1, '-|/' => 1, '-//' => 1, '/|-' => -1, '//-' => -1] as $arrow => $side) {
            $scene = self::scene('sequenceDiagram; A'.$arrow.'B: half');
            $line = $scene->graph->edges[0]->points[0][1];
            $mark = \array_find($scene->graph->edges, static fn (GraphEdge $edge): bool => $edge->decoration);
            $reach = -1 === $side ? $line - $mark->minY : $mark->maxY - $line;
            // Forward, a top half-arrow stands above the line; reversed, the pinned marker turns over.
            $this->assertGreaterThan(0.4, $reach, $arrow);
            $this->assertLessThan(0.1, -1 === $side ? $mark->maxY - $line : $line - $mark->minY, $arrow);
        }
        foreach (['()->>' => ['A'], '->>()' => ['B'], '()->>()' => ['A', 'B'], '()-x' => ['A'], '()/|-' => ['A'], '/|-()' => ['B']] as $arrow => $dotted) {
            $diagram = self::parse('sequenceDiagram; A'.$arrow.'B: center');
            $this->assertSame(['A', 'B'], \array_keys($diagram->participants));
            $this->assertSame($arrow, $diagram->events[0]['arrow']);
            $scene = self::scene('sequenceDiagram; A'.$arrow.'B: center');
            $dots = \array_values(\array_filter($scene->graph->edges, static fn (GraphEdge $edge): bool => $edge->decoration && 16 === \count($edge->points)));
            $centers = \array_map(static fn (GraphEdge $dot): float => ($dot->minX + $dot->maxX) / 2, $dots);
            $positions = \array_map(static fn (string $actor): float => \array_find($scene->graph->nodes, static fn (GraphNode $node): bool => $actor === $node->name)->x, $dotted);
            $this->assertEqualsWithDelta($positions, $centers, 1e-9, $arrow.' dots the lifelines it names');
            $this->assertSame([0.0], \array_unique(\array_map(static fn (GraphEdge $dot): float => $dot->strokeWidth, $dots)));
            foreach ($dots as $dot) {
                $this->assertEqualsWithDelta([0.8, 0.8], [$dot->maxX - $dot->minX, $dot->maxY - $dot->minY], 1e-9, 'a dot five pinned pixels in radius');
            }
        }
    }

    public function testNumberingStartIncrementOffResumeAndFractionalPrecision(): void
    {
        $diagram = self::parse('sequenceDiagram; autonumber .1 .1; A->B: a; A->B: b; A->B: c; autonumber off; A->B: d; autonumber; A->B: e; autonumber 10; A->B: f; A->B: g');
        $this->assertSame(['0.1. a', '0.2. b', '0.3. c', 'd', '0.5. e', '10. f', '11. g'], \array_column($diagram->events, 'label'));
    }

    public function testLargeAutonumbersKeepExactHundredths(): void
    {
        $source = 'sequenceDiagram; autonumber 999999999999.99 .01; '.\str_repeat('A->B: message;', times: 100);
        $diagram = self::parse($source);
        $this->assertSame('999999999999.99. message', $diagram->events[0]['label']);
        $this->assertSame('1000000000000. message', $diagram->events[1]['label']);
        $this->assertSame('1000000000000.98. message', $diagram->events[99]['label']);
        $this->assertSame('1. message', self::parse('sequenceDiagram; autonumber 000000000000001; A--B->C: message')->events[0]['label']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidSources(): iterable
    {
        yield 'header' => ['graph TD', 'Expected sequenceDiagram'];
        yield 'empty' => ['', 'Expected sequenceDiagram'];
        yield 'unknown' => ["sequenceDiagram\nloop retry", 'Unclosed sequence control block'];
        yield 'arrow' => ['sequenceDiagram; A->>>B: message', 'Invalid sequence participant'];
        yield 'activation' => ['sequenceDiagram; A()->>+B: message', 'Activation shortcuts cannot combine'];
        yield 'deactivation' => ['sequenceDiagram; A-->>-B: message', 'Cannot deactivate'];
        yield 'participant' => ['sequenceDiagram; participant A+', 'Invalid sequence participant'];
        yield 'empty note actor' => ['sequenceDiagram; Note over A,: note', 'Invalid sequence participant'];
        yield 'over' => ['sequenceDiagram; Note over A,B,C: note', 'Invalid note'];
        yield 'left' => ['sequenceDiagram; Note left of A,B: note', 'Invalid note'];
        yield 'number off' => ['sequenceDiagram; autonumber off 1', 'takes no increment'];
        yield 'number large start' => ['sequenceDiagram; autonumber 1'.\str_repeat('0', times: 308).' 1'.\str_repeat('0', times: 308).'; A->B: overflow', 'numeric limit'];
        yield 'number whole limit' => ['sequenceDiagram; autonumber 1000000000001', 'numeric limit'];
        yield 'number step limit' => ['sequenceDiagram; autonumber 1 1000000000001', 'numeric limit'];
        yield 'bad arrow dashes' => ['sequenceDiagram; A--->B: message', 'Invalid sequence participant'];
        yield 'number overflow' => ['sequenceDiagram; autonumber '.\str_repeat('9', times: 400), 'numeric limit'];
    }

    #[DataProvider('invalidSources')]
    public function testInvalidStatementsHaveLocatedErrors(string $source, string $expected): void
    {
        try {
            self::parse($source);
            $this->fail('Invalid sequence accepted');
        } catch (MermaidSyntaxError $error) {
            $this->assertStringContainsString($expected, $error->getMessage());
            $this->assertStringContainsString('Line ', $error->getMessage());
        }
    }

    public function testParticipantAndEventLimits(): void
    {
        $parser = new SequenceParser();
        $parser->accept(new MermaidStatement('sequenceDiagram', 0, 0, 0));
        for ($i = 0; $i < MermaidGraph::MAX_ITEMS; ++$i) {
            $parser->accept(new MermaidStatement('participant P'.$i, 0, 1, 0));
        }
        try {
            $parser->accept(new MermaidStatement('participant overflow', 0, 2, 0));
            $this->fail('Participant limit ignored');
        } catch (MermaidSyntaxError $error) {
            $this->assertStringContainsString('participant limit', $error->getMessage());
        }
        for ($i = 0; $i < MermaidGraph::MAX_ITEMS; ++$i) {
            $parser->accept(new MermaidStatement('P0->P1: message', 0, 3, 0));
        }
        $this->expectExceptionMessageIsOrContains('event limit');
        $parser->accept(new MermaidStatement('P0->P1: overflow', 0, 4, 0));
    }

    public function testNativeDispatchNarrowRowsCancellationAndErrors(): void
    {
        $scheduler = new FakeScheduler();
        $renderer = new NativeMermaidRenderer(new MermaidTask($scheduler));
        $result = null;
        $error = null;
        $source = "# comment\nSEQUENCEDIAGRAM\nparticipant A as 漢字\nA->>B: message";
        $cancel = $renderer->rows(
            $source,
            3,
            static function (FenceResult $value) use (&$result): void {
                $result = $value;
            },
            static function (string $value) use (&$error): void {
                $error = $value;
            },
        );
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $this->assertNull($error);
        $this->assertInstanceOf(FenceResult::class, $result);
        $this->assertStringContainsString('message', \implode('', $result->rows));
        $this->assertContains(3, $result->origins);
        $cancel();
        $large = 'sequenceDiagram; '.\str_repeat('A->>B: hello;', times: 300);
        $scene = null;
        $cancel = $renderer->render(
            $large,
            static function (MermaidScene $value) use (&$scene): void {
                $scene = $value;
            },
            static function (string $error): never {
                self::fail($error);
            },
        );
        $scheduler->fire();
        $this->assertNull($scene);
        $cancel();
        $this->assertSame([], $scheduler->scheduled);
        $renderer->render(
            'sequenceDiagram; loop retry',
            static function (): never {
                self::fail('Unsupported statement accepted');
            },
            static function (string $value) use (&$error): void {
                $error = $value;
            },
        );
        // The error arrives once the parse finishes, however many slices that takes.
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $this->assertStringContainsString('Line 1', $error);
    }
}
