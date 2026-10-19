<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphNode;

/** Area-proportional circles use bounded deterministic pair-distance stress minimization. */
final class VennGeometry
{
    /** @return \Generator<int, null, mixed, array<array-key, GraphNode>> */
    public static function layout(VennDiagram $source): \Generator
    {
        $single = [];
        foreach ($source->subsets as $subset) {
            if ($subset['size'] < 0) {
                throw $subset['statement']->error('Venn sizes cannot be negative');
            }
            if (1 === \count($subset['sets'])) {
                $single[$subset['sets'][0]] = $subset;
            }
            yield null;
        }
        if (\count($single) > 16) {
            throw new MermaidSyntaxError('Native Venn supports at most sixteen circles', offset: 0, sourceLine: $source->origin, column: 0);
        }
        $areas = [];
        foreach ($source->subsets as $subset) {
            if (2 === \count($subset['sets'])) {
                $areas[VennDiagram::key($subset['sets'])] = $subset['size'];
            }
            yield null;
        }
        foreach ($source->subsets as $subset) {
            $ids = \array_values(\array_unique($subset['sets']));
            $count = \count($ids);
            if ($count < 3) {
                continue;
            }
            for ($i = 0; $i < ($count - 1); ++$i) {
                for ($j = $i + 1; $j < $count; ++$j) {
                    $key = VennDiagram::key([$ids[$i], $ids[$j]]);
                    $areas[$key] ??= \min($single[$ids[$i]]['size'], $single[$ids[$j]]['size']) / 4;
                    yield null;
                }
            }
        }
        $circles = [];
        $count = \count($single);
        foreach ($single as $id => $subset) {
            $id = $subset['sets'][0];
            $radius = \sqrt($subset['size'] / \M_PI);
            $index = \count($circles);
            $angle = (2 * \M_PI * $index) / \max(1, $count);
            $circles[$id] = new GraphNode($index, $id, '', 2 * $radius * \cos($angle), 2 * $radius * \sin($angle), 2 * $radius, 2 * $radius, null, null, dashed: false);
            yield null;
        }
        $pairs = [];
        $ids = \array_map(\strval(...), \array_keys($circles));
        for ($i = 0; $i < ($count - 1); ++$i) {
            for ($j = $i + 1; $j < $count; ++$j) {
                $a = $circles[$ids[$i]];
                $b = $circles[$ids[$j]];
                $key = VennDiagram::key(VennDiagram::identifiers([$ids[$i], $ids[$j]]));
                $area = $areas[$key] ?? 0.0;
                $pairs[] = ['from' => $ids[$i], 'to' => $ids[$j], 'distance' => self::distance($a->w / 2, $b->w / 2, $area), 'weight' => $area > 0 ? 1.0 : 0.2];
                yield null;
            }
        }
        $work = self::optimize($circles, $pairs);
        yield from $work;
        $circles = $work->getReturn();
        yield from self::normalize($circles);

        return $circles;
    }

    /** @param array<array-key, GraphNode> $circles
     * @return \Generator<int, null, mixed, void>
     */
    private static function normalize(array &$circles): \Generator
    {
        $radius = \max([0.0, ...\array_map(static fn (GraphNode $circle): float => $circle->w / 2, $circles)]);
        $scale = $radius > 0 ? 18 / $radius : 1.0;
        $left = \min([0.0, ...\array_map(static fn (GraphNode $circle): float => $circle->x - ($circle->w / 2), $circles)]);
        $top = \min([0.0, ...\array_map(static fn (GraphNode $circle): float => $circle->y - ($circle->h / 2), $circles)]);
        foreach ($circles as $id => $circle) {
            $circles[$id] = new GraphNode(
                $circle->id,
                $circle->name,
                '',
                (($circle->x - $left) * $scale) + 4,
                (($circle->y - $top) * $scale) + 4,
                $circle->w * $scale,
                $circle->h * $scale,
                null,
                null,
                dashed: false,
            );
            yield null;
        }
    }

    public static function overlap(float $a, float $b, float $distance): float
    {
        if ($distance >= ($a + $b)) {
            return 0.0;
        }

        return $distance <= \abs($a - $b) ? \M_PI * (\min($a, $b) ** 2) : self::lens($a, $b, $distance);
    }

    private static function lens(float $a, float $b, float $distance): float
    {
        $square = $distance ** 2;
        $first = \acos(\max(-1.0, \min(1.0, ($square + $a ** 2 - $b ** 2) / (2 * $distance * $a))));
        $second = \acos(\max(-1.0, \min(1.0, ($square + $b ** 2 - $a ** 2) / (2 * $distance * $b))));
        $radical = (-$distance + $a + $b) * ($distance + $a - $b) * ($distance - $a + $b) * ($distance + $a + $b);

        return (($a ** 2) * $first) + (($b ** 2) * $second) - (0.5 * \sqrt(\max(0.0, $radical)));
    }

    public static function distance(float $a, float $b, float $area): float
    {
        return match (true) {
            $area <= 0 => $a + $b,
            $area >= (\M_PI * (\min($a, $b) ** 2)) => \abs($a - $b),
            default => self::inverse($a, $b, $area),
        };
    }

    private static function inverse(float $a, float $b, float $area): float
    {
        $low = \abs($a - $b);
        $high = $a + $b;
        for ($index = 0; $index < 48; ++$index) {
            $middle = ($low + $high) / 2;
            if (self::overlap($a, $b, $middle) > $area) {
                $low = $middle;
                continue;
            }
            $high = $middle;
        }

        return ($low + $high) / 2;
    }

    /** @param array<array-key, GraphNode> $circles
     * @param list<array{from: string, to: string, distance: float, weight: float}> $pairs
     * @return \Generator<int, null, mixed, array<array-key, GraphNode>>
     */
    private static function optimize(array $circles, array $pairs): \Generator
    {
        $count = \count($circles);
        for ($iteration = 0; $iteration < 240 && [] !== $pairs; ++$iteration) {
            $gradient = [];
            foreach ($pairs as $pair) {
                $a = $circles[$pair['from']];
                $b = $circles[$pair['to']];
                $dx = $a->x - $b->x;
                $dy = $a->y - $b->y;
                $length = \max(1.0e-9, \hypot($dx, $dy));
                $factor = (2 * $pair['weight'] * ($length - $pair['distance'])) / $length;
                foreach ([[$pair['from'], 1], [$pair['to'], -1]] as [$id, $sign]) {
                    $gradient[$id] ??= [0.0, 0.0];
                    $gradient[$id][0] += $sign * $factor * $dx;
                    $gradient[$id][1] += $sign * $factor * $dy;
                }
                yield null;
            }
            $rate = 0.5 / \max(1, $count);
            foreach ($gradient as $id => [$dx, $dy]) {
                $circle = $circles[$id];
                $circles[$id] = $circle->at($circle->x - ($rate * $dx), $circle->y - ($rate * $dy));
                yield null;
            }
        }

        return $circles;
    }
}
