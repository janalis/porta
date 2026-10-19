<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphCluster;
use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphLayout;

/** Stable Kahn ranks; one explicit cycle break per stalled component, never recursion. */
final class RankedGraphLayout
{
    /** @return \Generator<int, null, mixed, MermaidScene> */
    public function layout(MermaidGraph $source): \Generator
    {
        $work = new CompoundGraphPlacement()->place($source);
        yield from $work;
        $placement = $work->getReturn();
        $nodes = $placement->graph->nodes;
        $width = $placement->graph->width;
        $height = $placement->graph->height;
        $clusters = $placement->graph->clusters;
        $routes = self::edges($source, $placement);
        yield from $routes;
        $edges = $routes->getReturn();
        $bounds = MermaidCaptionBounds::layout(new GraphLayout($width, $height, $nodes, $edges, $clusters));
        yield from $bounds;
        $bounded = $bounds->getReturn();
        $width = $bounded->width;
        $height = $bounded->height;
        $nodes = $bounded->nodes;
        $edges = $bounded->edges;
        $clusters = $bounded->clusters;
        $origins = MermaidOrigins::rows($height, 0);
        $elements = [];
        $shapes = [];
        $groupSlots = \array_flip(\array_map(\strval(...), \array_keys($source->groups)));
        $nodeSlots = self::declared($source);
        foreach ($clusters as $index => $cluster) {
            $slot = $groupSlots[$cluster->name] ?? 0;
            $cluster = match ($source->colorSlots) {
                MermaidColorSlots::Clusters => $cluster->painted(MermaidColorSlots::stroke($cluster->color, $slot), MermaidColorSlots::fill($cluster->fillColor, $slot), $cluster->textColor),
                MermaidColorSlots::FrameStrokes => $cluster->painted(MermaidColorSlots::stroke($cluster->color, $slot), $cluster->fillColor, $cluster->textColor),
                default => $cluster,
            };
            $clusters[$index] = self::caption(
                $source->styles->decorateCluster($cluster),
                $source->flowchart?->spacing('subGraphTitleMargin.top', default: 0.0) ?? 0.0,
            );
            $origin = $source->groups[$cluster->name]['origin'];
            for ($row = (int) \floor($cluster->y0 / 2); $row <= (int) \ceil($cluster->y1 / 2); ++$row) {
                $origins[$row] = $origin;
                yield null;
            }
        }
        foreach ($nodes as $node) {
            if (MermaidColorSlots::Nodes === $source->colorSlots) {
                $slot = $nodeSlots[$node->name] ?? 0;
                $node = $node->painted(MermaidColorSlots::stroke($node->color, $slot), MermaidColorSlots::fill($node->fillColor, $slot), $node->textColor);
            }
            $nodes[$node->id] = $source->styles->decorate($node);
            $origin = $source->nodes[$node->name]['origin'];
            $elements[$node->id] = $origin;
            $shapes[$node->id] = $source->shapes[$node->name] ?? 'rect';
            for ($row = (int) \floor(($node->y - ($node->h / 2)) / 2); $row <= (int) \ceil(($node->y + ($node->h / 2)) / 2); ++$row) {
                $origins[$row] = $origin;
                yield null;
            }
            yield null;
        }

        return new MermaidScene(new GraphLayout($width, $height, $nodes, $edges, $clusters), $origins, $this->legend($source, $placement->directions), $elements, $shapes);
    }

    /** @return \Generator<int, null, mixed, list<GraphEdge>> */
    private static function edges(MermaidGraph $source, MermaidPlacement $placement): \Generator
    {
        $edges = [];
        $outgoing = [];
        $incoming = [];
        foreach ($source->edges as $edge) {
            $outgoing[$edge['from']] = ($outgoing[$edge['from']] ?? 0) + 1;
            $incoming[$edge['to']] = ($incoming[$edge['to']] ?? 0) + 1;
            yield null;
        }
        foreach ($source->edges as $index => $edge) {
            if (\str_contains($edge['arrow'], '~')) {
                continue;
            }
            // Fan-ins keep their own approach angles; fan-outs leave one shared exit.
            $anchor = $incoming[$edge['to']] > $outgoing[$edge['from']] ? $edge['from'] : $edge['to'];
            $edges[] = $source->edgeStyles->decorate(MermaidGraphEdges::edge(
                $placement->endpoints[$edge['from']],
                $placement->endpoints[$edge['to']],
                $edge['label'],
                $edge['arrow'],
                $source->shapes[$edge['from']] ?? 'rect',
                $source->shapes[$edge['to']] ?? 'rect',
                $source->edgeStyles->curve($index, $source->flowchart?->curve()),
                $placement->directions[self::scope($source, $edge['from'])],
                $placement->lanes[$index] ?? [],
                $placement->endpoints[$anchor],
            ), $index);
            yield null;
        }

        return $edges;
    }

    /**
     * Palette slots follow first mention, as the pinned databases assign them.
     *
     * @return array<string, int>
     */
    private static function declared(MermaidGraph $source): array
    {
        $offsets = \array_map(static fn (array $node): int => $node['offset'], $source->nodes);
        \asort($offsets, flags: \SORT_NUMERIC);

        return \array_flip(\array_map(\strval(...), \array_keys($offsets)));
    }

    private static function scope(MermaidGraph $source, string $id): string
    {
        if (\array_key_exists($id, $source->groups)) {
            return $source->groups[$id]['parent'] ?? '';
        }
        $groups = $source->nodes[$id]['groups'] ?? [];

        return [] === $groups ? '' : $groups[\count($groups) - 1];
    }

    private static function caption(GraphCluster $cluster, float $margin): GraphCluster
    {
        if (0.0 === $margin) {
            return $cluster;
        }

        return new GraphCluster(
            $cluster->name,
            $cluster->label,
            $cluster->x0,
            $cluster->y0,
            $cluster->x1,
            $cluster->y1,
            $cluster->color,
            [($cluster->x0 + $cluster->x1) / 2, $cluster->y0 + $margin + (2 * $cluster->fontScale)],
            $cluster->fillColor,
            $cluster->textColor,
            $cluster->strokeWidth,
            $cluster->dashed,
            $cluster->font,
            $cluster->fontScale,
            dashes: $cluster->dashes,
        );
    }

    /**
     * @param array<array-key, string> $directions
     * @return list<array{text: string, origin: int}>
     */
    private function legend(MermaidGraph $source, array $directions): array
    {
        $legend = [];
        foreach ($source->groups as $id => $group) {
            $legend[] = ['text' => '['.$group['label'].' · '.$directions[$id].']', 'origin' => $group['origin']];
        }
        foreach ($source->nodes as $id => $node) {
            $legend[] = ['text' => $id.('' === $node['label'] || (string) $id === $node['label'] ? '' : ': '.$node['label']), 'origin' => $node['origin']];
        }
        foreach ($source->edges as $edge) {
            $legend[] = ['text' => $edge['from'].' '.$edge['arrow'].' '.$edge['to'].('' === $edge['label'] ? '' : ' : '.$edge['label']), 'origin' => $edge['origin']];
        }

        return $legend;
    }
}
