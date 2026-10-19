<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphEdge;

/**
 * Pinned Cynefin folds: a dashed vertical and horizontal boundary, seven seeded cubic
 * wobbles each, and the cliff down the lower half of the fold, all over the native field.
 * Amounts the pinned build gives in pixels of its 800-pixel field scale to the native one.
 */
final class CynefinBoundaries
{
    /** The pinned field width the amplitude and cliff amounts are given against. */
    private const float PINNED_WIDTH = 800.0;
    private const int SEGMENTS = 7;
    private const int SAMPLES = 12;

    /**
     * Pinned `resolveSeed`: an authored nonzero seed, else the hash of the picture's id, which
     * a CLI export names `my-svg`.
     */
    public static function seed(float $seed): int
    {
        return 0.0 !== $seed && \is_finite($seed) ? (int) $seed : self::hash('my-svg');
    }

    /**
     * The two boundaries and the cliff, crossing at (`$x`, `$y`) within the field's edges.
     *
     * @return list<GraphEdge>
     */
    public static function edges(float $left, float $top, float $right, float $bottom, float $x, float $y, int $seed, float $amplitude): array
    {
        $scale = ($right - $left) / self::PINNED_WIDTH;
        $d = $amplitude * $scale;
        $vertical = [];
        $horizontal = [];
        for ($index = 0; $index <= self::SEGMENTS; ++$index) {
            $vertical[] = [$x + (self::random($seed + ($index * 17)) * $d * 2) - $d, $top + ((($bottom - $top) * $index) / self::SEGMENTS)];
            $horizontal[] = [$left + ((($right - $left) * $index) / self::SEGMENTS), $y + (self::random($seed + 100 + ($index * 23)) * $d * 2) - $d];
        }
        $fold = [$vertical[0]];
        $level = [$horizontal[0]];
        for ($index = 0; $index < self::SEGMENTS; ++$index) {
            $sign = 0 === ($index % 2) ? 1 : -1;
            [$a, $b] = [$vertical[$index], $vertical[$index + 1]];
            $g = $d * 1.5 * $sign * self::random($seed + ($index * 31) + 7);
            $middle = ($a[1] + $b[1]) / 2;
            \array_push($fold, ...self::cubic($a, [$a[0] + $g, $middle], [$b[0] - $g, $middle], $b));
            [$a, $b] = [$horizontal[$index], $horizontal[$index + 1]];
            $g = $d * 1.5 * $sign * self::random($seed + 100 + ($index * 37) + 11);
            $middle = ($a[0] + $b[0]) / 2;
            \array_push($level, ...self::cubic($a, [$middle, $a[1] + $g], [$middle, $b[1] - $g], $b));
        }
        // Pinned `generateCliffPath`: two cubics from the crossing down to the bottom edge.
        $c = ($right - $left) * 0.03;
        $span = $bottom - $y;
        $cliff = [
            [$x, $y],
            ...self::cubic([$x, $y], [$x + $c, $y + ($span * 0.2)], [$x - ($c * 1.5), $y + ($span * 0.55)], [$x + ($c * 0.5), $y + ($span * 0.75)]),
            ...self::cubic([$x + ($c * 0.5), $y + ($span * 0.75)], [$x - $c, $y + ($span * 0.85)], [$x + ($c * 0.3), $y + ($span * 0.95)], [$x, $bottom]),
        ];

        return [
            self::line($fold, '@cynefin.boundaryColor', 0.2, [0.6, 0.3]),
            self::line($level, '@cynefin.boundaryColor', 0.2, [0.6, 0.3]),
            self::line($cliff, '@cynefin.cliffColor', 0.4, null),
        ];
    }

    /** Pinned `seededRandom`, a mulberry32 step over 32-bit integer arithmetic. */
    public static function random(int $seed): float
    {
        $e = self::int32($seed + 1_831_565_813);
        $e = self::imul($e ^ self::shift($e, 15), $e | 1);
        $e ^= self::int32($e + self::imul($e ^ self::shift($e, 7), $e | 61));

        return (($e ^ self::shift($e, 14)) & 0xFFFF_FFFF) / 4_294_967_296;
    }

    /** Pinned `hashString`, Java's string hash in 32 bits. */
    public static function hash(string $text): int
    {
        $hash = 0;
        foreach (\mb_str_split($text) as $character) {
            $hash = self::int32(($hash << 5) - $hash + \mb_ord($character));
        }

        return $hash;
    }

    /**
     * @param array{float, float} $a
     * @param array{float, float} $b
     * @param array{float, float} $c
     * @param array{float, float} $d
     * @return list<array{float, float}> the curve after its start
     */
    private static function cubic(array $a, array $b, array $c, array $d): array
    {
        $points = [];
        for ($step = 1; $step <= self::SAMPLES; ++$step) {
            $t = $step / self::SAMPLES;
            $u = 1 - $t;
            $points[] = [
                (($u ** 3) * $a[0]) + (3 * $u * $u * $t * $b[0]) + (3 * $u * $t * $t * $c[0]) + (($t ** 3) * $d[0]),
                (($u ** 3) * $a[1]) + (3 * $u * $u * $t * $b[1]) + (3 * $u * $t * $t * $c[1]) + (($t ** 3) * $d[1]),
            ];
        }

        return $points;
    }

    /**
     * @param list<array{float, float}> $points
     * @param ?list<float> $dashes
     */
    private static function line(array $points, string $color, float $width, ?array $dashes): GraphEdge
    {
        $xs = \array_column($points, 0);
        $ys = \array_column($points, 1);

        return new GraphEdge(0, 0, $points, null, \min($xs), \min($ys), \max($xs), \max($ys), $color, null, null, dashed: null !== $dashes, strokeWidth: $width, decoration: true, dashes: $dashes);
    }

    private static function int32(int $value): int
    {
        $value &= 0xFFFF_FFFF;

        return $value >= 0x8000_0000 ? $value - 0x1_0000_0000 : $value;
    }

    /** JavaScript's `>>>`: an unsigned shift of the low 32 bits. */
    private static function shift(int $value, int $bits): int
    {
        return ($value & 0xFFFF_FFFF) >> $bits;
    }

    /** JavaScript's `Math.imul`, kept within 64-bit integers by multiplying 16-bit halves. */
    private static function imul(int $a, int $b): int
    {
        $a &= 0xFFFF_FFFF;
        $b &= 0xFFFF_FFFF;
        $low = ($a & 0xFFFF) * $b;
        $high = ((($a >> 16) * ($b & 0xFFFF)) & 0xFFFF) << 16;

        return self::int32($low + $high);
    }
}
