<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** One sampled path feeds terminal lines, SVG and PNG; its cursor retains even merged points. */
final class MermaidCurvePath
{
    /** @var list<array{float, float}> */
    public private(set) array $points = [];
    /** @var array{float, float} */
    private array $cursor = [0.0, 0.0];

    /** @param array{float, float}|null $start */
    public function __construct(?array $start = null)
    {
        if (null !== $start) {
            $this->line($start);
        }
    }

    /** @param array{float, float} $point */
    public function line(array $point): void
    {
        if (!\is_finite($point[0]) || !\is_finite($point[1])) {
            throw new \InvalidArgumentException('Native curve contains a non-finite coordinate');
        }
        $previous = \array_last($this->points);
        if (null === $previous || \hypot($point[0] - $previous[0], $point[1] - $previous[1]) > 1e-12) {
            $this->points[] = $point;
        }
        $this->cursor = $point;
    }

    /**
     * @param array{float, float} $first
     * @param array{float, float} $second
     * @param array{float, float} $end
     */
    public function cubic(array $first, array $second, array $end): void
    {
        $start = $this->cursor;
        for ($step = 1; $step <= 32; ++$step) {
            $t = $step / 32;
            $u = 1 - $t;
            $this->line([
                (($u ** 3) * $start[0]) + (3 * $u * $u * $t * $first[0]) + (3 * $u * $t * $t * $second[0]) + (($t ** 3) * $end[0]),
                (($u ** 3) * $start[1]) + (3 * $u * $u * $t * $first[1]) + (3 * $u * $t * $t * $second[1]) + (($t ** 3) * $end[1]),
            ]);
        }
    }

    /**
     * @param array{float, float} $control
     * @param array{float, float} $end
     */
    public function quadratic(array $control, array $end): void
    {
        $start = $this->cursor;
        for ($step = 1; $step <= 32; ++$step) {
            $t = $step / 32;
            $u = 1 - $t;
            $this->line([
                ($u * $u * $start[0]) + (2 * $u * $t * $control[0]) + ($t * $t * $end[0]),
                ($u * $u * $start[1]) + (2 * $u * $t * $control[1]) + ($t * $t * $end[1]),
            ]);
        }
    }

    /** @return array{float, float}|null */
    public function middle(): ?array
    {
        $middle = \array_first($this->points);
        $length = 0.0;
        $count = \count($this->points);
        for ($index = 1; $index < $count; ++$index) {
            $length += self::distance($this->points[$index - 1], $this->points[$index]);
        }
        $remaining = $length / 2;
        for ($index = 1; $index < $count; ++$index) {
            $from = $this->points[$index - 1];
            $to = $this->points[$index];
            $distance = self::distance($from, $to);
            if ($remaining <= $distance) {
                $ratio = $remaining / $distance;
                $middle = [$from[0] + (($to[0] - $from[0]) * $ratio), $from[1] + (($to[1] - $from[1]) * $ratio)];
                break;
            }
            $remaining -= $distance;
        }

        return $middle;
    }

    /**
     * @param array{float, float} $from
     * @param array{float, float} $to
     */
    private static function distance(array $from, array $to): float
    {
        return \hypot($to[0] - $from[0], $to[1] - $from[1]);
    }
}
