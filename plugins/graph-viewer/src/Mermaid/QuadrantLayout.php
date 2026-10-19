<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphCluster;
use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;

/** A Cartesian unit square: x grows right, y grows up; decorations never become selectable points. */
final readonly class QuadrantLayout
{
    private MermaidOptions $options;

    public function __construct(?MermaidOptions $options = null)
    {
        $this->options = $options ?? MermaidOptions::defaults();
    }

    /** @return \Generator<int, null, mixed, MermaidScene> */
    public function layout(QuadrantDiagram $source): \Generator
    {
        $measure = $this->labels($source);
        yield from $measure;
        [$labels, $side, $legend] = $measure->getReturn();
        $measure = $this->points($source);
        yield from $measure;
        [$points, $padding, $pointLegend] = $measure->getReturn();
        $left = \max(40.0, $side / 2) + $padding;
        $header = $this->headers($labels, $left + ($side / 2));
        yield from $header;
        [$nodes, $nodeOrigins, $top] = $header->getReturn();
        $top += $padding;
        // Pinned charts with points keep the X axis below; `xAxisPosition` places it on empty ones.
        $bottom = [] !== $points || 'bottom' === $this->options->text('quadrantChart', 'xAxisPosition');
        if (!$bottom) {
            $top += \max($labels['x-axis-low']['h'] ?? 0.0, $labels['x-axis-high']['h'] ?? 0.0) + 4;
        }
        $right = 'right' === $this->options->text('quadrantChart', 'yAxisPosition');
        $clusters = [];
        foreach ([1 => [0.5, 0.0], 2 => [0.0, 0.0], 3 => [0.0, 0.5], 4 => [0.5, 0.5]] as $index => [$x, $y]) {
            $clusters[] = new GraphCluster(
                'quadrant-'.$index,
                '',
                $left + ($x * $side),
                $top + ($y * $side),
                $left + (($x + 0.5) * $side),
                $top + (($y + 0.5) * $side),
                '@quadrantInternalBorderStrokeFill',
                null,
                fillColor: '@quadrant'.$index.'Fill',
                strokeWidth: $this->stroke('quadrantInternalBorderStrokeWidth', 1.0),
            );
            yield null;
        }
        // Pinned charts frame the quadrants at the external width, over the internal cross.
        $clusters[] = new GraphCluster(
            'quadrant-frame',
            '',
            $left,
            $top,
            $left + $side,
            $top + $side,
            '@quadrantExternalBorderStrokeFill',
            null,
            fillColor: 'none',
            strokeWidth: $this->stroke('quadrantExternalBorderStrokeWidth', 2.0),
        );
        $positions = [
            'quadrant-1' => [0.75, 0.25],
            'quadrant-2' => [0.25, 0.25],
            'quadrant-3' => [0.25, 0.75],
            'quadrant-4' => [0.75, 0.75],
            'x-axis-low' => [0.25, 1.12],
            'x-axis-high' => [0.75, 1.12],
            'y-axis-low' => [$right ? 1.28 : -0.28, 0.75],
            'y-axis-high' => [$right ? 1.28 : -0.28, 0.25],
        ];
        foreach ($positions as $name => [$x, $y]) {
            if (!\array_key_exists($name, $labels)) {
                continue;
            }
            $item = $labels[$name];
            $positionY = $this->captionY($name, $item['h'], $top, $side, $y, $bottom, [] !== $points);
            $id = \count($nodes);
            $nodes[$id] = $this->caption($id, $name, $item, $left + ($x * $side), $positionY);
            $nodeOrigins[$id] = $item['origin'];
            yield null;
        }
        foreach ($points as $point) {
            $id = \count($nodes);
            $nodes[$id] = new GraphNode(
                $id,
                'point-'.$id,
                $point['label'],
                $left + ($side * $point['x']),
                $top + ($side * (1 - $point['y'])),
                2 * $point['radius'],
                2 * $point['radius'],
                $point['color'],
                $point['fill'],
                dashed: false,
                strokeWidth: $point['stroke'],
                textColor: '@quadrantPointTextFill',
                fontScale: $this->scale('point'),
            );
            $nodeOrigins[$id] = $point['origin'];
            yield null;
        }
        $work = $this->scene($nodes, $nodeOrigins, $clusters, [...$legend, ...$pointLegend], $left + $side + $padding + ($right ? $left : 0.0), $top + $side + $padding, $source->origin);
        yield from $work;

        return $work->getReturn();
    }

    /** @return \Generator<int, null, mixed, array{array<string, array{label: string, origin: int, w: float, h: float, scale: float}>, float, list<array{text: string, origin: int}>}> */
    private function labels(QuadrantDiagram $source): \Generator
    {
        $labels = [];
        $legend = [];
        $side = 100.0;
        foreach ($source->labels as $name => $item) {
            $wrap = MermaidWrap::text($item['label'], width: 40);
            yield from $wrap;
            $label = $wrap->getReturn();
            $scale = $this->scale($name);
            [$width, $height] = MermaidText::box($label, $scale);
            $labels[$name] = ['label' => $label, 'origin' => $item['origin'], 'w' => $width, 'h' => $height, 'scale' => $scale];
            $legend[] = ['text' => $name.': '.$item['label'], 'origin' => $item['origin']];
            $side = \max($side, (2 * $height) + 20);
        }

        return [$labels, $side, $legend];
    }

    /** @return \Generator<int, null, mixed, array{list<array{label: string, x: float, y: float, radius: float, stroke: float, color: string, fill: string, origin: int}>, float, list<array{text: string, origin: int}>}> */
    private function points(QuadrantDiagram $source): \Generator
    {
        $points = [];
        $legend = [];
        $padding = 12.0;
        foreach ($source->points as $point) {
            $style = \array_replace($source->classes[$point['class']] ?? [], $point['style']);
            $radius = (\is_float($style['radius'] ?? null) ? $style['radius'] : $this->options->number('quadrantChart', 'pointRadius', fallback: 5.0)) / 10;
            $stroke = \is_float($style['stroke-width'] ?? null) ? $style['stroke-width'] / 10 : 0.0;
            // An invalid point fill inherits the pinned root `fill: textColor`.
            $fill = \is_string($style['color'] ?? null) ? $style['color'] : '@quadrantPointFill|textColor';
            $color = \is_string($style['stroke-color'] ?? null) ? $style['stroke-color'] : $fill;
            $wrap = MermaidWrap::text($point['label'], width: 40);
            yield from $wrap;
            $label = $wrap->getReturn();
            [, $height] = MermaidText::box($label, $this->scale('point'));
            $points[] = ['label' => $label, 'x' => $point['x'], 'y' => $point['y'], 'radius' => $radius, 'stroke' => $stroke, 'color' => $color, 'fill' => $fill, 'origin' => $point['origin']];
            $padding = \max($padding, $radius + $height + $stroke + 4);
            $legend[] = ['text' => $point['label'].': ['.$point['x'].', '.$point['y'].']', 'origin' => $point['origin']];
            yield null;
        }

        return [$points, $padding, $legend];
    }

    /**
     * @param array<string, array{label: string, origin: int, w: float, h: float, scale: float}> $labels
     * @return \Generator<int, null, mixed, array{array<int, GraphNode>, array<int, int>, float}>
     */
    private function headers(array $labels, float $center): \Generator
    {
        $nodes = [];
        $origins = [];
        $top = 2.0;
        foreach ($labels as $name => $item) {
            if (\in_array($name, ['title', 'acctitle', 'accdescr'], strict: true)) {
                $id = \count($nodes);
                $nodes[$id] = $this->caption($id, $name, $item, $center, $top + ($item['h'] / 2));
                $origins[$id] = $item['origin'];
                $top += $item['h'] + 2;
            }
            yield null;
        }

        return [$nodes, $origins, $top];
    }

    /** @param array{label: string, origin: int, w: float, h: float, scale: float} $item */
    private function caption(int $id, string $name, array $item, float $x, float $y): GraphNode
    {
        // Pinned quadrant text variables, one per caption kind.
        $color = match (true) {
            \str_starts_with($name, 'quadrant-') => '@quadrant'.\substr($name, offset: 9).'TextFill',
            \str_starts_with($name, 'x-axis') => '@quadrantXAxisTextFill',
            \str_starts_with($name, 'y-axis') => '@quadrantYAxisTextFill',
            default => '@quadrantTitleFill',
        };

        return new GraphNode($id, $name, $item['label'], $x, $y, $item['w'], $item['h'], null, null, dashed: false, background: true, textColor: $color, fontScale: $item['scale']);
    }

    // @mago-expect lint:no-boolean-flag-parameter -- Where the X axis sits and whether points push labels up are layout facts.
    private function captionY(string $name, float $height, float $top, float $side, float $fraction, bool $bottom, bool $points): float
    {
        return match (true) {
            \str_starts_with($name, 'x-axis') => $bottom ? $top + $side + ($height / 2) + 4 : $top - ($height / 2) - 4,
            $points && \str_starts_with($name, 'quadrant-') => $top + (\floor($fraction * 2) * ($side / 2)) + ($height / 2) + 4,
            default => $top + ($fraction * $side),
        };
    }

    /**
     * @param array<int, GraphNode> $nodes
     * @param array<int, int> $nodeOrigins
     * @param list<GraphCluster> $clusters
     * @param list<array{text: string, origin: int}> $legend
     * @return \Generator<int, null, mixed, MermaidScene>
     */
    private function scene(array $nodes, array $nodeOrigins, array $clusters, array $legend, float $width, float $height, int $header): \Generator
    {
        foreach ($nodes as $node) {
            $height = \max($height, $node->y + ($node->h / 2) + 4);
            yield null;
        }
        $origins = MermaidOrigins::rows($height + 2, $header);
        $elements = [];
        $shapes = [];
        foreach ($nodes as $id => $node) {
            $shapes[$id] = $node->background ? self::text($node, $clusters) : 'point';
            if (!$node->background) {
                $elements[$id] = $nodeOrigins[$id];
            }
            for ($row = \max(0, (int) \floor(($node->y - ($node->h / 2)) / 2)); $row <= (int) \ceil(($node->y + ($node->h / 2)) / 2); ++$row) {
                $origins[$row] = $nodeOrigins[$id];
                yield null;
            }
        }

        return new MermaidScene(new GraphLayout($width, $height, $nodes, edges: [], clusters: $clusters), $origins, $legend, $elements, $shapes);
    }

    /**
     * A caption's shape. Pinned y-axis captions read upward beside the chart; pictures turn
     * them there, while cells keep them level where the layout put them.
     *
     * @param list<GraphCluster> $clusters
     */
    private static function text(GraphNode $node, array $clusters): string
    {
        if ([] === $clusters || !\str_starts_with($node->name, 'y-axis')) {
            return 'text';
        }
        $left = \min(\array_map(static fn (GraphCluster $cluster): float => $cluster->x0, $clusters));
        $right = \max(\array_map(static fn (GraphCluster $cluster): float => $cluster->x1, $clusters));
        $reach = (($node->h - 4) / 2) + 1.5;
        $x = $node->x > $right ? $right + $reach : $left - $reach;

        return 'rotated:-90:'.$x.':'.$node->y;
    }

    /** Pinned text sizes per caption kind, as a scale of each one's default. */
    private function scale(string $name): float
    {
        [$key, $default] = match (true) {
            'title' === $name => ['titleFontSize', 20.0],
            'point' === $name => ['pointLabelFontSize', 12.0],
            \str_starts_with($name, 'quadrant-') => ['quadrantLabelFontSize', 16.0],
            \str_starts_with($name, 'x-axis') => ['xAxisLabelFontSize', 16.0],
            \str_starts_with($name, 'y-axis') => ['yAxisLabelFontSize', 16.0],
            default => [null, 1.0],
        };
        $size = null === $key ? $default : $this->options->number('quadrantChart', $key, fallback: $default);

        return $size > 0 ? $size / $default : 1.0;
    }

    /** A pinned border width in pixels, as native units. */
    private function stroke(string $key, float $default): float
    {
        return \max(0.0, $this->options->number('quadrantChart', $key, fallback: $default)) / 10;
    }
}
