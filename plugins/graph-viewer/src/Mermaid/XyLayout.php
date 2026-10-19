<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;

/** Bars paint behind lines; every datum keeps its own semantic node and authored series origin. */
final readonly class XyLayout
{
    private MermaidOptions $options;

    public function __construct(?MermaidOptions $options = null)
    {
        $this->options = $options ?? MermaidOptions::defaults();
    }

    /** @return \Generator<int, null, mixed, MermaidScene> */
    public function layout(XyDiagram $source): \Generator
    {
        if (!$source->oriented) {
            $source = clone $source;
            $source->orientation = 'horizontal' === $this->options->text('xyChart', 'chartOrientation') ? 'horizontal' : 'vertical';
        }
        $measure = $this->measure($source);
        yield from $measure;
        [$text, $padding] = $measure->getReturn();
        $nodes = [];
        $origins = [];
        $shapes = [];
        $elements = [];
        $legend = [];
        $top = $this->titles($source, $text, $nodes, $origins, $shapes, $legend);
        $geometry = new XyGeometry($source, left: 64.0, top: $top + $padding + 10);
        $axes = $this->axes($geometry, $text, $nodes, $origins, $shapes);
        yield from $axes;
        [$nodes, $origins, $shapes, $edges] = $axes->getReturn();
        $footer = $geometry->top + $geometry->height + $padding + 16;
        foreach ($source->series as $index => $series) {
            $name = $this->seriesName($series['title'], $series['kind'], $index);
            $legend[] = ['text' => $name, 'origin' => $series['origin']];
            if ('' !== $series['title'] && $this->options->flag('xyChart', 'showLegend')) {
                $this->caption($nodes, $origins, $shapes, 'legend-'.$index, $text[$name], x: 134.0, y: $footer + ($text[$name]['h'] / 2), origin: $series['origin']);
                $footer += $text[$name]['h'] + 2;
            }
            yield null;
        }
        $order = \array_keys($source->series);
        \usort($order, static fn (int $a, int $b): int => [$source->series[$a]['kind'], $a] <=> [$source->series[$b]['kind'], $b]);
        foreach ($order as $index) {
            $work = $this->plot($geometry, $index, $text, \count($nodes));
            yield from $work;
            [$plotNodes, $plotEdges, $plotLegend, $values] = $work->getReturn();
            foreach ($plotNodes as $node) {
                $nodes[$node->id] = $node;
                $origins[$node->id] = $source->series[$index]['origin'];
                $elements[$node->id] = $source->series[$index]['origin'];
                $shapes[$node->id] = 'line' === $source->series[$index]['kind'] ? 'point' : 'rect';
                yield null;
            }
            \array_push($edges, ...$plotEdges);
            \array_push($legend, ...$plotLegend);
            foreach ($this->options->flag('xyChart', 'showDataLabel') ? $values : [] as $value) {
                $this->value($nodes, $origins, $shapes, $value, $source->series[$index]['origin'], $source->orientation);
            }
        }
        $rows = MermaidOrigins::rows($footer + $padding + 2, $source->origin);
        foreach ($nodes as $node) {
            for ($row = \max(0, (int) \floor(($node->y - ($node->h / 2)) / 2)); $row <= (int) \ceil(($node->y + ($node->h / 2)) / 2); ++$row) {
                $rows[$row] = $origins[$node->id];
                yield null;
            }
        }

        return new MermaidScene(new GraphLayout(234.0, $footer + $padding, $nodes, $edges, clusters: []), $rows, $legend, $elements, $shapes);
    }

    /**
     * The title captions, and every metadata entry in the legend; pinned `showTitle` can hide the title.
     *
     * @param array<array-key, array{label: string, w: float, h: float}> $text
     * @param array<int, GraphNode> $nodes
     * @param array<int, int> $origins
     * @param array<int, string> $shapes
     * @param list<array{text: string, origin: int}> $legend
     */
    private function titles(XyDiagram $source, array $text, array &$nodes, array &$origins, array &$shapes, array &$legend): float
    {
        $top = 2.0;
        foreach ($source->metadata as $name => $item) {
            $legend[] = ['text' => $name.': '.$item['label'], 'origin' => $item['origin']];
            $shown = 'title' !== $name || $this->options->flag('xyChart', 'showTitle');
            if ($shown && \in_array($name, ['title', 'acctitle', 'accdescr'], strict: true)) {
                $height = $text[$item['label']]['h'];
                $this->caption($nodes, $origins, $shapes, $name, $text[$item['label']], x: 134.0, y: $top + ($height / 2), origin: $item['origin']);
                $top += $height + 2;
            }
        }

        return $top;
    }

    /** @return \Generator<int, string> */
    private function texts(XyDiagram $source): \Generator
    {
        foreach ($source->metadata as $item) {
            yield $item['label'];
        }
        foreach ($source->categories ?? [] as $category) {
            yield $category;
        }
        foreach ([$source->xRange ?? [1.0, 1.0], $source->yRange ?? [0.0, 1.0]] as $range) {
            for ($index = 0; $index <= 4; ++$index) {
                yield (string) XyScale::value($index / 4, $range);
            }
        }
        foreach ($source->series as $index => $series) {
            yield $this->seriesName($series['title'], $series['kind'], $index);
            foreach ($series['values'] as $point) {
                yield $point['label'];
            }
        }
    }

    /** @return \Generator<int, null, mixed, array{array<array-key, array{label: string, w: float, h: float}>, float}> */
    private function measure(XyDiagram $source): \Generator
    {
        $text = [];
        $height = 6.0;
        foreach ($this->texts($source) as $label) {
            if (\array_key_exists($label, $text)) {
                continue;
            }
            $work = MermaidWrap::text($label, width: 32);
            yield from $work;
            $wrapped = $work->getReturn();
            [$width, $extent] = MermaidText::box($wrapped);
            $text[$label] = ['label' => $wrapped, 'w' => $width, 'h' => $extent];
            $height = \max($height, $extent);
            yield null;
        }

        return [$text, $height + 4];
    }

    /**
     * @param array<array-key, array{label: string, w: float, h: float}> $text
     * @param array<int, GraphNode> $nodes
     * @param array<int, int> $origins
     * @param array<int, string> $shapes
     * @return \Generator<int, null, mixed, array{array<int, GraphNode>, array<int, int>, array<int, string>, list<GraphEdge>}>
     */
    private function axes(XyGeometry $geometry, array $text, array $nodes, array $origins, array $shapes): \Generator
    {
        $edges = $this->lines($geometry);
        $ticks = [];
        foreach ($geometry->categories ?? [] as $index => $category) {
            $ticks[] = [$category, ($index + 0.5) / \count($geometry->categories ?? [])];
        }
        if (null === $geometry->categories) {
            for ($index = 0; $index <= 4; ++$index) {
                $ticks[] = [(string) XyScale::value($index / 4, $geometry->xRange), $index / 4];
            }
        }
        foreach ($this->options->nested('xyChart', 'xAxis', 'showLabel') ? $ticks : [] as $index => [$label, $fraction]) {
            [$x, $y] = $geometry->position($fraction, value: 0.0);
            if ('horizontal' === $geometry->source->orientation) {
                $x = $geometry->left - 20;
            }
            if ('vertical' === $geometry->source->orientation) {
                $y += 6 + ($text[$label]['h'] / 2);
            }
            $this->caption($nodes, $origins, $shapes, 'category-'.$index, $text[$label], $x, $y, $geometry->source->metadata['x-axis']['origin'] ?? $geometry->source->origin);
            yield null;
        }
        for ($index = 0; $index <= ($this->options->nested('xyChart', 'yAxis', 'showLabel') ? 4 : -1); ++$index) {
            $label = (string) XyScale::value($index / 4, $geometry->yRange);
            [$x, $y] = $geometry->position(category: 0.0, value: $index / 4);
            if ('horizontal' === $geometry->source->orientation) {
                $y = $geometry->top + $geometry->height + 6 + ($text[$label]['h'] / 2);
            }
            if ('vertical' === $geometry->source->orientation) {
                $x = $geometry->left - 20;
            }
            $this->caption($nodes, $origins, $shapes, 'value-'.$index, $text[$label], $x, $y, $geometry->source->metadata['y-axis']['origin'] ?? $geometry->source->origin);
            yield null;
        }
        foreach (['x-axis', 'y-axis'] as $axis) {
            $label = $geometry->source->metadata[$axis]['label'] ?? '';
            if ('' === $label || !$this->options->nested('xyChart', $axis[0].'Axis', 'showTitle')) {
                continue;
            }
            $bottom = ('x-axis' === $axis) === ('vertical' === $geometry->source->orientation);
            $x = $bottom ? $geometry->left + ($geometry->width / 2) : 20.0;
            $y = $bottom ? $geometry->top + $geometry->height + $text[$label]['h'] + 10 : $geometry->top + ($geometry->height / 2);
            $this->caption($nodes, $origins, $shapes, $axis, $text[$label], $x, $y, $geometry->source->metadata[$axis]['origin']);
            if (!$bottom) {
                // Pinned side titles read upward; pictures turn them, cells keep them level.
                $shapes[\count($nodes) - 1] = 'rotated:-90:'.$x.':'.$y;
            }
            yield null;
        }

        return [$nodes, $origins, $shapes, $edges];
    }

    /**
     * @param array<array-key, array{label: string, w: float, h: float}> $text
     * @return \Generator<int, null, mixed, array{array<int, GraphNode>, list<GraphEdge>, list<array{text: string, origin: int}>, list<array{GraphNode, string}>}>
     */
    private function plot(XyGeometry $geometry, int $index, array $text, int $next): \Generator
    {
        $series = $geometry->source->series[$index];
        $count = \count($series['values']);
        $name = $this->seriesName($series['title'], $series['kind'], $index);
        $color = '@xyChart.plotColorPalette['.$index.']';
        $nodes = [];
        $edges = [];
        $legend = [];
        $values = [];
        $previous = null;
        foreach ($series['values'] as $position => $point) {
            [$category, $fraction] = $geometry->category($position, $count);
            [$x, $y] = $geometry->position($fraction, XyScale::fraction($point['value'], $geometry->yRange));
            $label = self::short($name).' · '.self::short($category).': '.$point['value'];
            if ('' !== $point['label']) {
                $label .= ' ('.self::short($point['label']).')';
            }
            $id = $next + $position;
            $node = 'bar' === $series['kind']
                ? self::bar($geometry, $fraction, $count, $id, $index, $position, $label, $color, $x, $y)
                : new GraphNode($id, 'xy-'.$index.'-'.$position, $label, $x, $y, 1.2, 1.2, $color, $color, dashed: false, strokeWidth: 0.0, drawLabel: $text[$point['label']]['label']);
            if ('line' === $series['kind'] && null !== $previous) {
                $edges[] = self::edge([$previous, [$x, $y]], $color);
            }
            $nodes[$id] = $node;
            $legend[] = ['text' => $label, 'origin' => $series['origin']];
            if ('bar' === $series['kind']) {
                $values[] = [$node, (string) $point['value']];
            }
            $previous = [$x, $y];
            yield null;
        }

        return [$nodes, $edges, $legend, $values];
    }

    private static function bar(XyGeometry $geometry, float $fraction, int $count, int $id, int $index, int $position, string $label, string $color, float $x, float $y): GraphNode
    {
        [$baseX, $baseY] = $geometry->position($fraction, value: 0.0);
        $horizontal = 'horizontal' === $geometry->source->orientation;
        $width = $horizontal ? \abs($x - $baseX) : $geometry->thickness($count);
        $height = $horizontal ? $geometry->thickness($count) : \abs($y - $baseY);

        return new GraphNode(
            $id,
            'xy-'.$index.'-'.$position,
            $label,
            ($x + $baseX) / 2,
            ($y + $baseY) / 2,
            $width,
            $height,
            $color,
            $color,
            dashed: false,
            background: true,
            strokeWidth: 0.0,
            drawLabel: '',
        );
    }

    /** @param array<int, GraphNode> $nodes
     * @param array<int, int> $origins
     * @param array<int, string> $shapes
     * @param array{label: string, w: float, h: float} $text
     */
    private function caption(array &$nodes, array &$origins, array &$shapes, string $name, array $text, float $x, float $y, int $origin): void
    {
        $id = \count($nodes);
        // Pinned `xyChart` text variables: categories label the x axis, values the y axis.
        $color = match (\explode('-', $name, limit: 2)[0]) {
            'category' => '@xyChart.xAxisLabelColor',
            'value' => '@xyChart.yAxisLabelColor',
            'x', 'y' => '@xyChart.'.$name[0].'AxisTitleColor',
            'legend' => '@textColor',
            default => '@xyChart.titleColor',
        };
        $nodes[$id] = new GraphNode($id, $name, $text['label'], $x, $y, $text['w'], $text['h'], null, null, dashed: false, background: true, textColor: $color);
        $origins[$id] = $origin;
        $shapes[$id] = 'text';
    }

    /**
     * Pinned `showDataLabel`: a bar's value inside its far end, or past it with `showDataLabelOutsideBar`.
     *
     * @param array<int, GraphNode> $nodes
     * @param array<int, int> $origins
     * @param array<int, string> $shapes
     * @param array{GraphNode, string} $value
     */
    private function value(array &$nodes, array &$origins, array &$shapes, array $value, int $origin, string $orientation): void
    {
        [$bar, $label] = $value;
        [$width, $height] = MermaidText::box($label);
        $outside = $this->options->flag('xyChart', 'showDataLabelOutsideBar') ? -1 : 1;
        [$x, $y] = 'vertical' === $orientation
            ? [$bar->x, $bar->y - ($bar->h / 2) + ($outside * (1 + ($height / 2)))]
            : [$bar->x + ($bar->w / 2) - ($outside * (1 + ($width / 2))), $bar->y];
        $id = \count($nodes);
        $nodes[$id] = new GraphNode($id, 'data-'.$bar->name, $label, $x, $y, $width, $height, null, null, dashed: false, background: true, textColor: '@xyChart.dataLabelColor');
        $origins[$id] = $origin;
        $shapes[$id] = 'text';
    }

    /**
     * The left line is the y axis of a vertical chart and the x axis of a horizontal one.
     *
     * @return list<GraphEdge>
     */
    private function lines(XyGeometry $geometry): array
    {
        [$left, $bottom] = 'horizontal' === $geometry->source->orientation ? ['x', 'y'] : ['y', 'x'];
        $base = $geometry->top + $geometry->height;
        $lines = [
            $left => self::edge([[$geometry->left, $geometry->top], [$geometry->left, $base]], '@xyChart.'.$left.'AxisLineColor'),
            $bottom => self::edge([[$geometry->left, $base], [$geometry->left + $geometry->width, $base]], '@xyChart.'.$bottom.'AxisLineColor'),
        ];

        return \array_values(\array_filter($lines, fn (string $axis): bool => $this->options->nested('xyChart', $axis.'Axis', 'showAxisLine'), mode: \ARRAY_FILTER_USE_KEY));
    }

    /** @param list<array{float, float}> $points */
    private static function edge(array $points, string $color): GraphEdge
    {
        return new GraphEdge(
            -1,
            -1,
            $points,
            null,
            \min(\array_column($points, 0)),
            \min(\array_column($points, 1)),
            \max(\array_column($points, 0)),
            \max(\array_column($points, 1)),
            $color,
            null,
            null,
            dashed: false,
        );
    }

    private function seriesName(string $title, string $kind, int $index): string
    {
        return '' === $title ? $kind.' '.($index + 1) : $title;
    }

    private static function short(string $text): string
    {
        return \mb_strimwidth(\strtr($text, ["\n" => ' ']), start: 0, width: 120, trim_marker: '…');
    }
}
