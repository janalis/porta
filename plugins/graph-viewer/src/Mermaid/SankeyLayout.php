<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\Theme\JsNumber;
use Porta\GraphViewer\Mermaid\Theme\JsObject;

/** Deterministic justified layers, label padding and ribbons sharing one global value-to-height scale. */
final readonly class SankeyLayout
{
    /** Pinned sankey nodes take d3 `schemeTableau10` in source order, whatever the theme. */
    private const array COLORS = ['#4e79a7', '#f28e2c', '#e15759', '#76b7b2', '#59a14f', '#edc949', '#af7aa1', '#ff9da7', '#9c755f', '#bab0ab'];

    private MermaidOptions $options;

    public function __construct(?MermaidOptions $options = null)
    {
        $this->options = $options ?? MermaidOptions::defaults();
    }

    /** @return \Generator<int, null, mixed, MermaidScene> */
    public function layout(SankeyDiagram $source): \Generator
    {
        $build = SankeyFlow::build($source, $this->options->text('sankey', 'nodeAlignment'));
        yield from $build;
        $flow = $build->getReturn();
        $measure = $this->measure($source);
        yield from $measure;
        $labels = $measure->getReturn();
        [$height, $unit] = self::scale($flow, $labels);
        $nodes = [];
        $elements = [];
        $shapes = [];
        $legend = [];
        foreach ($flow->layers as $level => $ids) {
            $used = 0.0;
            foreach ($ids as $id) {
                $used += $labels[$id]['height'] + ($flow->weights[$id] * $unit) + 6;
            }
            $top = 4 + (($height - $used) / 2);
            foreach ($ids as $id) {
                $size = $labels[$id]['height'] + ($flow->weights[$id] * $unit);
                $color = $this->color($source, $id);
                $nodes[$id] = new GraphNode(
                    $id,
                    'sankey-'.$id,
                    $source->nodes[$id]['label'],
                    4.0 + ($level * 64),
                    $top + ($size / 2),
                    3.0,
                    $size,
                    $color,
                    $color,
                    dashed: false,
                    strokeWidth: 0.0,
                    drawLabel: '',
                );
                $elements[$id] = $source->nodes[$id]['origin'];
                $legend[] = ['text' => $source->nodes[$id]['label'], 'origin' => $source->nodes[$id]['origin']];
                $top += $size + 6;
                yield null;
            }
        }
        \ksort($nodes);
        $width = (\max(\array_keys($flow->layers)) * 64.0) + 42;
        $central = \intdiv(num1: \max(\array_keys($flow->layers)), num2: 2);
        foreach ($source->nodes as $id => $item) {
            $caption = \count($nodes);
            $node = $nodes[$id];
            // Pinned legacy labels face the middle; outlined ones face away from the central layer.
            $left = 'outlined' === $this->options->text('sankey', 'labelStyle') ? $node->x < (4.0 + ($central * 64)) : $node->x >= ($width / 2);
            $nodes[$caption] = new GraphNode(
                $caption,
                'caption-'.$id,
                $labels[$id]['draw'],
                $node->x + ($left ? -19 : 19),
                $node->y,
                32.0,
                $labels[$id]['height'],
                null,
                '@background',
                dashed: false,
                strokeWidth: 0.0,
                textColor: '@textColor',
            );
            yield null;
        }
        $edges = $this->edges($source, $flow, $nodes, $unit);
        yield from $edges;
        foreach ($source->links as $link) {
            $legend[] = ['text' => $source->nodes[$link['source']]['label'].' → '.$source->nodes[$link['target']]['label'].': '.$link['value'], 'origin' => $link['origin']];
            yield null;
        }
        $origins = MermaidOrigins::rows($height + 10, $source->origin);
        foreach ($source->nodes as $id => $item) {
            $node = $nodes[$id];
            for ($row = (int) \floor(($node->y - ($node->h / 2)) / 2); $row <= (int) \ceil(($node->y + ($node->h / 2)) / 2); ++$row) {
                $origins[$row] = $item['origin'];
                yield null;
            }
        }

        return new MermaidScene(new GraphLayout($width, $height + 8, $nodes, $edges->getReturn(), clusters: []), $origins, $legend, $elements, $shapes);
    }

    /**
     * Pinned `sankey.linkColor` at the links' 50% opacity: the source's or the target's color, or
     * an authored color. A gradient, the default, keeps its starting color for cells.
     */
    private function link(SankeyDiagram $source, int $tail, int $head): string
    {
        $setting = $this->options->text('sankey', 'linkColor');
        $color = match ($setting) {
            'target' => $this->color($source, $head),
            'source', 'gradient' => $this->color($source, $tail),
            default => MermaidColor::css($setting) ?? $this->color($source, $tail),
        };

        return \substr($color, offset: 0, length: 7).'80';
    }

    /** Pinned `sankey.nodeColors` by node name, else the Tableau10 slot. */
    private function color(SankeyDiagram $source, int $id): string
    {
        // @mago-expect analysis:mixed-assignment -- An authored map, narrowed below.
        $colors = $this->options->value('sankey', 'nodeColors');
        // @mago-expect analysis:mixed-assignment -- An authored color, narrowed below.
        $authored = $colors instanceof \stdClass ? JsObject::get($colors, MermaidText::decode($source->nodes[$id]['label'])) : null;

        return (\is_string($authored) ? MermaidColor::css($authored) : null) ?? self::COLORS[$id % \count(self::COLORS)];
    }

    /**
     * Pinned labels: the node name, then with `showValues` its value, rounded to hundredths,
     * between `prefix` and `suffix`.
     *
     * @return \Generator<int, null, mixed, list<array{draw: string, height: float}>>
     */
    private function measure(SankeyDiagram $source): \Generator
    {
        $labels = [];
        $values = \array_fill(0, \count($source->nodes), value: [0.0, 0.0]);
        foreach ($source->links as $link) {
            $values[$link['source']][1] += $link['value'];
            $values[$link['target']][0] += $link['value'];
        }
        $shown = $this->options->flag('sankey', 'showValues');
        foreach ($source->nodes as $id => $item) {
            $value = \round(\max($values[$id]) * 100) / 100;
            $text = MermaidText::decode($item['label']).($shown ? "\n".$this->options->text('sankey', 'prefix').JsNumber::format($value).$this->options->text('sankey', 'suffix') : '');
            $wrap = MermaidWrap::text($text, width: 26);
            yield from $wrap;
            $draw = $wrap->getReturn();
            [, $height] = MermaidText::box($draw);
            $labels[] = ['draw' => $draw, 'height' => $height];
            yield null;
        }

        return $labels;
    }

    /** @param list<array{draw: string, height: float}> $labels
     * @return array{float, float}
     */
    private static function scale(SankeyFlow $flow, array $labels): array
    {
        $height = 120.0;
        foreach ($flow->layers as $ids) {
            $padding = 0.0;
            foreach ($ids as $id) {
                $padding += $labels[$id]['height'] + 6;
            }
            $height = \max($height, $padding + 120);
        }
        $unit = \INF;
        foreach ($flow->layers as $ids) {
            $padding = 0.0;
            $total = 0.0;
            foreach ($ids as $id) {
                $padding += $labels[$id]['height'] + 6;
                $total += $flow->weights[$id];
            }
            if ($total > 0) {
                $unit = \min($unit, ($height - $padding) / $total);
            }
        }

        return [$height, \is_finite($unit) ? $unit : 0.0];
    }

    /** @param array<int, GraphNode> $nodes
     * @return \Generator<int, null, mixed, list<\Porta\GraphViewer\Graph\GraphEdge>>
     */
    private function edges(SankeyDiagram $source, SankeyFlow $flow, array $nodes, float $unit): \Generator
    {
        $starts = [];
        $ends = [];
        foreach ($source->nodes as $id => $item) {
            foreach (['source' => $flow->outgoing[$id], 'target' => $flow->incoming[$id]] as $side => $indices) {
                $opposite = 'source' === $side ? 'target' : 'source';
                \usort($indices, static fn (int $a, int $b): int => [$nodes[$source->links[$a][$opposite]]->y, $a] <=> [$nodes[$source->links[$b][$opposite]]->y, $b]);
                $offset = $nodes[$id]->y - ($nodes[$id]->h / 2) + 1;
                foreach ($indices as $index) {
                    $width = $flow->values[$index] * $unit;
                    if ('source' === $side) {
                        $starts[$index] = $offset + ($width / 2);
                    }
                    if ('target' === $side) {
                        $ends[$index] = $offset + ($width / 2);
                    }
                    $offset += $width;
                    yield null;
                }
            }
        }
        $edges = [];
        foreach ($source->links as $index => $link) {
            $tail = $nodes[$link['source']];
            $head = $nodes[$link['target']];
            $ribbon = SankeyRibbon::edge(
                $tail->id,
                $head->id,
                $tail->x + 1.5,
                $starts[$index],
                $head->x - 1.5,
                $ends[$index],
                $flow->values[$index] * $unit,
                $this->link($source, $link['source'], $link['target']),
            );
            // A pinned gradient runs from the source's color to the target's along the link.
            $edges[] = 'gradient' === $this->options->text('sankey', 'linkColor')
                ? SankeyRibbon::gradient($ribbon, $this->color($source, $link['source']), $this->color($source, $link['target']))
                : $ribbon;
            yield null;
        }

        return $edges;
    }
}
