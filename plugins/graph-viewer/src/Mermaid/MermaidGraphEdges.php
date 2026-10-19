<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphNode;

/** Outline intersections and directed link geometry shared by ranked and authored grids. */
final class MermaidGraphEdges
{
    /** Pinned destructEndLink counts the ending operator, excluding any label opener. */
    public static function rankSpan(string $arrow): int
    {
        $line = \substr($arrow, offset: 0, length: -1);
        if (\in_array($line[0] ?? '', ['<', 'o', 'x'], strict: true)) {
            $line = \substr($line, offset: 1);
        }
        $dots = \substr_count($line, needle: '.');

        return \max(1, $dots > 0 ? $dots : \strlen($line) - 1);
    }

    /** @param list<array{float, float}> $lanes Reserved intermediate-rank points, in source-to-target order. */
    public static function edge(
        GraphNode $from,
        GraphNode $to,
        string $label,
        string $arrow,
        string $fromShape,
        string $toShape,
        ?MermaidCurve $curve = null,
        string $direction = 'TB',
        array $lanes = [],
        ?GraphNode $anchor = null,
    ): GraphEdge {
        if (null !== $curve && [] !== $lanes) {
            // The half rank before the target lets the route enter along the rank axis.
            $last = $lanes[\count($lanes) - 1];
            $entry = self::waypoint(new GraphNode(0, '', '', $last[0], $last[1], w: 0.0, h: 0.0, color: null, fillColor: null, dashed: false), $to, $direction);

            return self::route($from, $to, $label, $arrow, $fromShape, $toShape, $curve, null === $entry ? $lanes : [...$lanes, $entry]);
        }
        $waypoint = null === $curve || $from->id === $to->id ? null : self::waypoint($from, $to, $direction, $anchor);

        return self::route($from, $to, $label, $arrow, $fromShape, $toShape, $curve, null === $waypoint ? [] : [$waypoint]);
    }

    /** Pinned swimlanes route orthogonally through the rank gap; their basis default becomes rounded. */
    public static function orthogonal(GraphNode $from, GraphNode $to, string $label, string $arrow, string $fromShape, string $toShape, MermaidCurve $curve, string $direction): GraphEdge
    {
        $waypoint = $from->id === $to->id ? null : self::waypoint($from, $to, $direction);
        $bends = [];
        if (null !== $waypoint) {
            $bends = \in_array($direction, ['LR', 'RL'], strict: true) ? [[$waypoint[0], $from->y], $waypoint] : [[$from->x, $waypoint[1]], $waypoint];
        }

        return self::route($from, $to, $label, $arrow, $fromShape, $toShape, MermaidCurve::Basis === $curve ? MermaidCurve::Rounded : $curve, $bends);
    }

    /** @param list<array{float, float}> $bends */
    private static function route(GraphNode $from, GraphNode $to, string $label, string $arrow, string $fromShape, string $toShape, ?MermaidCurve $curve, array $bends): GraphEdge
    {
        $self = $from->id === $to->id;
        $points = $self ? self::loop($from, $fromShape, $toShape) : self::anchors($from, $to, $fromShape, $toShape, $bends);
        $segment = $self ? 1 : 0;
        $labelPosition = [($points[$segment][0] + $points[$segment + 1][0]) / 2, ($points[$segment][1] + $points[$segment + 1][1]) / 2];
        if (null !== $curve) {
            [$points, $labelPosition] = self::curved($points, $curve, $self, $labelPosition);
        }
        $tip = null;
        if (\str_ends_with($arrow, '>') || \str_ends_with($arrow, 'o') || \str_ends_with($arrow, 'x')) {
            $last = \count($points) - 1;
            $tip = $points[$last];
            $dx = $points[$last - 1][0] - $tip[0];
            $dy = $points[$last - 1][1] - $tip[1];
            $length = \hypot($dx, $dy);
            $amount = \min(0.5, $length / 2) / \max(1.0, $length);
            // Like DOT splines, stop at the arrow base so its tip has a direction.
            $points[$last] = [$tip[0] + ($dx * $amount), $tip[1] + ($dy * $amount)];
        }
        $bounds = $points;
        if (null !== $tip) {
            $bounds[] = $tip;
        }
        $xs = \array_column($bounds, 0);
        $ys = \array_column($bounds, 1);

        return new GraphEdge(
            $from->id,
            $to->id,
            $points,
            $tip,
            \min($xs),
            \min($ys),
            \max($xs),
            \max($ys),
            null,
            '' === $label ? null : $label,
            '' === $label ? null : $labelPosition,
            dashed: \str_contains($arrow, '.'),
            strokeWidth: \str_contains($arrow, '=') ? 0.4 : 0.12,
        );
    }

    /**
     * Dagre doubles ranks, so every routed edge crosses one dummy rank between its endpoints.
     * The dummy keeps its anchor's cross-axis position (the target unless told otherwise), as
     * balanced alignment does for the endpoint with the single neighbor.
     *
     * @return array{float, float}|null
     */
    private static function waypoint(GraphNode $from, GraphNode $to, string $direction, ?GraphNode $anchor = null): ?array
    {
        $anchor ??= $to;
        $horizontal = \in_array($direction, ['LR', 'RL'], strict: true);
        [$fromCenter, $toCenter] = $horizontal ? [$from->x, $to->x] : [$from->y, $to->y];
        [$fromHalf, $toHalf] = $horizontal ? [$from->w / 2, $to->w / 2] : [$from->h / 2, $to->h / 2];
        $sign = $toCenter >= $fromCenter ? 1 : -1;
        $leave = $fromCenter + ($sign * $fromHalf);
        $enter = $toCenter - ($sign * $toHalf);
        if ((($enter - $leave) * $sign) <= 0) {
            return null;
        }
        $middle = ($leave + $enter) / 2;

        return $horizontal ? [$middle, $anchor->y] : [$anchor->x, $middle];
    }

    /**
     * @param list<array{float, float}> $bends
     * @return non-empty-list<array{float, float}>
     */
    private static function anchors(GraphNode $from, GraphNode $to, string $fromShape, string $toShape, array $bends): array
    {
        if ([] !== $bends) {
            $first = $bends[0];
            $last = $bends[\count($bends) - 1];

            return [MermaidOutline::boundary($from, $fromShape, $first[0], $first[1]), ...$bends, MermaidOutline::boundary($to, $toShape, $last[0], $last[1])];
        }
        $points = [MermaidOutline::boundary($from, $fromShape, $to->x, $to->y), MermaidOutline::boundary($to, $toShape, $from->x, $from->y)];
        if (self::contains($from, $to)) {
            $points[1] = MermaidOutline::boundary($to, $toShape, $points[0][0], $points[0][1]);
        }
        if (self::contains($to, $from)) {
            $points[0] = MermaidOutline::boundary($from, $fromShape, $points[1][0], $points[1][1]);
        }

        return $points;
    }

    /** @return non-empty-list<array{float, float}> */
    private static function loop(GraphNode $node, string $fromShape, string $toShape): array
    {
        $right = $node->x + ($node->w / 2) + 3;
        $top = $node->y - ($node->h / 2) - 3;
        $start = MermaidOutline::boundary($node, $fromShape, $right, $node->y);
        $end = MermaidOutline::boundary($node, $toShape, $node->x, $top);

        return [$start, [$right, $start[1]], [$right, $top], [$end[0], $top], $end];
    }

    /**
     * @param non-empty-list<array{float, float}> $points
     * @param array{float, float} $labelPosition
     * @return array{non-empty-list<array{float, float}>, array{float, float}}
     */
    // @mago-expect lint:no-boolean-flag-parameter -- Whether the route is a self-loop is the one fact that changes its sampled span.
    private static function curved(array $points, MermaidCurve $curve, bool $self, array $labelPosition): array
    {
        // Preserve straight approaches to silhouettes; a global spline can
        // otherwise turn back through its own source or target card.
        $routed = MermaidCurves::path($self ? \array_slice($points, offset: 1, length: -1) : $points, $curve);
        $labelPosition = $routed->middle() ?? $labelPosition;
        if ($self) {
            $joined = new MermaidCurvePath($points[0]);
            foreach ($routed->points as $point) {
                $joined->line($point);
            }
            $joined->line($points[\count($points) - 1]);
            $routed = $joined;
        }

        // Zero rank spacing can make the two outline intersections coincide.
        return [\count($routed->points) < 2 ? $points : $routed->points, $labelPosition];
    }

    private static function contains(GraphNode $outer, GraphNode $inner): bool
    {
        return (\abs($inner->x - $outer->x) + ($inner->w / 2)) <= ($outer->w / 2) && (\abs($inner->y - $outer->y) + ($inner->h / 2)) <= ($outer->h / 2);
    }
}
