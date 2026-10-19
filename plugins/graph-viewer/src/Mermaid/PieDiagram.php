<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Ordered slices, retaining source values independently of their display geometry. */
final class PieDiagram
{
    /** @var array<array-key, array{label: string, value: float, text: string, origin: int}> */
    public array $slices = [];
    /** @var array<string, array{label: string, origin: int}> */
    public array $metadata = [];
    public bool $showData = false;
    public float $total = 0.0;
    public int $origin = 0;
}
