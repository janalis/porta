<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Named references retain insertion order independently from chronological commit sequence. */
final class GitDiagram
{
    /** @var array<array-key, array{id: string, custom: bool, message: string, tags: list<string>, type: string, customType: ?string, branch: string, parents: list<string>, seq: int, origin: int}> */
    public array $commits = [];
    /** @var array<array-key, array{tip: ?string, order: int, origin: int}> */
    public array $branches = ['main' => ['tip' => null, 'order' => 0, 'origin' => 0]];
    /** @var list<array{text: string, origin: int}> */
    public array $metadata = [];
    public string $current = 'main';
    public string $direction = 'LR';
    public int $origin = 0;
    public int $sequence = 0;
}
