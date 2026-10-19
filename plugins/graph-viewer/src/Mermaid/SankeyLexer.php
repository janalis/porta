<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** CSV records retain quoted newlines and doubled quotes; punctuation is data rather than Mermaid syntax. */
final readonly class SankeyLexer
{
    public function __construct(
        private string $source,
    ) {}

    /** @return \Generator<int, MermaidStatement> */
    public function statements(): \Generator
    {
        MermaidSource::validate($this->source);
        $start = 0;
        $startLine = 0;
        $line = 0;
        $quoted = false;
        $length = \strlen($this->source);
        for ($index = 0; $index <= $length; ++$index) {
            if (($index - $start) > MermaidSource::MAX_STATEMENT) {
                throw new MermaidSyntaxError('CSV record exceeds the size limit', $start, $startLine, column: 0);
            }
            $char = $this->source[$index] ?? '';
            if (!$quoted && '%%' === \substr($this->source, $index, length: 2) && '' === \trim(\substr($this->source, $start, $index - $start))) {
                $end = \strpos($this->source, needle: "\n", offset: $index);
                $index = false === $end ? $length : $end;
                $line += false === $end ? 0 : 1;
                $start = $index + 1;
                $startLine = $line;
                continue;
            }
            if ('"' === $char) {
                $quoted = !$quoted;
            }
            if ($index === $length && $quoted) {
                throw new MermaidSyntaxError('Unclosed Sankey CSV field', $start, $startLine, column: 0);
            }
            if (("\n" === $char && !$quoted) || $index === $length) {
                $record = \substr($this->source, $start, $index - $start);
                $trimmed = \ltrim($record, characters: " \t\r");
                $indent = \strlen($record) - \strlen($trimmed);
                if ('' !== \trim($trimmed)) {
                    yield new MermaidStatement(\rtrim($trimmed), $start + $indent, $startLine, $indent);
                }
                $start = $index + 1;
                $startLine = $line + 1;
            }
            $line += "\n" === $char ? 1 : 0;
        }
    }
}
