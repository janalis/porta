<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Gantt labels own apostrophes, hashes and semicolons; accessibility blocks own newlines. */
final readonly class GanttLexer
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
            $match = [];
            \preg_match('/\G([\t ]*)(accDescr\s*\{[^}]*\}|[^\r\n]*)(?:\r\n|\r|\n|$)/i', $this->source, $match, offset: $offset);
            $text = \rtrim($match[2]);
            if (!\str_starts_with(\strtolower($text), 'accdescr')) {
                $text = \rtrim(\preg_replace('/%%[^\n]*/', replacement: '', subject: $text) ?? $text);
            }
            $statement = new MermaidStatement($text, $offset + \strlen($match[1]), $line, \strlen($match[1]));
            if (\strlen($text) > MermaidSource::MAX_STATEMENT) {
                throw $statement->error('Statement exceeds the size limit');
            }
            if ('' !== $text) {
                yield $statement;
            }
            $offset += \strlen($match[0]);
            $line += \substr_count($match[0], needle: "\n");
        }
    }
}
