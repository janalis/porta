<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Indentation belongs to the declaration; quoted labels and metadata may span whole lines. */
final readonly class KanbanLexer
{
    public function __construct(
        private string $source,
    ) {}

    /** @return \Generator<int, MermaidStatement> */
    public function statements(): \Generator
    {
        MermaidSource::validate($this->source);
        $length = \strlen($this->source);
        $offset = 0;
        $line = 0;
        while ($offset < $length) {
            $end = \strpos($this->source, needle: "\n", offset: $offset);
            $end = false === $end ? $length : $end;
            $first = \substr($this->source, $offset, $end - $offset);
            $text = \ltrim($first, characters: " \t\r");
            $indent = \strlen($first) - \strlen($text);
            $start = $offset + $indent;
            if ('' !== $text && !\str_starts_with($text, '%%')) {
                $end = $this->end($start, $line, $indent);
                yield new MermaidStatement(\rtrim(\substr($this->source, $start, $end - $start)), $start, $line, $indent);
            }
            $line += \substr_count($this->source, needle: "\n", offset: $offset, length: $end - $offset) + 1;
            $offset = $end + 1;
        }
    }

    private function end(int $start, int $line, int $indent): int
    {
        $quote = null;
        $metadata = false;
        $escaped = false;
        $length = \strlen($this->source);
        for ($index = $start; $index < $length; ++$index) {
            if (($index - $start) > MermaidSource::MAX_STATEMENT) {
                throw new MermaidSyntaxError('Statement exceeds the size limit', $start, $line, $indent);
            }
            $char = $this->source[$index];
            if (null !== $quote) {
                if ($char === $quote && !$escaped) {
                    $quote = null;
                }
                $escaped = '\\' === $char && !$escaped;
                continue;
            }
            if (!$metadata && '%%' === \substr($this->source, $index, length: 2)) {
                $end = \strpos($this->source, needle: "\n", offset: $index);

                $index = false === $end ? $length : $end;
                break;
            }
            if ('"' === $char || ($metadata && "'" === $char)) {
                $quote = $char;
                $escaped = false;
            }
            if ('@{' === \substr($this->source, $index, length: 2)) {
                $metadata = true;
            }
            if ($metadata && '}' === $char) {
                $metadata = false;
            }
            if ("\n" === $char && !$metadata) {
                break;
            }
        }
        if (null !== $quote || $metadata) {
            throw new MermaidSyntaxError('Unclosed Kanban label or metadata', $start, $line, $indent);
        }

        if (($index - $start) > MermaidSource::MAX_STATEMENT) {
            throw new MermaidSyntaxError('Statement exceeds the size limit', $start, $line, $indent);
        }

        return $index;
    }
}
