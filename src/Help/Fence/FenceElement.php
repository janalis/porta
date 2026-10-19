<?php

declare(strict_types=1);

namespace Porta\Help\Fence;

/** A provider's semantic target: plain text, body-relative source line and normalized silhouette. */
final readonly class FenceElement
{
    /** @param list<array{float, float}> $polygon */
    public function __construct(
        public string $id,
        public string $label,
        public int $origin,
        public array $polygon,
    ) {
        if ($origin < 0 || '' === $id || \strlen($id) > 512 || \strlen($label) > 131_072 || \count($polygon) < 3 || \count($polygon) > 512) {
            throw new \InvalidArgumentException('Invalid fence semantic element');
        }
        foreach ($polygon as [$x, $y]) {
            if ($x < 0 || $x > 1 || $y < 0 || $y > 1 || !\is_finite($x) || !\is_finite($y)) {
                throw new \InvalidArgumentException('Fence element coordinates must be normalized finite numbers');
            }
        }
    }

    /** @return list<array{int, int}> half-open display-column spans at one image row's center */
    public function spans(float $y, int $width): array
    {
        $crossings = [];
        $previous = $this->polygon[\count($this->polygon) - 1];
        foreach ($this->polygon as [$bx, $by]) {
            [$ax, $ay] = $previous;
            if ($ay > $y !== $by > $y) {
                $crossings[] = $ax + ((($bx - $ax) * ($y - $ay)) / ($by - $ay));
            }
            $previous = [$bx, $by];
        }
        \sort($crossings, flags: \SORT_NUMERIC);
        $spans = [];
        $count = \count($crossings);
        for ($index = 0; ($index + 1) < $count; $index += 2) {
            $left = \max(0, (int) \floor($crossings[$index] * $width));
            $right = \min($width, (int) \ceil($crossings[$index + 1] * $width));
            if ($left < $right) {
                $spans[] = [$left, $right];
            }
        }

        return $spans;
    }
}
