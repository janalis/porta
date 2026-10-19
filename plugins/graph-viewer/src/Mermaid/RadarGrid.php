<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphEdge;

/** Grid circles and smooth curves are bounded sampled polylines shared by all native backends. */
final class RadarGrid
{
    /** @return array{float, float} */
    public static function point(float $fraction, int $axis, int $count, float $centerY): array
    {
        $angle = ((2 * \M_PI * $axis) / \max(1, $count)) - (\M_PI / 2);

        return [104.0 + (60 * $fraction * \cos($angle)), $centerY + (60 * $fraction * \sin($angle))];
    }

    /**
     * @param float $scale Pinned `radar.axisScaleFactor`: axis length as a share of the outer graticule.
     * @param int $fill Pinned `radar.graticuleOpacity` as a percentage: each ring fills its area.
     * @return \Generator<int, null, mixed, list<GraphEdge>>
     */
    public static function edges(RadarDiagram $source, float $centerY, float $scale = 1.0, int $fill = 30): \Generator
    {
        $edges = [];
        $count = \count($source->axes);
        $steps = 'circle' === $source->graticule ? 64 : $count;
        for ($tick = 1; $tick <= $source->ticks; ++$tick) {
            $points = [];
            for ($axis = 0; $axis < $steps; ++$axis) {
                $points[] = self::point($tick / $source->ticks, $axis, $steps, $centerY);
                yield null;
            }
            if ([] !== $points) {
                $points[] = $points[0];
                $edges[] = self::edge($points, '@radar.graticuleColor@'.$fill, filled: true);
                $edges[] = self::edge($points, '@radar.graticuleColor');
            }
        }
        foreach ($source->axes as $axis => $item) {
            $edges[] = self::edge([[104.0, $centerY], self::point($scale, $axis, $count, $centerY)], '@radar.axisColor');
            yield null;
        }

        return $edges;
    }

    /** @param list<array{float, float}> $points
     * @return \Generator<int, null, mixed, list<array{float, float}>>
     */
    public static function smooth(array $points, float $tension = 0.17): \Generator
    {
        $sampled = [];
        $count = \count($points);
        foreach ($points as $i => $p1) {
            $p0 = $points[($i + $count - 1) % $count];
            $p2 = $points[($i + 1) % $count];
            $p3 = $points[($i + 2) % $count];
            $a = [$p1[0] + ($tension * ($p2[0] - $p0[0])), $p1[1] + ($tension * ($p2[1] - $p0[1]))];
            $b = [$p2[0] - ($tension * ($p3[0] - $p1[0])), $p2[1] - ($tension * ($p3[1] - $p1[1]))];
            for ($step = 0; $step < 8; ++$step) {
                $t = $step / 8;
                $u = 1 - $t;
                $sampled[] = [
                    (($u ** 3) * $p1[0]) + (3 * ($u ** 2) * $t * $a[0]) + (3 * $u * ($t ** 2) * $b[0]) + (($t ** 3) * $p2[0]),
                    (($u ** 3) * $p1[1]) + (3 * ($u ** 2) * $t * $a[1]) + (3 * $u * ($t ** 2) * $b[1]) + (($t ** 3) * $p2[1]),
                ];
                yield null;
            }
        }
        $sampled[] = $points[0];

        return $sampled;
    }

    /** @param list<array{float, float}> $points */
    // @mago-expect lint:no-boolean-flag-parameter -- A filled path is the same path with its area painted, as pinned SVG paths are.
    public static function edge(array $points, string $color, bool $filled = false): GraphEdge
    {
        if ([] === $points) {
            throw new \InvalidArgumentException('Radar paths require at least one point');
        }
        $xs = \array_column($points, column_key: 0);
        $ys = \array_column($points, column_key: 1);

        return new GraphEdge(0, 0, $points, null, \min($xs), \min($ys), \max($xs), \max($ys), $color, null, null, dashed: false, ribbon: $filled ? $points : null, decoration: $filled);
    }
}
