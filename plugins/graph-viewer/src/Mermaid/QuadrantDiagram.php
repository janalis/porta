<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Authored coordinates remain in the unit square; styles are resolved after forward class definitions. */
final class QuadrantDiagram
{
    public int $origin = 0;
    /** @var array<string, array{label: string, origin: int}> */
    public array $labels = [];
    /** @var list<array{label: string, x: float, y: float, class: string, style: array<string, float|string>, origin: int}> */
    public array $points = [];
    /** @var array<array-key, array<string, float|string>> */
    public array $classes = [];
}
