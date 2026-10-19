<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Normalization avoids overflowing the span of large but finite endpoints. */
final class XyScale
{
    /** @param array{float, float} $range */
    public static function fraction(float $value, array $range): float
    {
        [$start, $end] = $range;

        return match (true) {
            $start === $end => 0.5,
            $value <= \min($start, $end) => $start < $end ? 0.0 : 1.0,
            $value >= \max($start, $end) => $start < $end ? 1.0 : 0.0,
            default => self::interior($value, $start, $end),
        };
    }

    private static function interior(float $value, float $start, float $end): float
    {
        $magnitude = \max(\abs($start), \abs($end), \abs($value), 1.0);
        $span = ($end / $magnitude) - ($start / $magnitude);
        $fraction = (($value / $magnitude) - ($start / $magnitude)) / $span;

        return \max(0.0, \min(1.0, $fraction));
    }

    /** @param array{float, float} $range */
    public static function value(float $fraction, array $range): float
    {
        return ((1 - $fraction) * $range[0]) + ($fraction * $range[1]);
    }
}
