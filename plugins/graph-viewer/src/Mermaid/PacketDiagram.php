<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Contiguous authored fields; layout splits them into configured words without losing source identity. */
final class PacketDiagram
{
    public const int BITS_PER_ROW = 32;
    public const int MAX_BITS = self::BITS_PER_ROW * MermaidGraph::MAX_ITEMS;

    public function __construct(
        public readonly int $bitsPerRow = self::BITS_PER_ROW,
    ) {}

    public int $origin = 0;
    /** @var array<string, array{label: string, origin: int}> */
    public array $metadata = [];
    /** @var list<array{start: int, end: int, label: string, origin: int}> */
    public array $fields = [];
}
