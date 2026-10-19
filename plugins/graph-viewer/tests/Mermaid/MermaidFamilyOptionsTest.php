<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphCluster;
use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphFont;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\MermaidPalette;
use Porta\GraphViewer\Mermaid\MermaidScene;

/** Pinned family options that change the native picture, one family at a time. */
final class MermaidFamilyOptionsTest extends TestCase
{
    public function testQuadrantAxesAndPointRadius(): void
    {
        $empty = "quadrantChart\nx-axis Low --> High\ny-axis Down --> Up\n";
        $top = self::scene([], $empty);
        $bottom = self::scene(['quadrantChart' => ['xAxisPosition' => 'bottom']], $empty);
        $this->assertLessThan($top->graph->clusters[0]->y0, self::named($top, 'x-axis-low')->y);
        $this->assertGreaterThan($bottom->graph->clusters[2]->y1, self::named($bottom, 'x-axis-low')->y);
        // Points keep the X axis below whatever the option says.
        $points = $empty."A: [0.5, 0.5]\n";
        $withPoints = self::scene(['quadrantChart' => ['xAxisPosition' => 'top']], $points);
        $this->assertGreaterThan($withPoints->graph->clusters[2]->y1, self::named($withPoints, 'x-axis-low')->y);
        $right = self::scene(['quadrantChart' => ['yAxisPosition' => 'right']], $empty);
        $this->assertGreaterThan($right->graph->clusters[0]->x1, self::named($right, 'y-axis-low')->x);
        $this->assertLessThan($top->graph->clusters[1]->x0, self::named($top, 'y-axis-low')->x);
        $this->assertGreaterThan($top->graph->width, $right->graph->width);
        $point = static fn (MermaidScene $scene): ?GraphNode => \array_find($scene->graph->nodes, static fn (GraphNode $node): bool => \str_starts_with($node->name, 'point-'));
        $this->assertSame(1.0, $point(self::scene([], $points))?->w);
        $this->assertSame(2.4, $point(self::scene(['quadrantChart' => ['pointRadius' => 12]], $points))?->w);
    }

    public function testSankeyAlignmentLabelsAndColors(): void
    {
        $body = "sankey-beta\nA,B,10\nB,C,4\nD,C,2.345\n";
        static fn (MermaidScene $scene, string $label): float => (float) \array_find(
            $scene->graph->nodes,
            static fn (GraphNode $node): bool => $label === $node->label && \str_starts_with($node->name, 'sankey-'),
        )?->x;
        // D has no input: justify and left keep it at the start; right and center move it beside C's other source.
        $this->assertSame(self::sankeyX(self::scene([], $body), label: 'A'), self::sankeyX(self::scene([], $body), label: 'D'));
        $this->assertSame(self::sankeyX(self::scene([], $body), label: 'A'), self::sankeyX(self::scene(['sankey' => ['nodeAlignment' => 'left']], $body), label: 'D'));
        $this->assertSame(self::sankeyX(self::scene([], $body), label: 'B'), self::sankeyX(self::scene(['sankey' => ['nodeAlignment' => 'right']], $body), label: 'D'));
        $this->assertSame(self::sankeyX(self::scene([], $body), label: 'B'), self::sankeyX(self::scene(['sankey' => ['nodeAlignment' => 'center']], $body), label: 'D'));
        $this->assertSame(self::sankeyX(self::scene([], $body), label: 'A'), self::sankeyX(self::scene(['sankey' => ['nodeAlignment' => 'left']], "sankey-beta\nA,B,1\nA,C,1\nC,D,1\n"), label: 'A'));
        $sink = "sankey-beta\nA,B,1\nA,C,1\nC,D,1\n";
        $this->assertSame(self::sankeyX(self::scene([], $sink), label: 'D'), self::sankeyX(self::scene([], $sink), label: 'B'), 'justified sinks share the last layer');
        $this->assertLessThan(self::sankeyX(self::scene([], $sink), label: 'D'), self::sankeyX(self::scene(['sankey' => ['nodeAlignment' => 'left']], $sink), label: 'B'));
        $this->assertLessThan(self::sankeyX(self::scene([], $sink), label: 'D'), self::sankeyX(self::scene(['sankey' => ['nodeAlignment' => 'center']], $sink), label: 'B'));
        $this->assertSame("D\n2.35", self::named(self::scene([], $body), name: 'caption-3')->label);
        $this->assertSame("D\n$2.35 k", self::named(self::scene(['sankey' => ['prefix' => '$', 'suffix' => ' k']], $body), name: 'caption-3')->label);
        $this->assertSame('D', self::named(self::scene(['sankey' => ['showValues' => false]], $body), name: 'caption-3')->label);
        // Legacy labels face the middle; outlined labels face away from the central layer.
        $plain = self::scene([], $body);
        $this->assertGreaterThan(self::named($plain, 'sankey-0')->x, self::named($plain, name: 'caption-0')->x);
        $this->assertLessThan(self::named($plain, 'sankey-2')->x, self::named($plain, name: 'caption-2')->x);
        $outlined = self::scene(['sankey' => ['labelStyle' => 'outlined']], $body);
        $this->assertLessThan(self::named($outlined, 'sankey-0')->x, self::named($outlined, name: 'caption-0')->x);
        $this->assertGreaterThan(self::named($outlined, 'sankey-1')->x, self::named($outlined, name: 'caption-1')->x);
        $colored = self::scene(['sankey' => ['nodeColors' => ['B' => 'red'], 'linkColor' => 'target']], $body);
        $this->assertSame('#ff0000', self::named($colored, 'sankey-1')->fillColor);
        $this->assertSame('#ff000080', $colored->graph->edges[0]->color);
        $this->assertSame('#4e79a780', $plain->graph->edges[0]->color);
        $this->assertSame('#4e79a780', self::scene(['sankey' => ['linkColor' => 'source']], $body)->graph->edges[0]->color);
        $this->assertSame('#00ff0080', self::scene(['sankey' => ['linkColor' => '#00ff00']], $body)->graph->edges[0]->color);
    }

    public function testSequenceMessageAlignmentAndSelfMessageShape(): void
    {
        $body = "sequenceDiagram\nparticipant A\nparticipant B\nA->>B: hi\nA->>A: self\n";
        $label = static fn (MermaidScene $scene): GraphNode => \array_first(\array_filter($scene->graph->nodes, static fn (GraphNode $node): bool => 'hi' === $node->label));
        $plain = self::scene([], $body);
        [$from, $to] = [$plain->graph->edges[0]->minX, $plain->graph->edges[0]->maxX];
        $this->assertEqualsWithDelta(($from + $to) / 2, $label($plain)->x, 0.6);
        $left = $label(self::scene(['sequence' => ['messageAlign' => 'left']], $body));
        $right = $label(self::scene(['sequence' => ['messageAlign' => 'right']], $body));
        $this->assertEqualsWithDelta($from + ($left->w / 2), $left->x, 0.6);
        $this->assertEqualsWithDelta($to - ($right->w / 2), $right->x, 0.6);
        $loop = static fn (MermaidScene $scene): int => \count(\array_last(\array_filter(
            $scene->graph->edges,
            static fn (GraphEdge $edge): bool => $edge->minX === $edge->points[0][0] && \count($edge->points) > 2,
        ))->points);
        $this->assertSame(13, $loop($plain));
        $this->assertSame(4, $loop(self::scene(['sequence' => ['rightAngles' => true]], $body)));
    }

    public function testSequenceFontsAndNoteAlignment(): void
    {
        $body = "sequenceDiagram\nparticipant A\nparticipant B\nA->>B: hi\nNote over A,B: wide<br>note\n";
        $find = static fn (MermaidScene $scene, string $label): GraphNode => \array_first(\array_filter($scene->graph->nodes, static fn (GraphNode $node): bool => $label === $node->label));
        $plain = self::scene([], $body);
        $this->assertSame([1.0, GraphFont::Regular, 1.0], [$find($plain, label: 'hi')->fontScale, $find($plain, label: 'hi')->font, $find($plain, label: "wide\nnote")->fontScale]);
        $this->assertArrayNotHasKey('node:'.$find($plain, label: "wide\nnote")->id, $plain->runs, 'centered notes need no runs');
        $styled = self::scene(['sequence' => ['messageFontSize' => 24, 'messageFontWeight' => 'bold', 'noteFontSize' => '21px', 'actorFontWeight' => 700, 'noteAlign' => 'left']], $body);
        $message = $find($styled, label: 'hi');
        $note = $find($styled, label: "wide\nnote");
        $this->assertSame([1.5, GraphFont::Bold, 1.5, GraphFont::Regular], [$message->fontScale, $message->font, $note->fontScale, $note->font]);
        $this->assertSame(7.0, $message->h, 'the scaled text sizes its box');
        $this->assertSame(GraphFont::Bold, $find($styled, label: 'A')->font, 'actors take their weight');
        $columns = (int) \round(($note->w - 4) / 1.5);
        $this->assertSame([['wide', GraphFont::Regular], [\str_repeat(' ', $columns - 4), GraphFont::Regular]], $styled->runs['node:'.$note->id][0], 'left-aligned lines pad on the right');
        $right = self::scene(['sequence' => ['noteAlign' => 'right']], $body);
        $note = $find($right, label: "wide\nnote");
        $this->assertSame([[\str_repeat(' ', (int) \round($note->w - 4) - 4), GraphFont::Regular], ['note', GraphFont::Regular]], $right->runs['node:'.$note->id][1]);
    }

    /** @return iterable<string, array{string, string, string, float}> */
    public static function fontSizes(): iterable
    {
        // Each family's pinned default size draws as native text's own; twice it, twice as large.
        yield 'er' => ['er', 24.0, "erDiagram\nCUSTOMER { string name }\nCUSTOMER ||--o{ ORDER : places\n", 'CUSTOMER'];
        yield 'requirement' => ['requirement', 28.0, "requirementDiagram\nrequirement r {\nid: 1\n}\n", 'r'];
        yield 'state' => ['state', 48.0, "stateDiagram-v2\n[*] --> Moving\n", 'Moving'];
    }

    #[DataProvider('fontSizes')]
    public function testFamilyFontSizesScaleTheirCards(string $family, float $size, string $body, string $name): void
    {
        $node = static fn (MermaidScene $scene): GraphNode => self::named($scene, $name);
        $plain = $node(self::scene([], $body));
        $large = $node(self::scene([$family => ['fontSize' => $size]], $body));
        $this->assertSame([1.0, 2.0], [$plain->fontScale, $large->fontScale]);
        $this->assertGreaterThan($plain->w, $large->w, 'layout measures the larger text');
        $authored = $node(self::scene([$family => ['fontSize' => $size]], $body.'style '.$name." font-size:8px\n"));
        $this->assertSame(0.5, $authored->fontScale, 'an authored size still wins');
        $default = $node(self::scene([$family => ['fontSize' => $size]], $body."classDef default font-size:8px\n"));
        $this->assertSame(0.5, $default->fontScale, 'so does an authored default class');
        $this->assertSame(1.0, $node(self::scene([$family => ['fontSize' => 0]], $body))->fontScale, 'a zero size keeps the default');
    }

    public function testGanttCompactRowsAndAxisOptions(): void
    {
        $body = "gantt\ndateFormat YYYY-MM-DD\nsection Work\nA :a, 2024-01-01, 2d\nB :b, 2024-01-02, 2d\nC :c, 2024-01-03, 1d\n";
        $plain = self::scene([], $body);
        $this->assertLessThan(self::named($plain, name: 'b')->y, self::named($plain, name: 'a')->y);
        $this->assertLessThan(self::named($plain, name: 'c')->y, self::named($plain, name: 'b')->y);
        // Compact: C starts exactly when A ends, so it reuses A's row; B overlaps A and takes the next.
        $compact = self::scene(['gantt' => ['displayMode' => 'compact']], $body);
        $this->assertSame(self::named($compact, name: 'a')->y, self::named($compact, name: 'c')->y);
        $this->assertLessThan(self::named($compact, name: 'b')->y, self::named($compact, name: 'a')->y);
        $this->assertEquals($compact->graph->nodes, NativeFixtureScene::render("---\ndisplayMode: compact\n---\n".$body)->graph->nodes);
        // A label that fits sits on its bar; a longer one just past the bar's end.
        $this->assertSame(self::named($compact, 'c')->x, self::named($compact, 'label:2')->x);
        $long = self::scene(['gantt' => ['displayMode' => 'compact']], \str_replace('C :c', replace: 'Cleanup the remaining work items :c', subject: $body)."Later :d, 2024-02-20, 1d\n");
        $bar = self::named($long, 'c');
        $this->assertGreaterThan($bar->x + ($bar->w / 2), self::named($long, 'label:2')->x - (self::named($long, 'label:2')->w / 2));
        $this->assertSame(23.0, self::named($plain, 'label:2')->x);
        $ticks = static fn (MermaidScene $scene): array => \array_values(\array_map(static fn (GraphNode $node): string => $node->label, \array_filter(
            $scene->graph->nodes,
            static fn (GraphNode $node): bool => \str_starts_with($node->name, 'tick:'),
        )));
        $this->assertSame('01', $ticks(self::scene(['gantt' => ['axisFormat' => '%d']], $body))[0]);
        $this->assertSame('01', $ticks(self::scene(['gantt' => ['axisFormat' => '%Y']], "gantt\naxisFormat %d\n".\substr($body, offset: 6)))[0], 'the source directive wins');
        $this->assertCount(\count($ticks(NativeFixtureScene::render("gantt\ntickInterval 1day\n".\substr($body, offset: 6)))), $ticks(self::scene(['gantt' => ['tickInterval' => '1day']], $body)));
        $this->assertNotContains('top-tick:0', \array_column($plain->graph->nodes, 'name'));
        $this->assertContains('top-tick:0', \array_column(self::scene(['gantt' => ['topAxis' => true]], $body)->graph->nodes, 'name'));
        $sections = "gantt\nsection A\nX :2024-01-01, 1d\nsection B\nY :2024-01-01, 1d\nsection C\nZ :2024-01-01, 1d\n";
        $two = self::scene(['gantt' => ['numberSectionStyles' => 2]], $sections);
        $this->assertSame($two->graph->clusters[0]->fillColor, $two->graph->clusters[2]->fillColor);
        $this->assertNotSame($two->graph->clusters[0]->fillColor, self::scene([], $sections)->graph->clusters[2]->fillColor);
        $five = "gantt\n".\implode('', \array_map(static fn (int $n): string => "section S{$n}\nT{$n} :2024-01-01, 1d\n", \range(1, end: 5)));
        $this->assertSame('none', self::scene(['gantt' => ['numberSectionStyles' => 6]], $five)->graph->clusters[4]->fillColor);
    }

    public function testClassEmptyBoxesAndNamespaceHierarchy(): void
    {
        $empty = "classDiagram\nclass Lonely\n";
        $this->assertSame('er-card:1', self::scene([], $empty)->shapes[0]);
        $this->assertSame('rect', self::scene(['class' => ['hideEmptyMembersBox' => true]], $empty)->shapes[0]);
        $nested = "classDiagram\nnamespace Company.Billing {\nclass Invoice\n}\nnamespace Company {\nclass Customer\n}\n";
        $labels = static fn (MermaidScene $scene): array => \array_map(static fn (GraphCluster $cluster): string => $cluster->label, $scene->graph->clusters);
        $hierarchical = self::scene([], $nested);
        $this->assertEqualsCanonicalizing(['Company', 'Billing'], $labels($hierarchical));
        // Flat: only declared namespaces, under their full names; Billing no longer sits inside Company.
        $flat = self::scene(['class' => ['hierarchicalNamespaces' => false]], $nested);
        $this->assertEqualsCanonicalizing(['Company.Billing', 'Company'], $labels($flat));
        $frames = \array_column(\array_map(static fn (GraphCluster $cluster): array => [$cluster->label, $cluster], $flat->graph->clusters), 1, 0);
        $inner = $frames['Company.Billing'];
        $outer = $frames['Company'];
        $this->assertTrue($inner->x0 >= $outer->x1 || $inner->x1 <= $outer->x0 || $inner->y0 >= $outer->y1 || $inner->y1 <= $outer->y0, 'declared namespaces do not nest');
        $only = self::scene(['class' => ['hierarchicalNamespaces' => false]], "classDiagram\nnamespace A.B {\nclass C\n}\n");
        $this->assertSame(['A.B'], $labels($only));
        // Declared at two depths, a class joins only the nearer one.
        $both = self::scene(['class' => ['hierarchicalNamespaces' => false]], "classDiagram\nnamespace A {\nclass X\n}\nnamespace A.B {\nclass Y\n}\n");
        $frames = \array_column(\array_map(static fn (GraphCluster $cluster): array => [$cluster->label, $cluster], $both->graph->clusters), 1, 0);
        $y = self::named($both, 'Y');
        $this->assertFalse($y->x > $frames['A']->x0 && $y->x < $frames['A']->x1 && $y->y > $frames['A']->y0 && $y->y < $frames['A']->y1, 'Y sits outside A');
    }

    public function testTimelineKanbanAndCynefinSwitches(): void
    {
        $timeline = "timeline\n2020 : a\n2021 : b\n";
        $plain = self::scene([], $timeline);
        $this->assertNotSame(self::named($plain, name: 'period-0')->fillColor, self::named($plain, name: 'period-1')->fillColor);
        $single = self::scene(['timeline' => ['disableMulticolor' => true]], $timeline);
        $this->assertSame(self::named($single, name: 'period-0')->fillColor, self::named($single, name: 'period-1')->fillColor);
        $this->assertSame(self::named($plain, name: 'period-0')->fillColor, self::named($single, name: 'period-1')->fillColor);
        $kanban = "kanban\nTodo\n  t1[Fix it]@{ ticket: 42 }\n  t2[Plain]\n";
        $legend = static fn (MermaidScene $scene): array => \array_column($scene->legend, 'text');
        $this->assertStringNotContainsString('https://tracker/42', \implode(' ', $legend(self::scene([], $kanban))));
        $linked = $legend(self::scene(['kanban' => ['ticketBaseUrl' => 'https://tracker/#TICKET#']], $kanban));
        $this->assertStringContainsString('→ https://tracker/42', \implode(' ', $linked));
        $this->assertContains('Plain', $linked);
        $cynefin = "cynefin-beta\nclear\n  \"Known\"\n";
        $captions = static fn (MermaidScene $scene): int => \count(\array_filter($scene->graph->nodes, static fn (GraphNode $node): bool => \str_starts_with($node->name, 'caption-')));
        $this->assertSame(5, $captions(self::scene([], $cynefin)));
        $this->assertSame(0, $captions(self::scene(['cynefin' => ['showDomainDescriptions' => false]], $cynefin)));
    }

    public function testTreemapValuesFollowTheFormat(): void
    {
        $body = "treemap-beta\n\"Root\"\n  \"Big\": 1234.5\n  \"Zero\": 0\n";
        $labels = static fn (MermaidScene $scene): array => \array_column($scene->legend, 'text');
        $this->assertSame(['Root (1,234.5)', 'Big (1,234.5)', 'Zero'], $labels(self::scene([], $body)));
        $this->assertSame(['Root', 'Big', 'Zero'], $labels(self::scene(['treemap' => ['showValues' => false]], $body)));
        $this->assertSame('Big ($1,234.5)', $labels(self::scene(['treemap' => ['valueFormat' => '$0,0']], $body))[1]);
        // Pinned keeps only `,` and the precision of a `$…,…` format, so `$,.2f` becomes `,.2`.
        $this->assertSame('Big ($1.2e+3)', $labels(self::scene(['treemap' => ['valueFormat' => '$,.2f']], $body))[1]);
        $this->assertSame('Big ($1.2k)', $labels(self::scene(['treemap' => ['valueFormat' => '$.2s']], $body))[1]);
        $this->assertSame('Big (1.23e+3)', $labels(self::scene(['treemap' => ['valueFormat' => '.2e']], $body))[1]);
        $this->assertSame('Big (1,234.5)', $labels(self::scene(['treemap' => ['valueFormat' => 'nonsense']], $body))[1]);
        $this->assertStringContainsString("Big\n1,234.5", (string) self::named(self::scene([], $body), 'treemap-1')->drawLabel);
    }

    public function testRadarAxisFactorsAndCurveTension(): void
    {
        $body = "radar-beta\naxis A, B, C, D\ncurve S{1, 2, 3, 2}\nmax 4\n";
        $plain = self::scene([], $body);
        $axis = static fn (MermaidScene $scene): float => \max(\array_map(
            static fn (GraphEdge $edge): float => $edge->maxX,
            \array_filter($scene->graph->edges, static fn (GraphEdge $edge): bool => 2 === \count($edge->points)),
        ));
        $label = static fn (MermaidScene $scene): float => self::named($scene, 'B')->x;
        $this->assertSame(164.0, $axis($plain));
        $this->assertSame(194.0, $axis(self::scene(['radar' => ['axisScaleFactor' => 1.5]], $body)));
        $this->assertGreaterThan($label($plain), $label(self::scene(['radar' => ['axisLabelFactor' => 1.5]], $body)));
        $curve = static fn (MermaidScene $scene): array => \array_last($scene->graph->edges)->points;
        $this->assertNotEquals($curve($plain), $curve(self::scene(['radar' => ['curveTension' => 0.4]], $body)));
        $this->assertEquals($curve($plain), $curve(self::scene(['radar' => ['curveTension' => 0.17]], $body)));
    }

    public function testC4RoleColorsComeFromTheConfiguration(): void
    {
        $body = "C4Context\nSystem(s, \"S\")\nSystemDb_Ext(d, \"D\")\nPerson(p, \"P\", \$bgColor=\"#123456\")\n";
        $plain = self::scene([], $body);
        $this->assertSame(['#1168bd', '#3c7fc0'], [self::named($plain, 's')->fillColor, self::named($plain, 's')->color]);
        $this->assertSame(['#999999', '#8a8a8a'], [self::named($plain, 'd')->fillColor, self::named($plain, 'd')->color]);
        $configured = self::scene(['c4' => ['system_bg_color' => 'red', 'external_system_db_border_color' => '#00ff00', 'person_bg_color' => 'blue']], $body);
        $this->assertSame('#ff0000', self::named($configured, 's')->fillColor);
        $this->assertSame('#00ff00', self::named($configured, 'd')->color);
        $this->assertSame('#123456', self::named($configured, 'p')->fillColor, 'an authored $bgColor wins');
        $queue = self::scene(['c4' => ['container_queue_bg_color' => '#abcdef']], "C4Container\nContainerQueue(q, \"Q\")\n");
        $this->assertSame('#abcdef', self::named($queue, 'q')->fillColor);
    }

    public function testRailroadColorsOverrideTheThemeDefaults(): void
    {
        $body = "railroad-ebnf-beta\nletter = \"a\" | other ;\n";
        $plain = self::scene([], $body);
        $configured = self::scene(['railroad' => [
            'terminalFill' => '#010203',
            'nonTerminalStroke' => 'red',
            'lineColor' => '#00ff00',
            'markerFill' => '#0000ff',
            'ruleNameColor' => '#111111',
        ]], $body);
        $this->assertNotSame('#010203', self::named($plain, 'a')->fillColor);
        $this->assertSame('#010203', self::named($configured, 'a')->fillColor);
        $this->assertSame('#ff0000', self::named($configured, 'other')->color);
        $this->assertSame('#00ff00', $configured->graph->edges[0]->color);
        $this->assertSame('#0000ff', self::named($configured, '@marker')->fillColor);
        $this->assertSame('#111111', self::named($configured, 'letter')->textColor);
        $this->assertSame(self::named($plain, 'a')->fillColor, self::named(self::scene(['railroad' => ['terminalFill' => 'not a color']], $body), 'a')->fillColor);
    }

    public function testUsecaseRotateGivesActorsThenUseCasesPaletteSlots(): void
    {
        $body = "usecase-beta\nU1(\"Browse\")\nactor A\nactor B\nA --> U1\n";
        $rotated = self::scene(['usecase' => ['colorScheme' => 'rotate']], $body);
        $palette = $rotated->palette;
        $this->assertInstanceOf(MermaidPalette::class, $palette);
        $this->assertSame($palette->resolve('@slot:stroke:0'), self::named($rotated, 'A')->color);
        $this->assertSame($palette->resolve('@slot:stroke:1'), self::named($rotated, 'B')->color);
        $this->assertSame($palette->resolve('@slot:stroke:2'), self::named($rotated, 'U1')->color);
        $this->assertSame($palette->resolve('@slot:fill:2'), self::named($rotated, 'U1')->fillColor);
        $role = self::scene([], $body);
        $this->assertSame(self::named($role, 'A')->color, self::named($role, 'B')->color);
        $classic = self::scene(['theme' => 'default', 'usecase' => ['colorScheme' => 'rotate']], $body);
        $this->assertEquals(self::named(self::scene(['theme' => 'default'], $body), 'A'), self::named($classic, 'A'), 'rotation needs a color theme');
    }

    /** @param array<string, mixed> $config */
    private static function scene(array $config, string $body): MermaidScene
    {
        $front = [] === $config ? '' : "---\nconfig: ".\json_encode($config, \JSON_THROW_ON_ERROR)."\n---\n";

        return NativeFixtureScene::render($front.$body);
    }

    private static function sankeyX(MermaidScene $scene, string $label): float
    {
        return (float) \array_find($scene->graph->nodes, static fn (GraphNode $node): bool => $label === $node->label && \str_starts_with($node->name, 'sankey-'))?->x;
    }

    private static function named(MermaidScene $scene, string $name): GraphNode
    {
        $node = \array_find($scene->graph->nodes, static fn (GraphNode $node): bool => $name === $node->name);
        self::assertInstanceOf(GraphNode::class, $node, 'No node '.$name);

        return $node;
    }
}
