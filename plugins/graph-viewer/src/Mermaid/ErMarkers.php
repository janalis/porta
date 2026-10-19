<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphEdge;

/** Endpoint decorations use existing line/fill primitives and never become selectable entities. */
final readonly class ErMarkers
{
    private function __construct(
        private GraphEdge $source,
        private float $x,
        private float $y,
        private float $ux,
        private float $uy,
        private float $scale,
    ) {}

    /** @return list<GraphEdge> */
    public static function forEdge(GraphEdge $edge, string $left, string $right): array
    {
        $points = $edge->points;
        if (\count($points) < 2) {
            return [];
        }
        // Curved edges are many short segments: aim along the line, three units in, and size by its length.
        $length = MermaidPolyline::length($points);
        $reversed = \array_reverse($points);

        return [
            ...self::endpoint($edge, $points[0], MermaidPolyline::along($points, \min($length, 3.0))[0], $left, $length),
            ...self::endpoint($edge, $reversed[0], MermaidPolyline::along($reversed, \min($length, 3.0))[0], $right, $length),
        ];
    }

    /**
     * @param array{float, float} $at
     * @param array{float, float} $next
     * @return list<GraphEdge>
     */
    private static function endpoint(GraphEdge $edge, array $at, array $next, string $cardinality, float $total): array
    {
        $dx = $next[0] - $at[0];
        $dy = $next[1] - $at[1];
        $length = \hypot($dx, $dy);
        if ($length < 1.0e-9) {
            return [];
        }
        $marker = new self($edge, $at[0], $at[1], $dx / $length, $dy / $length, \min(1.0, $total / 4.5));

        return match ($cardinality) {
            '1' => [$marker->bar(0.6), $marker->bar(1.6)],
            '0..1' => [$marker->bar(0.6), ...$marker->ring(2.4)],
            '1..*' => [...$marker->fork(), $marker->bar(2.7)],
            '0..*' => [...$marker->fork(), ...$marker->ring(3.0)],
            'MD parent' => [$marker->primitive([$marker->point(0.2), $marker->point(1.7, across: -0.9), $marker->point(3.2), $marker->point(1.7, across: 0.9)], fill: $marker->line())],
            default => throw new \InvalidArgumentException('Unknown ER endpoint cardinality'),
        };
    }

    /** @return array{float, float} */
    private function point(float $along, float $across = 0.0): array
    {
        return [$this->x + ($this->scale * (($this->ux * $along) - ($this->uy * $across))), $this->y + ($this->scale * (($this->uy * $along) + ($this->ux * $across)))];
    }

    private function bar(float $along): GraphEdge
    {
        return $this->primitive([$this->point($along, across: -0.8), $this->point($along, across: 0.8)]);
    }

    /** @return list<GraphEdge> */
    private function fork(): array
    {
        return [
            $this->primitive([$this->point(0.2, across: -0.9), $this->point(1.8), $this->point(0.2, across: 0.9)]),
            $this->primitive([$this->point(0.2), $this->point(1.8)]),
        ];
    }

    /** @return list<GraphEdge> */
    private function ring(float $along): array
    {
        $outer = [];
        $inner = [];
        for ($index = 0; $index < 32; ++$index) {
            $angle = (2 * \M_PI * $index) / 32;
            $outer[] = $this->point($along + (0.7 * \cos($angle)), 0.7 * \sin($angle));
            $inner[] = $this->point($along + (0.5 * \cos($angle)), 0.5 * \sin($angle));
        }

        // The ring is the line color around the entity background, as the pinned markers paint it.
        return [$this->primitive($outer, fill: $this->line()), $this->primitive($inner, fill: '@mainBkg')];
    }

    /** An authored relationship color, else the theme's line color. */
    private function line(): string
    {
        return $this->source->color ?? '@lineColor';
    }

    /** @param list<array{float, float}> $points */
    private function primitive(array $points, ?string $fill = null): GraphEdge
    {
        $xs = \array_column($points, 0);
        $ys = \array_column($points, 1);

        return new GraphEdge(
            $this->source->tail,
            $this->source->head,
            $points,
            null,
            \min($xs),
            \min($ys),
            \max($xs),
            \max($ys),
            $fill ?? $this->line(),
            null,
            null,
            dashed: false,
            ribbon: null === $fill ? null : $points,
            decoration: true,
        );
    }
}
