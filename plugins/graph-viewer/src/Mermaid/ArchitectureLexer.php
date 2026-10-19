<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Complete label/string tokens shield comments; physical lines delimit the remaining grammar. */
final readonly class ArchitectureLexer
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
            $indent = \strspn($this->source, characters: " \t", offset: $offset);
            $start = $offset + $indent;
            $end = $this->end($start, $line, $indent);
            $text = \rtrim(\substr($this->source, $start, $end - $start));
            if ('' !== $text) {
                $statement = new MermaidStatement($text, $start, $line, $indent);
                $match = [];
                if (1 === \preg_match('/^architecture-beta\s+(?=(?:title|accTitle|accDescr)\b)/', $text, $match)) {
                    yield $statement->slice(0, length: 17);
                    yield $statement->slice(\strlen($match[0]));
                }
                if ([] === $match) {
                    yield $statement;
                }
            }
            $next = \strpos($this->source, needle: "\n", offset: $end);
            $next = false === $next ? $length : $next + 1;
            $line += \substr_count(\substr($this->source, $offset, $next - $offset), needle: "\n");
            $offset = $next;
        }
    }

    private function end(int $start, int $line, int $column): int
    {
        $length = \strlen($this->source);
        $statement = new MermaidStatement('', $start, $line, $column);
        $metadata = 1 === \preg_match('/\G(?:architecture-beta\s+)?(?:title|accTitle\s*:|accDescr\s*:)/', $this->source, offset: $start);
        for ($offset = $start; $offset < $length; ++$offset) {
            if (($offset - $start) > MermaidSource::MAX_STATEMENT) {
                throw $statement->error('Architecture statement exceeds the size limit');
            }
            if ("\n" === $this->source[$offset] || '%%' === \substr($this->source, $offset, length: 2)) {
                return $offset;
            }
            $match = [];
            if (!$metadata && 1 === \preg_match('/\G(?:'.ArchitectureText::TITLE.'|'.MermaidQuotedString::PATTERN.'|accDescr\s*\{[^}]*\})/', $this->source, $match, offset: $offset)) {
                $offset += \strlen($match[0]) - 1;
                if (($offset - $start) > MermaidSource::MAX_STATEMENT) {
                    throw $statement->error('Architecture statement exceeds the size limit');
                }
            }
        }

        return $length;
    }
}
