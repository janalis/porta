<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphCluster;
use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphFont;
use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;

/** Proportional task bars share a time axis; vertical markers never allocate a task row. */
final class GanttLayout
{
    /** @var array<int, GraphNode> */
    private array $nodes = [];
    /** @var array<int, string> */
    private array $shapes = [];
    /** @var array<int, int> */
    private array $elements = [];
    /** @var array<int, true> */
    private array $verticalIds = [];

    private readonly MermaidOptions $options;

    public function __construct(
        private readonly GanttDate $calendar = new GanttDate(),
        ?MermaidOptions $options = null,
    ) {
        $this->options = $options ?? MermaidOptions::defaults();
    }

    /** @return \Generator<int, null, mixed, MermaidScene> */
    public function layout(GanttDiagram $source): \Generator
    {
        // Pinned source directives win; the `gantt` options fill what the source leaves unset.
        $source = clone $source;
        $source->axis = '' === $source->axis && 'D' !== $source->format ? $this->options->text('gantt', 'axisFormat') : $source->axis;
        $source->tick = '' === $source->tick ? $this->options->text('gantt', 'tickInterval') : $source->tick;
        $source->topAxis = $source->topAxis || $this->options->flag('gantt', 'topAxis');
        $this->nodes = [];
        $this->shapes = [];
        $this->elements = [];
        $this->verticalIds = [];
        yield from new GanttSchedule($this->calendar)->resolve($source);
        $minimum = [] === $source->tasks ? $this->calendar->midnight() : \min(\array_column($source->tasks, 'start'));
        $maximum = [] === $source->tasks ? $minimum + 86_400 : \max(\array_column($source->tasks, 'end'));
        if ($maximum <= $minimum) {
            $minimum -= 0.5;
            $maximum = $minimum + 1;
        }
        $left = 46.0;
        $extent = 120.0;
        $project = static fn (float $time): float => $left + ((($time - $minimum) / ($maximum - $minimum)) * $extent);
        $top = $source->topAxis ? 8.0 : 4.0;
        $bars = $this->bars($source, $top, $project);
        yield from $bars;
        [$top, $clusters, $legend, $verticals] = $bars->getReturn();
        $bottom = $top + 2;
        foreach ($verticals as $index => $task) {
            $x = $project($task['start']);
            $id = \count($this->nodes);
            $this->nodes[$id] = new GraphNode(
                $id,
                $task['id'],
                $task['label'],
                $x,
                ($bottom + 2) / 2,
                w: 0.5,
                h: \max(4.0, $bottom - 2),
                color: '@vertLineColor',
                fillColor: '@vertLineColor',
                dashed: false,
                drawLabel: '',
            );
            $this->elements[$id] = $task['origin'];
            $this->verticalIds[$id] = true;
            $this->text('vertical:'.$index, $task['label'], $x, $bottom + 6 + (4 * $index));
            $legend[] = ['text' => $task['label'].' · '.$this->calendar->label($task['start'], $source->format), 'origin' => $task['origin']];
            yield null;
        }
        yield from $this->shade($source, $minimum, $maximum, $bottom, $project);
        $axis = $this->axes($source, $minimum, $maximum, $bottom, $project);
        yield from $axis;
        $edges = $axis->getReturn();
        foreach ($source->metadata as $kind => $metadata) {
            $legend[] = ['text' => $kind.': '.$metadata['label'], 'origin' => $metadata['origin']];
        }
        $width = $left + $extent + 10;
        $height = $bottom + 8;
        foreach ($this->nodes as $node) {
            $width = \max($width, $node->x + ($node->w / 2) + 2);
            $height = \max($height, $node->y + ($node->h / 2) + 2);
        }
        $mapping = $this->origins($height, $source->origin);
        yield from $mapping;
        $origins = $mapping->getReturn();
        $scene = new MermaidScene(new GraphLayout($width, $height, $this->nodes, $edges, $clusters), $origins, $legend, $this->elements, $this->shapes);
        if (\array_key_exists('title', $source->metadata)) {
            $metadata = $source->metadata['title'];
            $title = MermaidTitle::apply($scene, new MermaidStatement($metadata['label'], 0, $metadata['origin'], 0));
            yield from $title;
            $scene = $title->getReturn();
        }

        return $scene;
    }

    /** @return \Generator<int, null, mixed, list<int>> */
    private function origins(float $height, int $headerOrigin): \Generator
    {
        $origins = MermaidOrigins::rows($height, $headerOrigin);
        $mapping = $this->elements;
        \uksort($mapping, fn (int $left, int $right): int => $this->nodes[$right]->h <=> $this->nodes[$left]->h);
        foreach ($mapping as $id => $origin) {
            $node = $this->nodes[$id];
            if (\array_key_exists($id, $this->verticalIds)) {
                $origins[(int) ($node->y / 2)] = $origin;
                yield null;
                continue;
            }
            for ($row = (int) \floor(($node->y - ($node->h / 2)) / 2); $row <= (int) \ceil(($node->y + ($node->h / 2)) / 2); ++$row) {
                $origins[$row] = $origin;
                yield null;
            }
        }

        return $origins;
    }

    /** @param \Closure(float): float $project
     * @return \Generator<int, null, mixed, void>
     */
    private function shade(GanttDiagram $source, float $minimum, float $maximum, float $bottom, \Closure $project): \Generator
    {
        $date = $this->calendar->at($minimum)->setTime(0, 0);
        if ([] === $source->excludes || $maximum >= GanttDate::seconds($date->modify('+6 years'))) {
            return;
        }
        $schedule = new GanttSchedule($this->calendar);
        while (GanttDate::seconds($date) < $maximum) {
            $next = $date->modify('+1 day');
            if ($schedule->excluded(GanttDate::seconds($date), $source)) {
                $left = $project(\max($minimum, GanttDate::seconds($date)));
                $right = $project(\min($maximum, GanttDate::seconds($next)));
                $id = \count($this->nodes);
                $this->nodes[$id] = new GraphNode(
                    $id,
                    'excluded:'.$date->format('Y-m-d'),
                    '',
                    ($left + $right) / 2,
                    ($bottom + 2) / 2,
                    $right - $left,
                    $bottom - 2,
                    color: 'none',
                    fillColor: '@excludeBkgColor',
                    dashed: false,
                    background: true,
                );
            }
            $date = $next;
            yield null;
        }
    }

    /** @param \Closure(float): float $project
     * @return \Generator<int, null, mixed, list<GraphEdge>>
     */
    private function axes(GanttDiagram $source, float $minimum, float $maximum, float $bottom, \Closure $project): \Generator
    {
        $left = 46.0;
        $extent = 120.0;
        $axis = new GanttAxis($this->calendar);
        // Pinned gantt styles hide the axis domain (`.grid path { stroke-width: 0; }`).
        $edges = [$this->line($left, $bottom, $left + $extent, $bottom, 'none')];
        foreach ($axis->ticks($minimum, $maximum, $source) as $index => $tick) {
            $x = $project($tick);
            // d3 draws grid ticks in `currentColor`, inside a group at 80% opacity.
            $edges[] = $this->line($x, 2.0, $x, $bottom, '#000000cc');
            $format = '' === $source->axis ? '%Y-%m-%d' : $source->axis;
            if ('' === $source->axis && 'D' === $source->format) {
                $format = '%d';
            }
            $this->text('tick:'.$index, $axis->label($tick, $format), $x, $bottom + 3);
            if ($source->topAxis) {
                $this->text('top-tick:'.$index, $axis->label($tick, $format), $x, y: 3.0);
            }
            yield null;
        }
        $now = GanttDate::seconds($this->calendar->today);
        if ('off' !== $source->todayMarker && $now >= $minimum && $now <= $maximum) {
            $x = $project($now);
            $edges[] = self::today($this->line($x, 2.0, $x, $bottom, '@todayLineColor')->stroked(0.2), $source->todayMarker);
        }

        return $edges;
    }

    /** @param \Closure(float): float $project
     * @return \Generator<int, null, mixed, array{float, list<GraphCluster>, list<array{text: string, origin: int}>, list<array{id: string, label: string, section: string, tags: list<string>, start: float, end: float, draw: ?float, origin: int} >}>
     */
    private function bars(GanttDiagram $source, float $top, \Closure $project): \Generator
    {
        $left = 46.0;
        $extent = 120.0;
        $clusters = [];
        $legend = [];
        $verticals = [];
        $sections = [];
        foreach ($source->tasks as $index => $task) {
            if (\in_array('vert', $task['tags'], strict: true)) {
                $verticals[] = $task;
                continue;
            }
            $last = \array_key_last($sections);
            if (null === $last || $sections[$last][0] !== $task['section']) {
                $sections[] = [$task['section'], []];
                $last = \array_key_last($sections);
            }
            $sections[$last][1][$index] = $task;
        }
        foreach ($sections as $sectionIndex => [$section, $tasks]) {
            $sectionTop = $top;
            if ('' !== $section) {
                $header = $this->text('section:'.$section, $section, 23.0, $top + 2, GraphFont::Bold);
                $this->elements[$header->id] = $source->sections[$section]['origin'];
                $top += 6;
            }
            $placed = [];
            foreach ($this->rows($tasks) as $index => $row) {
                $wrap = MermaidWrap::text($tasks[$index]['label'], width: 36);
                yield from $wrap;
                $placed[$row][$index] = $wrap->getReturn();
            }
            \ksort($placed);
            foreach ($placed as $row) {
                $scale = $this->scale('fontSize');
                $rowHeight = \max(6.0, ...\array_map(static fn (string $label): float => MermaidText::box($label, $scale)[1], $row));
                foreach ($row as $index => $label) {
                    $legend[] = $this->bar($source, $index, $tasks[$index], $label, $top + ($rowHeight / 2), $project, \count($row) > 1 || $this->compact());
                    yield null;
                }
                $top += $rowHeight + 2;
            }
            $clusters[] = $this->section($sectionIndex, $sectionTop, $top, $left + $extent + 2);
        }

        return [$top, $clusters, $legend, $verticals];
    }

    private function compact(): bool
    {
        return 'compact' === $this->options->text('gantt', 'displayMode');
    }

    /**
     * Each task's row within its section: one row per task, or in pinned compact mode the first
     * row whose last task ends by this one's start, taking tasks by start time.
     *
     * @param array<int, array{start: float, end: float, ...}> $tasks
     * @return array<int, int> row by task index
     */
    private function rows(array $tasks): array
    {
        if (!$this->compact()) {
            return \array_combine(\array_keys($tasks), \range(0, \count($tasks) - 1));
        }
        $order = \array_keys($tasks);
        \usort($order, static fn (int $a, int $b): int => [$tasks[$a]['start'], $a] <=> [$tasks[$b]['start'], $b]);
        $ends = [];
        $rows = [];
        foreach ($order as $index) {
            $row = (int) (\array_find_key($ends, static fn (float $end): bool => $tasks[$index]['start'] >= $end) ?? \count($ends));
            $ends[$row] = $tasks[$index]['end'];
            $rows[$index] = $row;
        }
        \ksort($rows);

        return $rows;
    }

    /**
     * One task bar and its label: in the label column, or beside its bar when a row is shared.
     *
     * @param array{id: string, label: string, section: string, tags: list<string>, start: float, end: float, draw: ?float, origin: int} $task
     * @param \Closure(float): float $project
     * @return array{text: string, origin: int}
     */
    // @mago-expect lint:no-boolean-flag-parameter -- Whether the row is shared decides where the label sits.
    private function bar(GanttDiagram $source, int $index, array $task, string $label, float $center, \Closure $project, bool $shared): array
    {
        $start = $project($task['start']);
        $end = $project($task['draw'] ?? $task['end']);
        $milestone = \in_array('milestone', $task['tags'], strict: true);
        $barWidth = $milestone ? 4.0 : \max(0.25, \abs($end - $start));
        $x = $milestone ? $project(($task['start'] + $task['end']) / 2) : ($start + $end) / 2;
        // Pinned task text sits on its bar when it fits, else just past the bar's end.
        [$labelWidth] = MermaidText::box($label, $this->scale('fontSize'));
        $labelX = $labelWidth <= $barWidth ? $x : $x + ($barWidth / 2) + 1 + ($labelWidth / 2);
        $this->text('label:'.$index, $label, $shared ? $labelX : 23.0, $center);
        [$fill, $stroke] = self::paint($task['tags']);
        $id = \count($this->nodes);
        $this->nodes[$id] = new GraphNode($id, $task['id'], $task['label'], $x, $center, $barWidth, h: 4.0, color: $stroke, fillColor: $fill, dashed: false, drawLabel: '');
        $this->shapes[$id] = $milestone ? 'diamond' : 'rounded';
        $this->elements[$id] = $task['origin'];

        return [
            'text' => $task['label'].' · '.$this->calendar->label($task['start'], $source->format).' — '.$this->calendar->label($task['end'], $source->format),
            'origin' => $task['origin'],
        ];
    }

    private function text(string $name, string $label, float $x, float $y, GraphFont $font = GraphFont::Regular): GraphNode
    {
        // Pinned `fontSize` sets task text and `sectionFontSize` section titles, both from 11 pixels.
        $scale = match (true) {
            \str_starts_with($name, 'label:') => $this->scale('fontSize'),
            \str_starts_with($name, 'section:') => $this->scale('sectionFontSize'),
            default => 1.0,
        };
        [$width, $height] = MermaidText::box($label, $scale);
        $id = \count($this->nodes);
        // Pinned gantt text roles: task labels outside bars, section titles, axis ticks, markers.
        $color = match (\explode(':', $name, limit: 2)[0]) {
            'label' => '@taskTextOutsideColor',
            'section' => '@titleColor',
            'vertical' => '@vertLineColor',
            default => '@textColor',
        };
        $node = new GraphNode($id, $name, $label, $x, $y, $width, $height, color: null, fillColor: null, dashed: false, textColor: $color, font: $font, fontScale: $scale);
        $this->nodes[$id] = $node;
        $this->shapes[$id] = 'text';

        return $node;
    }

    /**
     * Pinned gantt task classes: crit borders win over done and active fills.
     *
     * @param list<string> $tags
     * @return array{string, string} fill and stroke tokens
     */
    private static function paint(array $tags): array
    {
        $crit = \in_array('crit', $tags, strict: true);
        $fill = match (true) {
            \in_array('active', $tags, strict: true) => '@activeTaskBkgColor',
            \in_array('done', $tags, strict: true) => '@doneTaskBkgColor',
            $crit => '@critBkgColor',
            default => '@taskBkgColor',
        };
        $stroke = match (true) {
            $crit => '@critBorderColor',
            \in_array('active', $tags, strict: true) => '@activeTaskBorderColor',
            \in_array('done', $tags, strict: true) => '@doneTaskBorderColor',
            default => '@taskBorderColor',
        };

        return [$fill, $stroke];
    }

    private function scale(string $key): float
    {
        $size = MermaidFonts::pixels($this->options->value('gantt', $key)) ?? 11.0;

        return $size > 0 ? $size / 11 : 1.0;
    }

    /** Pinned `.section0`…`.section3` cycle four band colors, drawn at 20% opacity. */
    private function section(int $index, float $top, float $bottom, float $right): GraphCluster
    {
        // `section4` and beyond, reachable with more `numberSectionStyles`, have no pinned fill.
        $slot = $index % \max(1, (int) $this->options->number('gantt', 'numberSectionStyles', fallback: 4.0));
        $fill = ['@sectionBkgColor@20', '@altSectionBkgColor@20', '@sectionBkgColor2@20', '@altSectionBkgColor@20'][$slot] ?? 'none';

        return new GraphCluster('section:'.$index, '', 2.0, $top, $right, $bottom, color: 'none', labelPos: null, fillColor: $fill);
    }

    /**
     * Pinned `todayMarker` styles the line with its CSS, commas for semicolons: `stroke`,
     * `stroke-width` in pixels and a non-zero `stroke-dasharray` apply; a declaration a browser
     * would drop leaves the default.
     */
    private static function today(GraphEdge $line, string $css): GraphEdge
    {
        foreach (\explode(',', \str_replace(';', replace: ',', subject: $css)) as $declaration) {
            [$key, $value] = \array_map(\trim(...), \explode(':', $declaration, limit: 2)) + ['', ''];
            $pixels = \preg_replace('/px$/', replacement: '', subject: $value) ?? $value;
            $width = \is_numeric($pixels) && (float) $pixels >= 0 ? (float) $pixels / 10 : null;
            $color = MermaidColor::css($value);
            $dashes = MermaidCss::dashes(['dash-pattern' => \preg_replace('/[\s,]+/', replacement: ' ', subject: $value) ?? $value], current: null);
            $line = match (true) {
                'stroke' === $key && null !== $color => $line->painted($color, $line->textColor),
                'stroke-width' === $key && null !== $width => $line->stroked($width),
                'stroke-dasharray' === $key && null !== $dashes => $line->dashed(\max($dashes) > 0, $dashes),
                default => $line,
            };
        }

        return $line;
    }

    private function line(float $x0, float $y0, float $x1, float $y1, string $color): GraphEdge
    {
        return new GraphEdge(-1, -1, [[$x0, $y0], [$x1, $y1]], null, \min($x0, $x1), \min($y0, $y1), \max($x0, $x1), \max($y0, $y1), $color, null, null, dashed: false, decoration: true);
    }
}
