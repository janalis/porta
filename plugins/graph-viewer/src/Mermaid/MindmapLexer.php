<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Indentation belongs to the first line; quoted labels may contain whole lines. */
final readonly class MindmapLexer
{
    public function __construct(
        private string $source,
    ) {}

    /** @return \Generator<int, MermaidStatement> */
    public function statements(): \Generator
    {
        MermaidSource::validate($this->source);
        $offset = 0;
        $line = 0;
        $length = \strlen($this->source);
        while ($offset < $length) {
            $end = \strpos($this->source, needle: "\n", offset: $offset);
            $end = false === $end ? $length : $end;
            $first = \substr($this->source, $offset, $end - $offset);
            $text = \ltrim($first, characters: " \t\r");
            $indent = \strlen($first) - \strlen($text);
            $start = $offset + $indent;
            if ('' !== $text && !\str_starts_with($text, '%%')) {
                $end = $this->end($start, $end, $line, $indent);
                $text = \rtrim(\substr($this->source, $start, $end - $start));
                if (\strlen($text) > MermaidSource::MAX_STATEMENT) {
                    throw new MermaidSyntaxError('Statement exceeds the size limit', $start, $line, $indent);
                }
                yield new MermaidStatement($text, $start, $line, $indent);
            }
            $line += \substr_count($this->source, needle: "\n", offset: $offset, length: $end - $offset) + 1;
            $offset = $end + 1;
        }
    }

    private function end(int $start, int $end, int $line, int $indent): int
    {
        $match = [];
        $first = \substr($this->source, $start, $end - $start);
        if (1 !== \preg_match('/^[^\[(){}\n]*?(?:\(\(|\{\{|\)\)|-\)|\(-|[\[()])(["`])/', $first, $match)) {
            return $end;
        }
        $quote = $match[1];
        $quoteStart = $start + \strlen($match[0]) - 1;
        $close = \strpos($this->source, $quote, $quoteStart + 1);
        if (false === $close) {
            throw new MermaidSyntaxError('Unclosed mindmap label', $start, $line, $indent);
        }
        $newline = \strpos($this->source, needle: "\n", offset: $close + 1);

        return false === $newline ? \strlen($this->source) : $newline;
    }
}
