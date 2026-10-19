<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Captions own their semicolons; ordinary statements retain Mermaid's semicolon separators. */
final readonly class QuadrantLexer
{
    public function __construct(
        private string $source,
    ) {}

    /** @return \Generator<int, MermaidStatement> */
    public function statements(): \Generator
    {
        foreach (new MermaidLexer($this->source, chart: true)->statements() as $line) {
            if (\strlen($line->text) > MermaidSource::MAX_STATEMENT) {
                throw $line->error('Statement exceeds the size limit');
            }
            if (1 === \preg_match('/^(?:title\b|accTitle\s*:|accDescr\s*[:{])/i', $line->text)) {
                yield $this->caption($line);
                continue;
            }
            try {
                foreach (new MermaidLexer($line->text)->statements() as $part) {
                    yield new MermaidStatement($part->text, $line->offset + $part->offset, $line->line + $part->line, 0 === $part->line ? $line->column + $part->column : $part->column);
                }
            } catch (MermaidSyntaxError $error) {
                throw new MermaidSyntaxError(
                    'Invalid quadrant statement',
                    $line->offset + $error->offset,
                    $line->line + $error->sourceLine,
                    0 === $error->sourceLine ? $line->column + $error->column : $error->column,
                );
            }
        }
    }

    private function caption(MermaidStatement $statement): MermaidStatement
    {
        if (1 === \preg_match('/^accDescr\s*\{/i', $statement->text)) {
            return $statement;
        }
        $end = \strpos($this->source, needle: "\n", offset: $statement->offset);
        $text = \rtrim(\substr($this->source, $statement->offset, (false === $end ? \strlen($this->source) : $end) - $statement->offset));
        if (\strlen($text) > MermaidSource::MAX_STATEMENT) {
            throw $statement->error('Statement exceeds the size limit');
        }

        return new MermaidStatement($text, $statement->offset, $statement->line, $statement->column);
    }
}
