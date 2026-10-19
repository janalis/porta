<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Coordinates are normalized to unit visibility/evolution before placement. */
final class WardleyDiagram
{
    /** @var array<array-key, array{label: string, visibility: float, evolution: float, origin: int, kind: string, dx: float, dy: float, strategy: string, inertia: bool}> */
    public array $nodes = [];
    /** @var list<array{from: string, to: string, flow: string, label: string, dashed: bool, origin: int}> */
    public array $links = [];
    /** @var array<array-key, list<string>> */
    public array $pipelines = [];
    /** @var list<array{parent: string, items: list<array{name: string, evolution: float, dx: float, dy: float, origin: int}>, statement: MermaidStatement}> */
    public array $pendingPipelines = [];
    /** @var array<array-key, array{name: string, target: float, origin: int}> */
    public array $trends = [];
    /** @var list<array{kind: string, label: string, visibility: float, evolution: float, origin: int}> */
    public array $annotations = [];
    /** @var list<array{text: string, origin: int}> */
    public array $metadata = [];
    /** @var list<array{label: string, boundary: ?float}> */
    public array $stages = [];
    public float $width = 110.0;
    public float $height = 60.0;
    public int $origin = 0;
}
