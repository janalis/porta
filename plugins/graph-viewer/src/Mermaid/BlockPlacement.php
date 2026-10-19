<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphCluster;
use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;

/** Bottom-up intrinsic sizing, followed by top-down span allocation in each authored grid. */
final class BlockPlacement
{
    /** @var array<array-key, array{float, float}> */
    private array $sizes = [];
    /** @var array<array-key, array{float, float}> */
    private array $natural = [];
    /** @var array<array-key, array{float, float}> */
    private array $padding = [];
    /** @var array<array-key, list<list<string>>> */
    private array $rows = [];
    /** @var array<array-key, int> */
    private array $columns = [];
    /** @var array<array-key, int> */
    private array $ids = [];
    /** @var array<int, GraphNode> */
    private array $nodes = [];
    /** @var list<GraphCluster> */
    private array $clusters = [];

    public function __construct(
        private readonly BlockDiagram $source,
    ) {}

    /** @return \Generator<int, null, mixed, GraphLayout> */
    public function layout(): \Generator
    {
        $space = MermaidLoopSpace::measure($this->source->graph);
        yield from $space;
        $this->padding = $space->getReturn();
        $measure = $this->measure('');
        yield from $measure;
        $place = $this->place('', x: 2.0, y: 2.0, width: $this->sizes[''][0]);
        yield from $place;

        return new GraphLayout($this->sizes[''][0] + 4, $this->sizes[''][1] + 4, $this->nodes, edges: [], clusters: $this->clusters);
    }

    /** @return \Generator<int, null, mixed, null> */
    private function measure(string $scope): \Generator
    {
        $items = $this->source->children[$scope];
        $columns = $this->source->columns[$scope] ?? -1;
        if ($columns <= 0) {
            $columns = \max(1, \array_sum(\array_map(fn (string $id): int => $this->source->spans[$id], $items)));
        }
        $this->columns[$scope] = $columns;
        $rows = [];
        $row = [];
        $used = 0;
        $cell = 6.0;
        foreach ($items as $id) {
            $work = $this->measureItem($id);
            yield from $work;
            $span = $this->source->spans[$id];
            $cell = \max($cell, ($this->sizes[$id][0] - (4 * ($span - 1))) / $span);
            if ([] !== $row && ($used + $span) > $columns) {
                $rows[] = $row;
                $row = [];
                $used = 0;
            }
            $row[] = $id;
            $used += $span;
            yield null;
        }
        if ([] !== $row) {
            $rows[] = $row;
        }
        $this->rows[$scope] = $rows;
        $this->columns[$scope] = \max($columns, ...\array_map(fn (string $id): int => $this->source->spans[$id], $items));
        $width = 0.0;
        $height = 0.0;
        foreach ($rows as $items) {
            $span = \array_sum(\array_map(fn (string $id): int => $this->source->spans[$id], $items));
            $width = \max($width, (\max($columns, $span) * ($cell + 4)) - 4);
            $height += \max(\array_map(fn (string $id): float => $this->sizes[$id][1], $items)) + 6;
            yield null;
        }
        $header = '' === $scope ? 0.0 : $this->source->graph->styles->measureCluster($scope, $this->source->graph->groups[$scope]['label'])[1];
        $headerWidth = '' === $scope ? 0.0 : $this->source->graph->styles->measureCluster($scope, $this->source->graph->groups[$scope]['label'])[0];
        $this->natural[$scope] = [\max($width + 8, $headerWidth), $height + $header + 2];
        [$px, $py] = $this->padding[$scope] ?? [0.0, 0.0];
        $this->sizes[$scope] = [$this->natural[$scope][0] + (2 * $px), $this->natural[$scope][1] + (2 * $py)];

        return null;
    }

    /** @return \Generator<int, null, mixed, null> */
    private function measureItem(string $id): \Generator
    {
        if (\array_key_exists($id, $this->source->children)) {
            yield from $this->measure($id);

            return null;
        }
        $this->natural[$id] = $this->leafSize($id);
        [$px, $py] = $this->padding[$id] ?? [0.0, 0.0];
        $this->sizes[$id] = [$this->natural[$id][0] + (2 * $px), $this->natural[$id][1] + (2 * $py)];

        return null;
    }

    /** @return array{float, float} */
    private function leafSize(string $id): array
    {
        if (\array_key_exists($id, $this->source->spaces)) {
            return [6.0, 6.0];
        }
        [$w, $h] = $this->source->graph->styles->measure($id, $this->source->graph->nodes[$id]['label']);
        $shape = $this->source->graph->shapes[$id] ?? 'rect';
        if (\in_array($shape, ['circle', 'doublecircle', 'diamond'], strict: true)) {
            $w = \max($w, $h) * ('diamond' === $shape ? 1.5 : 1);
            $h = $w;
        }

        return [$w, $h];
    }

    /** @return \Generator<int, null, mixed, null> */
    private function place(string $scope, float $x, float $y, float $width): \Generator
    {
        $header = '' === $scope ? 0.0 : $this->source->graph->styles->measureCluster($scope, $this->source->graph->groups[$scope]['label'])[1];
        [$px, $py] = $this->padding[$scope] ?? [0.0, 0.0];
        $x += $px;
        $y += $py;
        $width -= 2 * $px;
        $height = $this->natural[$scope][1];
        if ('' !== $scope) {
            $group = $this->source->graph->groups[$scope];
            $id = $this->id($scope);
            $this->nodes[$id] = new GraphNode($id, $scope, $group['label'], $x + ($width / 2), $y + ($header / 2), $width, $header, color: null, fillColor: null, dashed: false, drawLabel: '');
            $this->clusters[] = $this->source
                ->graph
                ->styles
                ->decorateCluster(new GraphCluster($scope, $group['label'], $x, $y, $x + $width, $y + $height, color: '#666666', labelPos: null, fillColor: '#f7f7ff'));
        }
        $cell = (($width - 4) / $this->columns[$scope]) - 4;
        $top = $y + $header + 4;
        foreach ($this->rows[$scope] as $items) {
            $rowHeight = \max(\array_map(fn (string $id): float => $this->sizes[$id][1], $items));
            $left = $x + 4;
            foreach ($items as $name) {
                $spanWidth = ($this->source->spans[$name] * ($cell + 4)) - 4;
                if (\array_key_exists($name, $this->source->children)) {
                    $work = $this->place($name, $left, $top, $spanWidth);
                    yield from $work;
                }
                if (!\array_key_exists($name, $this->source->spaces) && !\array_key_exists($name, $this->source->children)) {
                    $id = $this->id($name);
                    $this->nodes[$id] = $this->source
                        ->graph
                        ->styles
                        ->decorate(
                            new GraphNode(
                                $id,
                                $name,
                                $this->source->graph->nodes[$name]['label'],
                                $left + ($spanWidth / 2),
                                $top + ($rowHeight / 2),
                                $spanWidth - (2 * ($this->padding[$name][0] ?? 0.0)),
                                $this->natural[$name][1],
                                color: null,
                                fillColor: null,
                                dashed: false,
                            ),
                        );
                }
                $left += $spanWidth + 4;
                yield null;
            }
            $top += $rowHeight + 6;
        }

        return null;
    }

    private function id(string $name): int
    {
        return $this->ids[$name] ??= \count($this->ids);
    }
}
