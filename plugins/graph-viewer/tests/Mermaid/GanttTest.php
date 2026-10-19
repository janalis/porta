<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\GanttAxis;
use Porta\GraphViewer\Mermaid\GanttDate;
use Porta\GraphViewer\Mermaid\GanttDiagram;
use Porta\GraphViewer\Mermaid\GanttLayout;
use Porta\GraphViewer\Mermaid\GanttLexer;
use Porta\GraphViewer\Mermaid\GanttParser;
use Porta\GraphViewer\Mermaid\GanttSchedule;
use Porta\GraphViewer\Mermaid\MermaidScene;
use Porta\GraphViewer\Mermaid\MermaidStatement;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;

final class GanttTest extends TestCase
{
    private static function calendar(): GanttDate
    {
        return new GanttDate(new \DateTimeImmutable('2026-10-18T12:00:00Z'));
    }

    private static function parse(string $source): GanttDiagram
    {
        $parser = new GanttParser(self::calendar());
        foreach (new GanttLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }

        $diagram = $parser->finish();
        \iterator_to_array(new GanttSchedule(self::calendar())->resolve($diagram));

        return $diagram;
    }

    private static function scene(string $source): MermaidScene
    {
        $work = new GanttLayout(self::calendar())->layout(self::parse($source));
        \iterator_to_array($work);

        return $work->getReturn();
    }

    public static function fixtures(): iterable
    {
        foreach (\glob(__DIR__.'/../fixtures/mermaid/gantt/*.mmd') as $path) {
            yield \basename($path) => [$path];
        }
    }

    #[DataProvider('fixtures')]
    public function testPinnedBodiesParse(string $path): void
    {
        $source = \preg_replace('/\A---\R.*?\R---\R/s', replacement: '', subject: \file_get_contents($path));
        $source = \preg_replace('/%%\{.*?\}%%/s', replacement: '', subject: $source);
        $this->assertNotEmpty(self::parse($source)->tasks);
        $this->assertNotEmpty(NativeFixtureRows::render($source, width: 100)->rows);
    }

    public static function referenceCases(): iterable
    {
        $cases = \json_decode(\file_get_contents(__DIR__.'/../fixtures/mermaid/gantt-reference.json'), associative: true, flags: \JSON_THROW_ON_ERROR);
        foreach ($cases as $case) {
            yield $case['name'] => [$case];
        }
    }

    #[DataProvider('referenceCases')]
    public function testPinnedDatabaseDateArithmetic(array $case): void
    {
        $source = "gantt\ndateFormat ".$case['format']."\n";
        if ('' !== $case['excludes']) {
            $source .= 'excludes '.$case['excludes']."\n";
        }
        if ($case['inclusive']) {
            $source .= "inclusiveEndDates\n";
        }
        foreach ($case['data'] as $data) {
            $source .= 'Task '.$data."\n";
        }
        $actual = [];
        foreach (self::parse($source)->tasks as $task) {
            $actual[] = [
                'id' => $task['id'],
                'start' => self::calendar()->at($task['start'])->format('Y-m-d\TH:i:s.v\Z'),
                'end' => self::calendar()->at($task['end'])->format('Y-m-d\TH:i:s.v\Z'),
                'draw' => null === $task['draw'] ? null : self::calendar()->at($task['draw'])->format('Y-m-d\TH:i:s.v\Z'),
            ];
        }
        $this->assertSame($case['tasks'], $actual);
    }

    public function testBarsHaveProportionalWidthsAndMilestonesStayAtTheirTime(): void
    {
        $scene = self::scene("gantt\nsection Work\nOne:a, 2024-01-01, 1d\nTwo:b, after a, 2d\nMilestone:milestone, m, after b, 0d");
        $selected = [];
        foreach ($scene->selectionGraph->nodes as $node) {
            $selected[$node->name] = $node;
        }
        $this->assertSame(2 * $selected['a']->w, $selected['b']->w);
        $this->assertSame($selected['a']->x + ($selected['a']->w / 2), $selected['b']->x - ($selected['b']->w / 2));
        $this->assertSame($selected['b']->x + ($selected['b']->w / 2), $selected['m']->x);
        $this->assertSame('diamond', $scene->shapes[$selected['m']->id]);
        $this->assertSame(2, $scene->origin($selected['a']->id));
        $this->assertSame(0, $scene->graph->edgeCount());
        $this->assertSame('Work', $selected['section:Work']->label);
    }

    public function testVerticalMarkersDoNotConsumeRowsAndNormalBarOriginsWin(): void
    {
        $base = "gantt\ndateFormat HH:mm\nA:a, 10:00, 1h\nB:b, 11:00, 1h";
        $scene = self::scene($base."\nMark:vert, marker, 10:30, 0d");
        $original = self::scene($base);
        foreach (['a', 'b'] as $name) {
            $node = \array_find($scene->selectionGraph->nodes, static fn ($node): bool => $name === $node->name);
            $before = \array_find($original->selectionGraph->nodes, static fn ($node): bool => $name === $node->name);
            $this->assertSame($before->y, $node->y);
            for ($row = (int) \floor(($node->y - ($node->h / 2)) / 2); $row <= (int) \ceil(($node->y + ($node->h / 2)) / 2); ++$row) {
                $this->assertSame($scene->origin($node->id), $scene->origins[$row]);
            }
        }
        $marker = \array_find($scene->selectionGraph->nodes, static fn ($node): bool => 'marker' === $node->name);
        $this->assertSame(4, $scene->origin($marker->id));
        $this->assertGreaterThan(10, $marker->h);
    }

    public function testExclusionsIncludesStatusesTopAxisAndMetadata(): void
    {
        $source = <<<'MMD'
            gantt
            topAxis
            excludes weekends
            excludes monday
            includes 2026-10-19
            weekend friday
            weekday monday
            axisFormat %d
            accTitle: Schedule
            accDescr {
            Literal { is not a callback
            }
            section Work
            Done:done, a, 2026-10-16, 2d
            Active:active, b, after a, 1d
            Critical:crit, c, after b, 1d
            click a href "https://example.com"
            MMD;
        $diagram = self::parse($source);
        $this->assertSame(['weekends', 'monday'], $diagram->excludes);
        $this->assertSame(['2026-10-19'], $diagram->includes);
        $this->assertSame('2026-10-19', self::calendar()->label($diagram->tasks[0]['end'], 'YYYY-MM-DD'));
        $scene = self::scene($source);
        // Theme roles: done, active and critical bars, then the excluded days.
        $fills = \array_column($scene->graph->nodes, 'fillColor');
        $this->assertContains('@doneTaskBkgColor', $fills);
        $this->assertContains('@activeTaskBkgColor', $fills);
        $this->assertContains('@critBkgColor', $fills);
        $this->assertContains('@excludeBkgColor', $fills);
        $this->assertNotEmpty(\array_filter($scene->graph->nodes, static fn (GraphNode $node): bool => \str_starts_with($node->name, 'top-tick:')));
        $this->assertStringContainsString('https://example.com', \implode(' ', \array_column($scene->legend, 'text')));
        $this->assertCount(4, $scene->elements);
    }

    public function testEmptyZeroSpanAndTodayMarker(): void
    {
        $this->assertNotEmpty(self::scene('gantt')->graph->nodes);
        $this->assertNotEmpty(self::scene("gantt\nA:milestone, a, 2024-01-01, 0d")->elements);
        $on = self::scene("gantt\nA:2026-10-18, 1d");
        $off = self::scene("gantt\ntodayMarker off\nA:2026-10-18, 1d");
        $this->assertCount(\count($off->graph->edges) + 1, $on->graph->edges);
        // The today line is the marker's: the last full-height vertical edge.
        $today = static fn (MermaidScene $scene): GraphEdge => \array_last(\array_filter(
            $scene->graph->edges,
            static fn (GraphEdge $edge): bool => $edge->minX === $edge->maxX && 2.0 === $edge->minY,
        ));
        $this->assertSame(['@todayLineColor', 0.2, false], [$today($on)->color, $today($on)->strokeWidth, $today($on)->dashed], 'pinned `.today`: 2px in the today color');
        $styled = $today(self::scene("gantt\ntodayMarker stroke-width:5px,stroke:#0f0,stroke-dasharray:4 2,opacity:0.5\nA:2026-10-18, 1d"));
        $this->assertSame(['#00ff00', 0.5, true, [0.4, 0.2]], [$styled->color, $styled->strokeWidth, $styled->dashed, $styled->dashes], 'its CSS styles the line, commas for semicolons');
        $named = $today(self::scene("gantt\ntodayMarker stroke:red;stroke-dasharray:0;stroke-width:wide\nA:2026-10-18, 1d"));
        $this->assertSame(['#ff0000', 0.2, false], [$named->color, $named->strokeWidth, $named->dashed], 'a dash of zero is solid, and a bad width keeps the default');
        $this->assertSame(0.3, $today(self::scene("gantt\ntodayMarker stroke:red;stroke-width:3px\nA:2026-10-18, 1d"))->strokeWidth, 'semicolons separate declarations too');
        $dropped = $today(self::scene("gantt\ntodayMarker stroke:notacolor\nA:2026-10-18, 1d"));
        $this->assertSame('@todayLineColor', $dropped->color, 'a color a browser would drop leaves the default');
        $this->assertFalse($today(self::scene("gantt\ntodayMarker stroke-dasharray:oops\nA:2026-10-18, 1d"))->dashed, 'so does a dash a browser would drop');
    }

    public function testUnknownReferencesFallBackToTheInjectedToday(): void
    {
        $diagram = self::parse("gantt\nA:a, after missing, 1d\nB:b, after a, until missing");
        $this->assertSame(self::calendar()->midnight(), $diagram->tasks[0]['start']);
        $this->assertSame(self::calendar()->midnight(), $diagram->tasks[1]['end']);
    }

    public function testCalendarAndAxisFormats(): void
    {
        $calendar = self::calendar();
        foreach ([
            ['YY-MMM-DD',             '24-Jan-03'],
            ['YYYY-MMMM-DD',          '2024-January-03'],
            ['YYYY-M-D',              '2024-1-3'],
            ['YYYY-MM-DD H:mm:ss',    '2024-01-03 13:02:03'],
            ['YYYY-MM-DD h:mm:ss a',  '2024-01-03 1:02:03 pm'],
            ['YYYY-MM-DD hh:mm:ss A', '2024-01-03 01:02:03 PM'],
            ['YYYY-MM-DDTHH:mm:ssZ',  '2024-01-03T13:02:03+00:00'],
            ['YYYY-MM-DDTHH:mm:ssZZ', '2024-01-03T13:02:03+0000'],
        ] as [$format, $text]) {
            $this->assertNotNull($calendar->parse($text, $format));
        }
        $this->assertSame('0', $calendar->label(0.0, 'x'));
        $this->assertSame('0', $calendar->label(0.0, 'X'));
        $this->assertNull($calendar->parse('bad', 'X'));
        $time = GanttDate::seconds(new \DateTimeImmutable('2026-10-18T12:34:56.789Z'));
        $axis = new GanttAxis($calendar);
        $this->assertSame('Sun Sunday Oct October 18 18 12 12 291 789 10 34 PM 4 56 7 42 42 0 41 10/18/2026 12:34:56 26 2026 +0000 %', $axis->label(
            $time,
            '%a %A %b %B %d %e %H %I %j %L %m %M %p %q %S %u %U %V %w %W %x %X %y %Y %Z %%',
        ));
        $this->assertSame('789000 26 2026 Sun Oct 18 12:34:56 2026', $axis->label($time, '%f %g %G %c'));
        $this->assertSame((string) (int) ($time * 1000).' '.(int) $time, $axis->label($time, '%Q %s'));
    }

    public function testHugeOrInvalidTickIntervalsFallBackToBoundedAutomaticTicks(): void
    {
        $source = new GanttDiagram();
        $source->tick = '1millisecond';
        $axis = new GanttAxis(self::calendar());
        $ticks = $axis->ticks(0.0, 1_000_000.0, $source);
        $this->assertLessThanOrEqual(512, \count($ticks));
        $this->assertGreaterThan(750_000.0, \array_last($ticks));
        $this->assertCount(512, $axis->ticks(0.0, 0.512, $source));
        $source->tick = '999999999999999999999999999month';
        $this->assertNotEmpty($axis->ticks(0.0, 10.0, $source));
        $source->tick = 'not an interval';
        $this->assertNotEmpty($axis->ticks(0.0, 100_000_000.0, $source));
    }

    public static function malformed(): iterable
    {
        yield ['', 'header'];
        yield ['other', 'gantt header'];
        yield ["gantt\nnot a task", 'task label'];
        yield ["gantt\nA:a, b, c, d", 'one to three'];
        yield ["gantt\nA:active", 'one to three'];
        yield ["gantt\nA:a, after b, 1d\nB:b, after a, 1d", 'cyclic'];
        yield ["gantt\nA:invalid date, 1d", 'start date'];
        yield ["gantt\nA:13, 1d", 'start date'];
        yield ["gantt\nA:2024-99-99, 1d", 'start date'];
        yield ["gantt\nA:2024-01-01, 9999999999999999999999999999h", 'calendar limit'];
        yield ["gantt\nA:2024-01-01, 99999999M", 'calendar limit'];
        yield ["gantt\nA:2024-01-01, 99999999d", 'calendar limit'];
        yield ["gantt\ndateFormat x\nA:99999999999999999999999999999, 1d", 'calendar limit'];
        yield ["gantt\ndateFormat X\nA:0, 99999999999999999999999999999", 'calendar limit'];
        yield ["gantt\nexcludes monday tuesday wednesday thursday friday saturday sunday\nA:2024-01-01, 1d", 'iteration limit'];
        yield ["gantt\n".\str_repeat('A', times: 65_537).':2024-01-01, 1d', 'size limit'];
        yield ["gantt\n".\str_repeat("A:2024-01-01, 1d\n", times: 4097), 'task limit'];
    }

    #[DataProvider('malformed')]
    public function testMalformedInputsHaveLocatedErrors(string $source, string $message): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains($message);
        self::parse($source);
    }

    public function testExclusionsLeaveAVisibleGapBeforeTheDependentTask(): void
    {
        $scene = self::scene("gantt\nexcludes weekends\nA:a, 2026-10-19, 5d\nB:b, after a, 1d");
        $a = \array_find($scene->selectionGraph->nodes, static fn ($node): bool => 'a' === $node->name);
        $b = \array_find($scene->selectionGraph->nodes, static fn ($node): bool => 'b' === $node->name);
        $this->assertEqualsWithDelta(30.0, $b->x - ($b->w / 2) - $a->x - ($a->w / 2), delta: 0.0001);
        $long = self::scene("gantt\nexcludes weekends\nA:2010-01-01, 2020-01-01");
        $this->assertEmpty(\array_filter($long->graph->nodes, static fn (GraphNode $node): bool => \str_starts_with($node->name, 'excluded:')));
    }

    public static function calendarLimits(): iterable
    {
        yield 'start' => ['start', '999999999999999999999999999999', 0.0];
        yield 'clock duration' => ['duration', '9999999999999999999999999999h', 0.0];
        yield 'calendar months' => ['duration', '120001M', -377_736_000_000.0];
        yield 'calendar days' => ['duration', '4000001d', -377_736_000_000.0];
    }

    #[DataProvider('calendarLimits')]
    public function testCalendarMethodsEnforceTheirOwnBounds(string $method, string $value, float $start): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('calendar limit');
        $statement = new MermaidStatement('', 0, 0, 0);
        if ('start' === $method) {
            self::calendar()->start($value, 'x', $statement);

            return;
        }
        self::calendar()->duration($start, $value, $statement);
    }

    public function testSchedulingYieldsInsideLongExclusionRangesAndCanBeAbandoned(): void
    {
        $parser = new GanttParser(self::calendar());
        foreach (new GanttLexer("gantt\nexcludes weekends\nA:2024-01-01, 1000d")->statements() as $statement) {
            $parser->accept($statement);
        }
        $diagram = $parser->finish();
        $this->assertSame([], $diagram->tasks);
        $work = new GanttSchedule(self::calendar())->resolve($diagram);
        $work->rewind();
        $work->next();
        $this->assertTrue($work->valid(), 'calendar days yield after the task-resolution step');
        $this->assertSame([], $diagram->tasks, 'partial schedules are not published');
        unset($work);
        $this->assertSame([], $diagram->tasks);
        \iterator_to_array(new GanttSchedule(self::calendar())->resolve($diagram));
        $this->assertCount(1, $diagram->tasks);
    }

    public function testCalendarIterationBudgetAlsoBoundsLongValidRanges(): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('iteration limit');
        self::parse("gantt\nexcludes never\nA:2024-01-01, 100001d");
    }

    public function testTaskAndSectionTextTakeTheirPinnedSizes(): void
    {
        $body = "gantt\ndateFormat YYYY-MM-DD\nsection Work\nTask :a, 2024-01-01, 2d\n";
        $scale = static fn (MermaidScene $scene, string $prefix): float => \array_find($scene->graph->nodes, static fn (GraphNode $node): bool => \str_starts_with(
            $node->name,
            $prefix,
        ))->fontScale ?? -1.0;
        $plain = NativeFixtureScene::render($body);
        $this->assertSame([1.0, 1.0], [$scale($plain, prefix: 'label:'), $scale($plain, prefix: 'section:')]);
        $sized = NativeFixtureScene::render("---\nconfig:\n  gantt:\n    fontSize: 22\n    sectionFontSize: '33px'\n---\n".$body);
        $this->assertSame([2.0, 3.0], [$scale($sized, prefix: 'label:'), $scale($sized, prefix: 'section:')]);
        $label = \array_find($sized->graph->nodes, static fn (GraphNode $node): bool => \str_starts_with($node->name, 'label:'));
        $this->assertSame(8.0, $label?->h, 'the scaled label sizes its box');
        $this->assertSame(1.0, $scale(NativeFixtureScene::render("---\nconfig:\n  gantt:\n    fontSize: 0\n---\n".$body), prefix: 'label:'));
    }
}
