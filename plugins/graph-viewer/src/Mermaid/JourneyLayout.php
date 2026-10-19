<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphFont;
use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * Pinned `journeyRenderer`, ten pixels a unit: the actor legend at the left, each section's
 * header over its tasks, a column per task with the actors' dots on its edge, the timeline
 * arrow beneath them, and each task's score face on its dashed line. The legend keeps every
 * declaration in source order.
 */
final readonly class JourneyLayout
{
    /** Pinned `journey.actorColours`, by sorted actor. */
    private const array ACTORS = ['#8fbc8f', '#7cfc00', '#00ffff', '#20b2aa', '#b0e0e6', '#ffffe0'];
    /** Pinned task boxes are 150 by 50 pixels, 50 apart; sections sit at 50 and tasks at 110. */
    private const float WIDTH = 15.0;
    private const float STEP = 20.0;
    private const float SECTION = 5.0;
    private const float TASK = 11.0;
    private const float BOX = 5.0;
    /** The timeline at four box heights, faces from 300 pixels, task lines to 450. */
    private const float AXIS = 20.0;
    private const float FACES = 30.0;
    private const float FOOT = 45.0;

    private MermaidOptions $options;

    public function __construct(?MermaidOptions $options = null)
    {
        $this->options = $options ?? MermaidOptions::defaults();
    }

    /** @return \Generator<int, null, mixed, MermaidScene> */
    public function layout(JourneyDiagram $source): \Generator
    {
        $nodes = [];
        $edges = [];
        $elements = [];
        $origins = [];
        $legend = [];
        foreach ($this->items($source) as $item) {
            $legend[] = ['text' => $item['text'], 'origin' => $item['origin']];
            yield null;
        }
        $top = 2.5;
        $title = $source->metadata['title'] ?? null;
        $actors = self::actors($source);
        $left = \max(15.0, 6.0 + \max([0, ...\array_map(AnsiUtils::visibleWidth(...), \array_keys($actors))]));
        $shapes = [];
        foreach (\array_keys($actors) as $index => $actor) {
            $y = $top + 6 + (2 * $index);
            $shapes[\count($nodes)] = 'circle';
            $nodes[] = self::dot(\count($nodes), $actor, 2.0, $y, $actors[$actor]);
            $width = (float) AnsiUtils::visibleWidth($actor);
            $shapes[\count($nodes)] = 'text';
            $nodes[] = new GraphNode(\count($nodes), 'actor:'.$actor, $actor, 4 + ($width / 2), $y, $width, 2.0, null, null, dashed: false, background: true, textColor: '#666666');
        }
        $tasks = $this->tasks($source, $nodes, $edges, $elements, $origins, $left, $top, $actors);
        yield from $tasks;
        // Pinned `drawTasks` stops the timeline four pixels short of the last column's end.
        $end = $left + (\count($source->tasks) * self::STEP) - 0.4;
        if (null !== $title) {
            // Pinned `4ex` titles are twice the body text.
            $scale = 2 * $this->scale('titleFontSize', default: 32.0);
            $width = AnsiUtils::visibleWidth($title['label']) * $scale;
            $shapes[\count($nodes)] = 'text';
            $nodes[] = new GraphNode(
                \count($nodes),
                'title',
                $title['label'],
                $left + ($width / 2),
                $top + 1.5,
                $width,
                2 * $scale,
                null,
                null,
                dashed: false,
                background: true,
                textColor: '@titleColor',
                font: GraphFont::Bold,
                fontScale: $scale,
            );
        }
        $axis = $top + self::AXIS;
        // Pinned journey CSS strokes every `line` in the text color, over their own attributes.
        $edges[] = new GraphEdge(0, 0, [[$left, $axis], [$end - 1.2, $axis]], [$end, $axis], $left, $axis, $end, $axis, '@textColor', null, null, dashed: false, strokeWidth: 0.4, decoration: true);
        $height = $top + self::FOOT + 2;
        $rows = MermaidOrigins::rows($height, $source->origin);
        foreach ($origins as $id => $origin) {
            $node = $nodes[$id];
            for ($row = (int) (($node->y - ($node->h / 2)) / 2); $row <= (int) (($node->y + ($node->h / 2)) / 2); ++$row) {
                $rows[$row] = $origin;
                yield null;
            }
        }
        $shapes += $tasks->getReturn();

        return new MermaidScene(new GraphLayout(\max($end, $left + 20) + 2, $height, $nodes, $edges, clusters: []), $rows, $legend, $elements, $shapes);
    }

    /**
     * Each task's column with its section header first, the actors' dots, its dashed line and
     * its score face; the shapes of everything drawn.
     *
     * @param array<int, GraphNode> $nodes
     * @param list<GraphEdge> $edges
     * @param array<int, int> $elements
     * @param array<int, int> $origins
     * @param array<string, string> $actors
     * @return \Generator<int, null, mixed, array<int, string>>
     */
    private function tasks(JourneyDiagram $source, array &$nodes, array &$edges, array &$elements, array &$origins, float $left, float $top, array $actors): \Generator
    {
        $shapes = [];
        $scale = $this->scale('taskFontSize', default: 14.0);
        $current = false;
        foreach ($source->tasks as $index => $task) {
            $x = $left + ($index * self::STEP);
            $slot = $task['section'] ?? 0;
            $fill = '@fillType'.($slot % 8);
            if ($current !== $task['section'] && null !== $task['section']) {
                $count = \count(\array_filter(\array_slice($source->tasks, $index), static fn (array $later): bool => $later['section'] === $task['section']));
                $width = (self::WIDTH * $count) + (5 * ($count - 1));
                $section = $source->sections[$task['section']];
                $wrap = MermaidWrap::text($section['label'], width: (int) \max(1, ($width - 2) / $scale));
                yield from $wrap;
                $id = \count($nodes);
                $nodes[$id] = new GraphNode(
                    $id,
                    'section:'.$task['section'],
                    $wrap->getReturn(),
                    $x + ($width / 2),
                    $top + self::SECTION + (self::BOX / 2),
                    $width,
                    self::BOX,
                    '#666666',
                    $fill,
                    dashed: false,
                    textColor: '@textColor',
                    fontScale: $scale,
                );
                $shapes[$id] = 'rounded';
                $origins[$id] = $section['origin'];
            }
            $current = $task['section'];
            $wrap = MermaidWrap::text($task['label'], width: (int) \max(1, (self::WIDTH - 2) / $scale));
            yield from $wrap;
            $label = $wrap->getReturn();
            $height = \max(self::BOX, (2 * (\substr_count($label, needle: "\n") + 1) * $scale) + 2);
            $center = $x + (self::WIDTH / 2);
            $taskTop = $top + self::TASK;
            $face = $top + self::FACES + ((5 - $task['score']) * 3);
            // The dashed line runs behind the face: drawn in two pieces around it.
            foreach ([[$taskTop + $height, $face - 1.5], [$face + 1.5, $top + self::FOOT]] as [$from, $to]) {
                if ($to <= $from) {
                    continue;
                }
                $edges[] = new GraphEdge(
                    0,
                    0,
                    [[$center, $from], [$center, $to]],
                    null,
                    $center,
                    $from,
                    $center,
                    $to,
                    '@textColor',
                    null,
                    null,
                    dashed: true,
                    strokeWidth: 0.1,
                    decoration: true,
                    dashes: [0.4, 0.2],
                );
            }
            $id = \count($nodes);
            $nodes[$id] = new GraphNode(
                $id,
                'task:'.$index,
                self::summary($index, $task),
                $center,
                $taskTop + ($height / 2),
                self::WIDTH,
                $height,
                '#666666',
                $fill,
                dashed: false,
                drawLabel: $label,
                textColor: '@textColor',
                fontScale: $scale,
            );
            $shapes[$id] = 'rounded';
            $elements[$id] = $task['origin'];
            $origins[$id] = $task['origin'];
            foreach (\array_values(\array_unique(\array_filter($task['actors'], static fn (string $actor): bool => '' !== $actor))) as $offset => $actor) {
                $shapes[\count($nodes)] = 'circle';
                $nodes[] = self::dot(\count($nodes), $actor, $x + 1.4 + $offset, $taskTop, $actors[$actor]);
            }
            $id = \count($nodes);
            $nodes[$id] = new GraphNode($id, 'face:'.$index, '', $center, $face, 3.0, 3.0, '#999999', '#fff8dc', dashed: false, background: true);
            $shapes[$id] = JourneyFaces::shape($task['score']);
            yield null;
        }

        return $shapes;
    }

    /**
     * Pinned actors in sorted order, each in its `actorColours` slot.
     *
     * @return array<string, string>
     */
    private static function actors(JourneyDiagram $source): array
    {
        $names = \array_values(\array_unique(\array_filter(\array_merge(...\array_column([...$source->tasks, ['actors' => []]], 'actors')), static fn (string $actor): bool => '' !== $actor)));
        \sort($names, flags: \SORT_STRING);
        $colors = [];
        foreach ($names as $index => $name) {
            $colors[$name] = self::ACTORS[$index % \count(self::ACTORS)];
        }

        return $colors;
    }

    private static function dot(int $id, string $actor, float $x, float $y, string $color): GraphNode
    {
        return new GraphNode($id, 'dot:'.$actor, '', $x, $y, 1.4, 1.4, '#000000', $color, dashed: false, background: true);
    }

    /** A pinned text size as a scale of its default; `4ex` is twice the 16-pixel body text. */
    private function scale(string $key, float $default): float
    {
        // @mago-expect analysis:mixed-assignment -- A size is a number or a CSS length, narrowed below.
        $value = $this->options->value('journey', $key);
        $match = [];
        // @mago-expect analysis:invalid-type-cast -- the pattern admits only a decimal before `ex`
        $pixels = \is_string($value) && 1 === \preg_match('/^(\d+(?:\.\d+)?)ex$/', \trim($value), $match) ? (float) $match[1] * 8 : MermaidFonts::pixels($value);

        return null !== $pixels && $pixels > 0 ? $pixels / $default : 1.0;
    }

    /** @return \Generator<int, array{text: string, origin: int}> */
    private function items(JourneyDiagram $source): \Generator
    {
        $metadata = $source->metadata;
        $metadata['title'] ??= ['label' => 'Journey', 'origin' => $source->origin];
        foreach ($metadata as $kind => $item) {
            yield ['text' => $kind.': '.$item['label'], 'origin' => $item['origin']];
        }
        $section = 0;
        foreach ($source->tasks as $index => $task) {
            while (null !== $task['section'] && $section <= $task['section']) {
                $item = $source->sections[$section++];
                yield ['text' => 'section: '.$item['label'], 'origin' => $item['origin']];
            }
            yield ['text' => self::summary($index, $task), 'origin' => $task['origin']];
        }
        while ($section < \count($source->sections)) {
            $item = $source->sections[$section++];
            yield ['text' => 'section: '.$item['label'], 'origin' => $item['origin']];
        }
    }

    /**
     * A task's text for search, selection and the legend: its number, score and actors.
     *
     * @param array{label: string, score: float, scoreText: string, actors: list<string>, section: ?int, origin: int} $task
     */
    private static function summary(int $index, array $task): string
    {
        $actors = \json_encode($task['actors'], flags: \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);

        return ($index + 1).'. '.$task['label'].' | score: '.$task['scoreText'].'/5 | actors: '.$actors;
    }
}
