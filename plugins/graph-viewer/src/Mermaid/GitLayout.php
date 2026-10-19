<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/** Chronology follows one axis, stable branch order the other; glyphs retain commit identities. */
final readonly class GitLayout
{
    public function __construct(
        private GitConfiguration $configuration = new GitConfiguration(),
        private GitColors $colors = new GitColors(),
    ) {}

    /** @return \Generator<int, null, mixed, MermaidScene> */
    public function layout(GitDiagram $source): \Generator
    {
        $branches = $source->branches;
        \uasort($branches, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);
        $lanes = \array_flip(\array_keys($branches));
        $wrapping = $this->labels($source);
        yield from $wrapping;
        [$labels, $laneHeight, $branchLabels] = $wrapping->getReturn();
        $ranking = $this->positions($source);
        yield from $ranking;
        $positions = $ranking->getReturn();
        $last = 1 + \max([0, ...$positions]);
        $horizontal = 'LR' === $source->direction;
        $width = $horizontal ? 30.0 + ($last * 26) : 30.0 + (\count($branches) * 32);
        $height = $horizontal ? 2.0 + (\count($branches) * $laneHeight) : 8.0 + (($last + 1) * $laneHeight);
        $headers = $this->branches($source, $lanes, $laneHeight, $branchLabels);
        yield from $headers;
        [$nodes, $elements, $shapes, $legend] = $headers->getReturn();
        $ids = [];
        foreach ($source->commits as $name => $commit) {
            $id = \count($branches) + \count($ids);
            $ids[$name] = $id;
            $lane = $lanes[$commit['branch']];
            $chronology = 'BT' === $source->direction ? $last - 1 - $positions[$name] : $positions[$name];
            [$x, $y] = $horizontal ? [30.0 + ($chronology * 26), ($laneHeight / 2) + ($lane * $laneHeight)] : [12.0 + ($lane * 32), $laneHeight + 4.0 + ($chronology * $laneHeight)];
            $type = $commit['customType'] ?? $commit['type'];
            [$fill, $stroke] = 'HIGHLIGHT' === $type ? $this->colors->highlight($lane) : [$this->colors->commit($lane), $this->colors->commit($lane)];
            $nodes[$id] = new GraphNode($id, (string) $name, $labels[$name], $x, $y, w: 4.0, h: 4.0, color: $stroke, fillColor: $fill, dashed: false, drawLabel: '');
            $shapes[$id] = 'git:'.$commit['type'].':'.($commit['customType'] ?? '').'|'.$this->colors->inner();
            $elements[$id] = $commit['origin'];
            $caption = $this->caption($commit['id'], $commit['custom'], $commit['type']);
            yield from $caption;
            $captionId = -1 - \count($ids);
            [$nodes[$captionId], $shapes[$captionId]] = $this->label($captionId, (string) $name, $caption->getReturn(), $x, $y, $horizontal);
            if ([] !== $commit['tags']) {
                $tagId = -1_000_000 - \count($ids);
                $nodes[$tagId] = $this->tags($tagId, (string) $name, $commit['tags'], $x, $y);
                // Pinned `.tag-hole` takes the text color, which the shape carries as a theme token.
                $shapes[$tagId] = 'git-tag|@textColor';
            }
            $legend[] = ['text' => $commit['branch'].' · '.$commit['type'].' · '.$labels[$name], 'origin' => $commit['origin']];
            yield null;
        }
        $connections = $this->edges($source, $nodes, $ids, $lanes, $width, $height);
        yield from $connections;
        $edges = $connections->getReturn();
        $rows = MermaidOrigins::rows($height, $source->origin);
        foreach ($elements as $id => $origin) {
            $node = $nodes[$id];
            $rows[(int) ($node->y / 2)] = $origin;
            yield null;
        }
        $scene = new MermaidScene(new GraphLayout($width, $height, $nodes, $edges, clusters: []), $rows, $legend, $elements, $shapes);
        $caption = $this->title($scene, $source);
        yield from $caption;

        return $caption->getReturn();
    }

    /**
     * Pinned `drawCommitLabel`: a commit's id, unless labels are off, it is a cherry-pick, or
     * it is a merge without an authored id. Messages and tags never join it.
     *
     * @return \Generator<int, null, mixed, string>
     */
    private function caption(string $id, bool $custom, string $type): \Generator
    {
        $shown = $this->configuration->labels() && 'CHERRY_PICK' !== $type && ('MERGE' !== $type || $custom);
        $wrap = MermaidWrap::text($shown ? MermaidText::decode($id) : '', width: 20);
        yield from $wrap;

        return $wrap->getReturn();
    }

    /**
     * A commit's label node beneath it, or beside it top to bottom, and its shape: slanted in
     * left-to-right pictures unless `rotateCommitLabel` is off.
     *
     * @return array{GraphNode, string}
     */
    // @mago-expect lint:no-boolean-flag-parameter -- The graph's orientation decides where the label hangs.
    private function label(int $id, string $name, string $display, float $x, float $y, bool $horizontal): array
    {
        $lines = 1 + \substr_count($display, needle: "\n");
        [$cx, $cy] = $horizontal ? [$x, $y + 4 + $lines] : [$x + 15, $y];
        $node = new GraphNode(
            $id,
            '@caption:'.$name,
            $display,
            $cx,
            $cy,
            w: 22.0,
            h: (float) (2 * $lines),
            color: 'none',
            fillColor: $this->colors->caption()[0],
            dashed: false,
            strokeWidth: 0.0,
            textColor: $this->colors->caption()[1],
        );
        $shape = match (true) {
            '' === $display => 'text',
            $horizontal && $this->configuration->rotated() => self::rotated($display, $x, $y),
            default => 'rect',
        };

        return [$node, $shape];
    }

    /**
     * Pinned rotated labels turn 45° up from the commit's lower left, ending just below it.
     */
    private static function rotated(string $display, float $x, float $y): string
    {
        $half = \max(\array_map(AnsiUtils::visibleWidth(...), \explode("\n", $display))) / 2;
        $reach = $half * \M_SQRT1_2;

        return 'rotated:-45:'.($x + 0.5 - $reach).':'.($y + 3 + $reach);
    }

    /**
     * Pinned tag flags, stacked above the commit, the first tag highest.
     *
     * @param list<string> $tags
     */
    private function tags(int $id, string $name, array $tags, float $x, float $y): GraphNode
    {
        $text = \array_map(MermaidText::decode(...), $tags);
        $width = \max(\array_map(AnsiUtils::visibleWidth(...), $text)) + 3.0;
        $height = 2.0 * \count($text);
        [$fill, $stroke, $color] = $this->colors->tag();

        return new GraphNode($id, '@tag:'.$name, \implode("\n", $text), $x, $y - 3 - ($height / 2), $width, $height, $stroke, $fill, dashed: false, background: true, textColor: $color);
    }

    /** @param array<array-key, int> $lanes
     * @param array<array-key, string> $labels
     * @return \Generator<int, null, mixed, array{array<int, GraphNode>, array<int, int>, array<int, string>, list<array{text: string, origin: int}>}>
     */
    private function branches(GitDiagram $source, array $lanes, float $laneHeight, array $labels): \Generator
    {
        $nodes = [];
        $elements = [];
        $shapes = [];
        $legend = $source->metadata;
        $horizontal = 'LR' === $source->direction;
        foreach ($lanes as $name => $lane) {
            $id = \count($nodes);
            [$x, $y] = $horizontal ? [11.0, ($laneHeight / 2) + ($lane * $laneHeight)] : [12.0 + ($lane * 32), $laneHeight / 2];
            $nodes[$id] = new GraphNode(
                $id,
                (string) $name,
                $labels[$name],
                $x,
                $y,
                w: 20.0,
                h: (float) (4 + (2 * \substr_count($labels[$name], needle: "\n"))),
                color: $this->colors->branch($lane)[1],
                fillColor: $this->colors->branch($lane)[0],
                dashed: false,
                textColor: $this->colors->branch($lane)[2],
            );
            $origin = $source->branches[$name]['origin'];
            if ($this->configuration->branches()) {
                $elements[$id] = $origin;
            }
            if (!$this->configuration->branches()) {
                $nodes[$id] = new GraphNode($id, (string) $name, '', $x, $y, w: 0.0, h: 0.0, color: null, fillColor: null, dashed: false);
            }
            $shapes[$id] = $this->configuration->branches() ? 'rounded' : 'text';
            $legend[] = ['text' => 'branch: '.$name, 'origin' => $origin];
            yield null;
        }

        return [$nodes, $elements, $shapes, $legend];
    }

    /** @return \Generator<int, null, mixed, array<array-key, int>> */
    private function positions(GitDiagram $source): \Generator
    {
        $commits = $source->commits;
        \uasort($commits, static fn (array $a, array $b): int => $a['seq'] <=> $b['seq']);
        $positions = [];
        foreach ($commits as $name => $commit) {
            $rank = 0;
            foreach ($commit['parents'] as $parent) {
                $rank = \max($rank, 1 + ($positions[$parent] ?? -1));
            }
            $positions[$name] = $this->configuration->parallel() ? $rank : $commit['seq'];
            yield null;
        }

        return $positions;
    }

    /** @return \Generator<int, null, mixed, MermaidScene> */
    private function title(MermaidScene $scene, GitDiagram $source): \Generator
    {
        foreach ($source->metadata as $metadata) {
            if (!\str_starts_with($metadata['text'], 'title ')) {
                continue;
            }
            $title = MermaidTitle::apply($scene, new MermaidStatement(\substr($metadata['text'], offset: 6), offset: 0, line: $metadata['origin'], column: 0));
            yield from $title;
            $scene = $title->getReturn();
        }

        return $scene;
    }

    /** @return \Generator<int, null, mixed, array{array<array-key, string>, float, array<array-key, string>}> */
    private function labels(GitDiagram $source): \Generator
    {
        $labels = [];
        $laneHeight = 20.0;
        foreach ($source->commits as $id => $commit) {
            $text = $commit['id'].('' === $commit['message'] ? '' : "\n".$commit['message']);
            foreach ($commit['tags'] as $tag) {
                $text .= "\n[tag: ".$tag.']';
            }
            $wrap = MermaidWrap::text(MermaidText::decode($text), width: 20);
            yield from $wrap;
            $labels[$id] = $wrap->getReturn();
            $laneHeight = \max($laneHeight, 2 * (6.0 + (2 * (1 + \substr_count($labels[$id], needle: "\n")))));
        }

        $branchLabels = [];
        foreach ($source->branches as $name => $branch) {
            $wrap = MermaidWrap::text(MermaidText::decode((string) $name), width: 18);
            yield from $wrap;
            $branchLabels[$name] = $wrap->getReturn();
            $laneHeight = \max($laneHeight, 8.0 + (2 * \substr_count($branchLabels[$name], needle: "\n")));
        }

        return [$labels, $laneHeight, $branchLabels];
    }

    /** @param array<int, GraphNode> $nodes
     * @param array<array-key, int> $ids
     * @param array<array-key, int> $lanes
     * @return \Generator<int, null, mixed, list<GraphEdge>>
     */
    private function edges(GitDiagram $source, array $nodes, array $ids, array $lanes, float $width, float $height): \Generator
    {
        $edges = [];
        $horizontal = 'LR' === $source->direction;
        [$arrow, $track] = $this->colors->widths();
        foreach ($this->configuration->branches() ? $lanes : [] as $name => $lane) {
            $node = $nodes[$lane];
            $points = $horizontal ? [[$node->x + 10, $node->y], [$width - 2, $node->y]] : [[$node->x, $node->y + 2], [$node->x, $height - 2]];
            // Pinned branch tracks are dashed.
            $edges[] = self::line($node->id, $node->id, $points, $this->colors->line(), $track, dashed: true, decoration: true);
            yield null;
        }
        foreach ($source->commits as $name => $commit) {
            $node = $nodes[$ids[$name]];
            foreach ($commit['parents'] as $index => $parent) {
                $from = $nodes[$ids[$parent]];
                // A merge's other parent keeps its own lane and color up to the merge.
                $merged = 'MERGE' === $commit['type'] && 0 !== $index;
                $lane = $lanes[$merged ? $source->commits[$parent]['branch'] : $commit['branch']];
                $route = self::route($source, $parent, (string) $name, $from, $node, $merged);
                $edges[] = self::line($from->id, $node->id, $route, $this->colors->commit($lane), $arrow, dashed: 'CHERRY_PICK' === $commit['type'] && 1 === $index);
                yield null;
            }
        }

        return $edges;
    }

    /**
     * Pinned `drawArrow`: an arrow leaves its parent along the child's lane, or a merged parent
     * runs its own lane to the merge; it turns once, rounded. A commit on the turning lane in
     * between sends it through a lane midway, as pinned `shouldRerouteArrow` does.
     *
     * @return list<array{float, float}>
     */
    // @mago-expect lint:no-boolean-flag-parameter -- Whether the parent is merged in is the routing fact pinned drawArrow branches on.
    private static function route(GitDiagram $source, string $parent, string $child, GraphNode $from, GraphNode $to, bool $merged): array
    {
        $horizontal = 'LR' === $source->direction;
        $start = [$from->x, $from->y];
        $end = [$to->x, $to->y];
        $further = $horizontal ? $from->y < $to->y : $from->x < $to->x;
        $turning = $source->commits[$further ? $child : $parent]['branch'];
        $between = \array_filter(
            $source->commits,
            static fn (array $commit): bool => $commit['branch'] === $turning && $commit['seq'] > $source->commits[$parent]['seq'] && $commit['seq'] < $source->commits[$child]['seq'],
        );
        $middle = $horizontal ? ($from->y + $to->y) / 2 : ($from->x + $to->x) / 2;
        $corner = $horizontal === $merged ? [$to->x, $from->y] : [$from->x, $to->y];

        return match (true) {
            ($horizontal ? $from->y : $from->x) === ($horizontal ? $to->y : $to->x) => [$start, $end],
            [] !== $between => self::rounded($horizontal ? [$start, [$from->x, $middle], [$to->x, $middle], $end] : [$start, [$middle, $from->y], [$middle, $to->y], $end], radius: 1.0),
            default => self::rounded([$start, $corner, $end], radius: 2.0),
        };
    }

    /**
     * An orthogonal path with its corners rounded by quarter circles of up to `$radius`.
     *
     * @param list<array{float, float}> $points
     * @return list<array{float, float}>
     */
    private static function rounded(array $points, float $radius): array
    {
        $result = [$points[0]];
        $last = \count($points) - 1;
        for ($index = 1; $index < $last; ++$index) {
            [$before, $corner, $after] = [$points[$index - 1], $points[$index], $points[$index + 1]];
            $in = \hypot($corner[0] - $before[0], $corner[1] - $before[1]);
            $out = \hypot($after[0] - $corner[0], $after[1] - $corner[1]);
            // Lanes and commits lie far further apart than two radii, so a corner always fits.
            $entry = [$corner[0] + ((($before[0] - $corner[0]) * $radius) / $in), $corner[1] + ((($before[1] - $corner[1]) * $radius) / $in)];
            $exit = [$corner[0] + ((($after[0] - $corner[0]) * $radius) / $out), $corner[1] + ((($after[1] - $corner[1]) * $radius) / $out)];
            for ($step = 0; $step <= 4; ++$step) {
                // A quadratic Bézier through the corner traces the quarter turn closely enough at this size.
                $t = $step / 4;
                $u = 1 - $t;
                $result[] = [($u * $u * $entry[0]) + (2 * $u * $t * $corner[0]) + ($t * $t * $exit[0]), ($u * $u * $entry[1]) + (2 * $u * $t * $corner[1]) + ($t * $t * $exit[1])];
            }
        }
        $result[] = $points[$last];

        return $result;
    }

    /** @param list<array{float, float}> $points */
    private static function line(int $from, int $to, array $points, string $color, float $width, bool $dashed = false, bool $decoration = false): GraphEdge
    {
        return new GraphEdge(
            $from,
            $to,
            $points,
            null,
            \min(\array_column($points, 0)),
            \min(\array_column($points, 1)),
            \max(\array_column($points, 0)),
            \max(\array_column($points, 1)),
            $color,
            null,
            null,
            $dashed,
            strokeWidth: $width,
            decoration: $decoration,
        );
    }
}
