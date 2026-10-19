<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Timeline declarations retain source order, including repeated and empty sections. */
final class TimelineDiagram
{
    public string $direction = 'LR';
    public int $origin = 0;
    /** @var array<string, array{label: string, origin: int}> */
    public array $metadata = [];
    /** @var list<array{label: string, origin: int}> */
    public array $sections = [];
    /** @var list<array{label: string, origin: int, section: ?int, events: list<array{label: string, origin: int}>}> */
    public array $periods = [];
}
