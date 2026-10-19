<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Raw declarations resolve only after final calendar directives and forward references are known. */
final class GanttDiagram
{
    public function __construct(
        public readonly GanttDate $calendar = new GanttDate(),
    ) {}

    /** @var list<array{id: string, label: string, section: string, tags: list<string>, start: ?string, end: string, statement: MermaidStatement}> */
    public array $raw = [];
    /** @var list<array{id: string, label: string, section: string, tags: list<string>, start: float, end: float, draw: ?float, origin: int}> */
    public array $tasks = [];
    /** @var list<string> */
    public array $excludes = [];
    /** @var list<string> */
    public array $includes = [];
    /** @var array<array-key, array{label: string, origin: int}> */
    public array $sections = [];
    /** @var array<string, array{label: string, origin: int}> */
    public array $metadata = [];
    public string $format = 'YYYY-MM-DD';
    public string $axis = '';
    public string $tick = '';
    public string $todayMarker = '';
    public string $weekday = 'sunday';
    public string $weekend = 'saturday';
    public bool $inclusive = false;
    public bool $topAxis = false;
    public int $origin = 0;
}
