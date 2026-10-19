<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/**
 * roughjs 4.6.6 (MIT; resources/licenses/roughjs.txt), as the pinned hand-drawn look calls it:
 * every line is two bowed cubic strokes, and a fill is hachure lines at -41°, two pixels apart.
 * Coordinates are pixels. Each shape restarts the seeded generator, as each roughjs call does.
 *
 * Operations are lists of numbers: a move holds two, a cubic curve six.
 */
final readonly class MermaidRough
{
    /** Pinned `hachureGap: 1.5`, which roughjs rounds to whole pixels. */
    private const int GAP = 2;
    /** roughjs `hachureAngle` plus the quarter turn its filler adds. */
    private const float ANGLE = 49.0;
    private const float OFFSET = 2.0;
    private const float BOWING = 1.0;

    public function __construct(
        private int $seed,
        private float $roughness,
    ) {}

    /**
     * A closed shape of three points or more, filled: roughjs draws the outline first, closed
     * back to the start, then the hachure from the same generator.
     *
     * @param list<array{float, float}> $points
     * @return array{list<list<float>>, list<list<float>>} the hachure, then the outline
     */
    public function polygon(array $points): array
    {
        $random = new MermaidRoughRandom($this->seed);
        $outline = $this->path([...$points, $points[0]], $random);
        $fill = [];
        foreach (self::hachure($points) as [[$x1, $y1], [$x2, $y2]]) {
            \array_push($fill, ...$this->doubled($x1, $y1, $x2, $y2, $random));
        }

        return [$fill, $outline];
    }

    /**
     * An open path: roughjs `linearPath`.
     *
     * @param list<array{float, float}> $points
     * @return list<list<float>>
     */
    public function line(array $points): array
    {
        return $this->path($points, new MermaidRoughRandom($this->seed));
    }

    /**
     * Operations as polylines, each cubic flattened by its control polygon's length.
     *
     * @param list<list<float>> $operations
     * @return list<list<array{float, float}>>
     */
    public static function polylines(array $operations, float $scale): array
    {
        $lines = [];
        $line = [];
        [$x, $y] = [0.0, 0.0];
        foreach ($operations as $operation) {
            if (2 === \count($operation)) {
                if (\count($line) > 1) {
                    $lines[] = $line;
                }
                [$x, $y] = $operation;
                $line = [[$x / $scale, $y / $scale]];
                continue;
            }
            [$ax, $ay, $bx, $by, $cx, $cy] = $operation;
            $length = \hypot($ax - $x, $ay - $y) + \hypot($bx - $ax, $by - $ay) + \hypot($cx - $bx, $cy - $by);
            $steps = \max(2, \min(12, (int) \ceil($length / 12)));
            for ($step = 1; $step <= $steps; ++$step) {
                $t = $step / $steps;
                $u = 1 - $t;
                $line[] = [
                    (($u * $u * $u * $x) + (3 * $u * $u * $t * $ax) + (3 * $u * $t * $t * $bx) + ($t * $t * $t * $cx)) / $scale,
                    (($u * $u * $u * $y) + (3 * $u * $u * $t * $ay) + (3 * $u * $t * $t * $by) + ($t * $t * $t * $cy)) / $scale,
                ];
            }
            [$x, $y] = [$cx, $cy];
        }
        if (\count($line) > 1) {
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * roughjs `linearPath` and `polygon` outlines: each segment doubled.
     *
     * @param list<array{float, float}> $points
     * @return list<list<float>>
     */
    private function path(array $points, MermaidRoughRandom $random): array
    {
        $count = \count($points);
        $operations = [];
        for ($index = 1; $index < $count; ++$index) {
            \array_push($operations, ...$this->doubled($points[$index - 1][0], $points[$index - 1][1], $points[$index][0], $points[$index][1], $random));
        }

        return $operations;
    }

    /**
     * roughjs `doubleLine`: a wide stroke, then a tighter overlay.
     *
     * @return list<list<float>>
     */
    private function doubled(float $x1, float $y1, float $x2, float $y2, MermaidRoughRandom $random): array
    {
        return [...$this->stroke($x1, $y1, $x2, $y2, $random, overlay: false), ...$this->stroke($x1, $y1, $x2, $y2, $random, overlay: true)];
    }

    /**
     * roughjs `_line`: one bowed cubic from a jittered start to a jittered end, its randomness
     * damped on long lines.
     *
     * @return list<list<float>>
     */
    // @mago-expect lint:no-boolean-flag-parameter -- The overlay pass is roughjs's own second argument; both passes share every other step.
    private function stroke(float $x1, float $y1, float $x2, float $y2, MermaidRoughRandom $random, bool $overlay): array
    {
        $squared = ($x1 - $x2) ** 2 + ($y1 - $y2) ** 2;
        $length = \sqrt($squared);
        $damping = match (true) {
            $length < 200 => 1.0,
            $length > 500 => 0.4,
            default => (-0.001_666_8 * $length) + 1.233_334,
        };
        $offset = (self::OFFSET * self::OFFSET * 100) > $squared ? $length / 10 : self::OFFSET;
        $jitter = $overlay ? $offset / 2 : $offset;
        $divergence = 0.2 + (0.2 * $random->next());
        $bowX = $this->offset((self::BOWING * self::OFFSET * ($y2 - $y1)) / 200, $random, $damping);
        $bowY = $this->offset((self::BOWING * self::OFFSET * ($x1 - $x2)) / 200, $random, $damping);
        $move = [$x1 + $this->offset($jitter, $random, $damping), $y1 + $this->offset($jitter, $random, $damping)];

        return [
            $move,
            [
                $bowX + $x1 + (($x2 - $x1) * $divergence) + $this->offset($jitter, $random, $damping),
                $bowY + $y1 + (($y2 - $y1) * $divergence) + $this->offset($jitter, $random, $damping),
                $bowX + $x1 + (2 * ($x2 - $x1) * $divergence) + $this->offset($jitter, $random, $damping),
                $bowY + $y1 + (2 * ($y2 - $y1) * $divergence) + $this->offset($jitter, $random, $damping),
                $x2 + $this->offset($jitter, $random, $damping),
                $y2 + $this->offset($jitter, $random, $damping),
            ],
        ];
    }

    /** roughjs `_offsetOpt`: a uniform offset in ±range, scaled by roughness. */
    private function offset(float $range, MermaidRoughRandom $random, float $damping): float
    {
        return $this->roughness * $damping * (($random->next() * ($range - -$range)) + -$range);
    }

    /**
     * roughjs `polygonHachureLines`: the shape turned so the hatching runs level, scanned one
     * pixel at a time through an active edge table, every second scanline kept, turned back.
     *
     * @param list<array{float, float}> $points
     * @return list<array{array{float, float}, array{float, float}}>
     */
    private static function hachure(array $points): array
    {
        $turned = \array_map(static fn (array $point): array => self::turned($point[0], $point[1], self::ANGLE), $points);
        if ($turned[0] !== $turned[\count($turned) - 1]) {
            $turned[] = $turned[0];
        }
        $edges = [];
        $count = \count($turned);
        for ($index = 1; $index < $count; ++$index) {
            [$from, $to] = [$turned[$index - 1], $turned[$index]];
            if ($from[1] !== $to[1]) {
                $low = \min($from[1], $to[1]);
                $edges[] = ['low' => $low, 'high' => \max($from[1], $to[1]), 'x' => $low === $from[1] ? $from[0] : $to[0], 'slope' => ($to[0] - $from[0]) / ($to[1] - $from[1])];
            }
        }
        // roughjs breaks ties by x and then the high end, but the active table sorts by x on
        // every scanline, so only the low end changes which lines are drawn.
        \usort($edges, static fn (array $a, array $b): int => $a['low'] <=> $b['low']);
        $lines = [];
        $active = [];
        $y = $edges[0]['low'] ?? 0.0;
        for ($scan = 0; [] !== $active || [] !== $edges; ++$scan) {
            while ([] !== $edges && $edges[0]['low'] <= $y) {
                $active[] = \array_shift($edges);
            }
            $active = \array_values(\array_filter($active, static fn (array $edge): bool => $edge['high'] > $y));
            \usort($active, static fn (array $a, array $b): int => $a['x'] <=> $b['x']);
            for ($pair = 1; 0 === ($scan % self::GAP) && $pair < \count($active); $pair += 2) {
                $lines[] = [self::turned(self::round($active[$pair - 1]['x']), $y, -self::ANGLE), self::turned(self::round($active[$pair]['x']), $y, -self::ANGLE)];
            }
            ++$y;
            foreach (\array_keys($active) as $index) {
                $active[$index]['x'] += $active[$index]['slope'];
            }
        }

        return $lines;
    }

    /** @return array{float, float} the point turned about the origin */
    private static function turned(float $x, float $y, float $degrees): array
    {
        $radians = (\M_PI / 180) * $degrees;

        return [($x * \cos($radians)) - ($y * \sin($radians)), ($x * \sin($radians)) + ($y * \cos($radians))];
    }

    /** JavaScript `Math.round`: halves round up. */
    private static function round(float $value): float
    {
        return \floor($value + 0.5);
    }
}
