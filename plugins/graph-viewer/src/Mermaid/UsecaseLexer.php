<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Physical-line grammar with explicit multiline Markdown, metadata, JSON and accessibility bodies. */
final readonly class UsecaseLexer
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
            $end = '%%' === \substr($this->source, $start, length: 2)
                ? $start + \strcspn($this->source, characters: "\r\n", offset: $start)
                : $this->end($start, $line, $indent);
            $text = \rtrim(\substr($this->source, $start, $end - $start));
            if ('' !== $text && !\str_starts_with($text, '%%')) {
                yield new MermaidStatement($text, $start, $line, $indent);
            }
            $next = $end < $length ? $end + 1 : $end;
            if ("\r" === ($this->source[$end] ?? '') && "\n" === ($this->source[$end + 1] ?? '')) {
                ++$next;
            }
            $line += (int) \preg_match_all('/\r\n|\r|\n/', \substr($this->source, $offset, $next - $offset));
            $offset = $next;
        }
    }

    private function end(int $start, int $line, int $column): int
    {
        $quote = new UsecaseQuotedText();
        $json = 1 === \preg_match('/\Gjson\s+'.UsecaseLabels::IDENTIFIER.'\s*@\{/', $this->source, offset: $start);
        if ($json) {
            $quote->json();
        }
        $description = 1 === \preg_match('/\GaccDescr\s*\{/', $this->source, offset: $start);
        $descriptionEnd = $description ? \strpos($this->source, needle: '}', offset: $start) : -1;
        if (false === $descriptionEnd) {
            throw new MermaidStatement('', $start, $line, $column)->error('Unclosed use-case accessibility description');
        }
        $depth = 0;
        $length = \strlen($this->source);
        $statement = new MermaidStatement('', $start, $line, $column);
        for ($offset = $start; $offset < $length; ++$offset) {
            $char = $this->source[$offset];
            if (($offset - $start) > MermaidSource::MAX_STATEMENT) {
                throw $statement->error('Statement exceeds the size limit');
            }
            if ($descriptionEnd >= $offset) {
                continue;
            }
            if ('' !== $char && \str_contains("\r\n", $char)) {
                if ($quote->quoted() && !$quote->multiline()) {
                    throw $statement->error('A plain use-case string cannot cross a physical line');
                }
                if (0 === $depth && !$quote->multiline()) {
                    return $offset;
                }
            }
            if (!$quote->advance($char, $this->source[$offset + 1] ?? '')) {
                continue;
            }
            if ('{' === $char) {
                ++$depth;
                if ($depth > 128) {
                    throw $statement->error('Use-case metadata exceeds the nesting limit');
                }
            }
            if ('}' === $char && --$depth < 0) {
                throw $statement->error('Unexpected use-case closing delimiter');
            }
        }
        if ($quote->quoted() || $depth > 0) {
            throw new MermaidStatement('', $start, $line, $column)->error('Unclosed use-case string or metadata');
        }

        return $length;
    }
}
