<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Source-ordered axes and curves, with references resolved after the entire document is parsed. */
final class RadarDiagram
{
    public int $origin = 0;
    /** @var list<array{name: string, label: string, origin: int}> */
    public array $axes = [];
    /** @var list<array{name: string, label: string, origin: int, entries: list<array{axis: ?string, value: float, origin: int}>}> */
    public array $curves = [];
    /** @var array<string, array{label: string, origin: int}> */
    public array $metadata = [];
    public bool $showLegend = true;
    public int $ticks = 5;
    public ?float $maximum = null;
    public float $minimum = 0.0;
    public string $graticule = 'circle';
}
