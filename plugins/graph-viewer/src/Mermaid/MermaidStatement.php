<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** A lexical statement and its byte position in the original document. */
final readonly class MermaidStatement
{
    public function __construct(
        public string $text,
        public int $offset,
        public int $line,
        public int $column,
    ) {}

    public function slice(int $relative, ?int $length = null): self
    {
        [$line, $column] = $this->location($relative);

        return new self(\substr($this->text, $relative, $length), $this->offset + $relative, $line, $column);
    }

    public function error(string $message, int $relative = 0): MermaidSyntaxError
    {
        [$line, $column] = $this->location($relative);

        return new MermaidSyntaxError($message, $this->offset + $relative, $line, $column);
    }

    /** @return array{int, int} */
    private function location(int $relative): array
    {
        $before = \substr($this->text, offset: 0, length: $relative);
        $newlines = \substr_count($before, needle: "\n");
        $last = \strrpos($before, needle: "\n");
        $column = false === $last ? $this->column + \mb_strlen($before) : \mb_strlen(\substr($before, $last + 1));

        return [$this->line + $newlines, $column];
    }
}
