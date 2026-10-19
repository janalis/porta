<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Five named domains; later domain blocks replace earlier contents. */
final class CynefinDiagram
{
    public const array DOMAINS = ['complex', 'complicated', 'chaotic', 'clear', 'confusion'];

    public int $origin = 0;
    /** @var array<string, array{origin: int, items: list<array{label: string, origin: int}>}> */
    public array $domains = [];
    /** @var list<array{from: string, to: string, label: string, origin: int}> */
    public array $transitions = [];
    /** @var array<string, array{label: string, origin: int}> */
    public array $metadata = [];
}
