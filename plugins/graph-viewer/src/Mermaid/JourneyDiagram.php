<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** A chronological task list with section identity and ordered actor memberships. */
final class JourneyDiagram
{
    /** @var list<array{label: string, origin: int}> */
    public array $sections = [];
    /** @var list<array{label: string, score: float, scoreText: string, actors: list<string>, section: ?int, origin: int}> */
    public array $tasks = [];
    /** @var array<string, array{label: string, origin: int}> */
    public array $metadata = [];
    public int $origin = 0;
}
