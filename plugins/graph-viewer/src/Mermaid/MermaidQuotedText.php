<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Shared quote/escape state for statement boundaries and shape-label scanning. */
final class MermaidQuotedText
{
    public private(set) bool $quoted = false;
    private bool $escaped = false;
    private string $delimiter = '"';

    public function __construct(
        private readonly string $quotes = '"',
    ) {}

    public function advance(string $char): void
    {
        match (true) {
            $this->escaped => $this->escaped = false,
            $this->quoted && '\\' === $char => $this->escaped = true,
            !$this->quoted && \str_contains($this->quotes, $char) => $this->open($char),
            $this->quoted && $this->delimiter === $char => $this->quoted = false,
            default => null,
        };
    }

    private function open(string $char): void
    {
        $this->delimiter = $char;
        $this->quoted = true;
    }
}
