<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphCluster;
use Porta\GraphViewer\Graph\GraphFont;
use Porta\GraphViewer\Graph\GraphLayout;

/** Declaration-order grids honor separate element and boundary row limits at every level. */
final readonly class C4Placement
{
    public function __construct(
        private C4Diagram $source,
        private C4Elements $elements,
    ) {}

    /** @return \Generator<int, null, mixed, GraphLayout> */
    public function layout(): \Generator
    {
        $work = $this->group(null);
        yield from $work;
        $tile = $work->getReturn();

        return new GraphLayout($tile->width + 4, $tile->height + 4, $tile->nodes, edges: [], clusters: $tile->clusters);
    }

    /** @return \Generator<int, null, mixed, C4Tile> */
    private function group(?string $name): \Generator
    {
        $leaves = [];
        foreach ($this->source->nodes as $alias => $element) {
            if ($element['parent'] !== $name) {
                continue;
            }
            $node = $this->elements->nodes[$this->elements->ids[$alias]];
            $allocation = $this->reserve(new C4Tile($node->w, $node->h, [$node->id => $node->at($node->w / 2, $node->h / 2)]), (string) $alias);
            yield from $allocation;
            $leaves[] = $allocation->getReturn();
            yield null;
        }
        $packing = $this->pack($leaves, $this->source->columns);
        yield from $packing;
        $leafGrid = $packing->getReturn();
        $children = [];
        foreach ($this->source->groups as $alias => $group) {
            if ($group['parent'] !== $name) {
                continue;
            }
            $work = $this->group((string) $alias);
            yield from $work;
            $children[] = $work->getReturn();
        }
        $packing = $this->pack($children, $this->source->groupColumns);
        yield from $packing;
        $groupGrid = $packing->getReturn();
        $gap = [] === $leaves || [] === $children ? 0.0 : 6.0;
        $header = null === $name ? null : $this->elements->nodes[$this->elements->groups[$name]];
        $top = null === $header ? 4.0 : $header->h + 4;
        $contentWidth = \max($leafGrid->width, $groupGrid->width);
        $width = \max($contentWidth + 8, $header->w ?? 0.0);
        $height = $top + $leafGrid->height + $gap + $groupGrid->height + 4;
        $first = $this->translated($leafGrid, x: 4.0, y: $top);
        yield from $first;
        $firstTile = $first->getReturn();
        $second = $this->translated($groupGrid, x: 4.0, y: $top + $leafGrid->height + $gap);
        yield from $second;
        $secondTile = $second->getReturn();
        $nodes = $firstTile->nodes + $secondTile->nodes;
        $clusters = [...$firstTile->clusters, ...$secondTile->clusters];
        if (null !== $header && null !== $name) {
            $nodes[$header->id] = $header->at($width / 2, $header->h / 2);
            $record = $this->source->groups[$name];
            $attrs = $record['attributes'];
            $color = MermaidColor::parse('stroke', C4Styles::slot($attrs, key: 'borderColor', fallback: '#444444'), $record['statement'], family: 'C4');
            $fill = MermaidColor::parse('fill', C4Styles::slot($attrs, key: 'bgColor', fallback: 'none'), $record['statement'], family: 'C4');
            $text = MermaidColor::parse('color', C4Styles::slot($attrs, key: 'fontColor', fallback: '#333333'), $record['statement'], family: 'C4');
            \array_unshift(
                $clusters,
                new GraphCluster(
                    $name,
                    $header->label,
                    x0: 0.0,
                    y0: 0.0,
                    x1: $width,
                    y1: $height,
                    color: $color,
                    labelPos: null,
                    fillColor: $fill,
                    textColor: $text,
                    dashed: !\in_array($record['macro'], ['Deployment_Node', 'Node', 'Node_L', 'Node_R'], strict: true),
                    font: GraphFont::Bold,
                    fontScale: $header->fontScale,
                ),
            );
        }

        $tile = new C4Tile($width, $height, $nodes, $clusters);
        if (null !== $name && !\array_key_exists($name, $this->elements->ids)) {
            $allocation = $this->reserve($tile, $name);
            yield from $allocation;

            return $allocation->getReturn();
        }

        return $tile;
    }

    /** @return \Generator<int, null, mixed, C4Tile> */
    private function reserve(C4Tile $tile, string $name): \Generator
    {
        $label = $this->elements->edgeLabels[$name."\0".$name] ?? null;
        if (null === $label) {
            return $tile;
        }
        [$width, $height] = MermaidText::box($label);
        $left = \max(0.0, ($width / 2) - 2 - (0.75 * $tile->width));
        $right = \max(10.0, ($width / 2) + 6 - ($tile->width / 4));
        $work = $this->translated($tile, $left, y: 0.0);
        yield from $work;
        $placed = $work->getReturn();

        return new C4Tile($tile->width + $left + $right, $tile->height + 10 + $height, $placed->nodes, $placed->clusters);
    }

    /** @param list<C4Tile> $tiles
     * @return \Generator<int, null, mixed, C4Tile>
     */
    private function pack(array $tiles, int $columns): \Generator
    {
        $nodes = [];
        $clusters = [];
        $x = 0.0;
        $y = 0.0;
        $rowHeight = 0.0;
        $width = 0.0;
        foreach ($tiles as $index => $tile) {
            if (0 !== $index && 0 === ($index % $columns)) {
                $x = 0.0;
                $y += $rowHeight + 6;
                $rowHeight = 0.0;
            }
            $work = $this->translated($tile, $x, $y);
            yield from $work;
            $placed = $work->getReturn();
            $nodes += $placed->nodes;
            \array_push($clusters, ...$placed->clusters);
            $width = \max($width, $x + $tile->width);
            $rowHeight = \max($rowHeight, $tile->height);
            $x += $tile->width + 4;
            yield null;
        }

        return new C4Tile($width, $y + $rowHeight, $nodes, $clusters);
    }

    /** @return \Generator<int, null, mixed, C4Tile> */
    private function translated(C4Tile $tile, float $x, float $y): \Generator
    {
        $work = MermaidTranslation::graph(new GraphLayout($tile->width, $tile->height, $tile->nodes, edges: [], clusters: $tile->clusters), $x, $y);
        yield from $work;
        $graph = $work->getReturn();

        return new C4Tile($tile->width, $tile->height, $graph->nodes, $graph->clusters);
    }
}
