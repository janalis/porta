<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Sparse diagrams and large text must not allocate an unbounded full-height source-row map. */
final class MermaidOrigins
{
    /** @return non-empty-list<int> */
    public static function rows(float $height, int $origin = 0): array
    {
        if (!\is_finite($height) || $height < 0 || $height > 1_000_000) {
            throw new \InvalidArgumentException('Native diagram exceeds its layout height limit');
        }

        return \array_fill(0, count: \max(1, (int) \ceil($height / 2)), value: $origin);
    }
}
