<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Data stays numeric and ordered; display geometry never changes the authored values. */
final class XyDiagram
{
    public string $orientation = 'vertical';
    /** Whether the header names the orientation, which then wins over `xyChart.chartOrientation`. */
    public bool $oriented = false;
    public int $origin = 0;
    /** @var array<string, array{label: string, origin: int}> */
    public array $metadata = [];
    /** @var ?list<string> */
    public ?array $categories = null;
    /** @var ?array{float, float} */
    public ?array $xRange = null;
    /** @var ?array{float, float} */
    public ?array $yRange = null;
    /** @var list<array{kind: 'bar'|'line', title: string, origin: int, values: list<array{value: float, label: string}>}> */
    public array $series = [];
}
