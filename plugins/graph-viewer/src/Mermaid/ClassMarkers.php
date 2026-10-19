<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphEdge;

/** UML glyphs share the scene's line/fill primitives and never enter its selection map. */
final class ClassMarkers
{
    /** @return list<GraphEdge> */
    public static function forEdge(GraphEdge $edge, string $left, string $right): array
    {
        $result = [];
        $length = MermaidPolyline::length($edge->points);
        foreach ([[$left, $edge->points], [$right, \array_reverse($edge->points)]] as [$kind, $from]) {
            if ('' === $kind || $length < 1.0e-9) {
                continue;
            }
            // Curved edges are many short segments: aim along the line, three units in.
            $at = $from[0];
            [$toward] = MermaidPolyline::along($from, \min($length, 3.0));
            $dx = $toward[0] - $at[0];
            $dy = $toward[1] - $at[1];
            $length = \hypot($dx, $dy);
            $scale = \min(1.0, MermaidPolyline::length($edge->points) / 8);
            $points = self::outline($kind);
            foreach ($points as $i => [$along, $across]) {
                $points[$i] = [$at[0] + (($scale * (($along * $dx) - ($across * $dy))) / $length), $at[1] + (($scale * (($along * $dy) + ($across * $dx))) / $length)];
            }
            $xs = \array_column($points, 0);
            $ys = \array_column($points, 1);
            $result[] = new GraphEdge(
                $edge->tail,
                $edge->head,
                $points,
                null,
                \min($xs),
                \min($ys),
                \max($xs),
                \max($ys),
                $edge->color,
                null,
                null,
                dashed: false,
                ribbon: '*' === $kind ? $points : null,
                strokeWidth: $edge->strokeWidth,
                decoration: true,
            );
        }

        return $result;
    }

    /** Stop the shaft at hollow glyph bases, so their interiors stay empty. */
    public static function shaft(GraphEdge $edge, string $left, string $right): GraphEdge
    {
        $points = $edge->points;
        $scale = \min(1.0, MermaidPolyline::length($points) / 8);
        foreach ([$left, $right] as $end => $kind) {
            $depth = match ($kind) {
                '<|', '|>' => 2.4,
                '*', 'o' => 3.0,
                '()' => 2.0,
                default => 0.0,
            };
            if (($depth * $scale) <= 0.0) {
                continue;
            }
            $ordered = 0 === $end ? $points : \array_reverse($points);
            [$cut, $rest] = MermaidPolyline::along($ordered, $depth * $scale);
            $trimmed = [$cut, ...$rest];
            $points = 0 === $end ? $trimmed : \array_reverse($trimmed);
        }

        return new GraphEdge(
            $edge->tail,
            $edge->head,
            $points,
            null,
            $edge->minX,
            $edge->minY,
            $edge->maxX,
            $edge->maxY,
            $edge->color,
            $edge->label,
            $edge->labelPos,
            $edge->dashed,
            strokeWidth: $edge->strokeWidth,
            textColor: $edge->textColor,
            decoration: $edge->decoration,
            font: $edge->font,
            fontScale: $edge->fontScale,
            dashes: $edge->dashes,
        );
    }

    /** @return list<array{float, float}> */
    private static function outline(string $kind): array
    {
        if ('()' === $kind) {
            $circle = [];
            for ($i = 0; $i <= 24; ++$i) {
                $angle = (2 * \M_PI * $i) / 24;
                $circle[] = [1.0 + \cos($angle), \sin($angle)];
            }

            return $circle;
        }

        return match ($kind) {
            '<|', '|>' => [[0.0, 0.0], [2.4, -1.2], [2.4, 1.2], [0.0, 0.0]],
            '*', 'o' => [[0.0, 0.0], [1.5, -1.0], [3.0, 0.0], [1.5, 1.0], [0.0, 0.0]],
            default => [[2.0, -1.0], [0.0, 0.0], [2.0, 1.0]],
        };
    }
}
