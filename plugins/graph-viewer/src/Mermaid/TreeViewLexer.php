<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Bare filenames own punctuation; only a leading quote opens a multiline label. */
final readonly class TreeViewLexer
{
    public function __construct(
        private string $source,
    ) {}

    /** @return \Generator<int, MermaidStatement> */
    public function statements(): \Generator
    {
        MermaidSource::validate($this->source);
        $branches = new TreeViewBranches($this->source);
        $offset = 0;
        $line = 0;
        $length = \strlen($this->source);
        while ($offset < $length) {
            $end = \strpos($this->source, needle: "\n", offset: $offset);
            $end = false === $end ? $length : $end;
            $raw = \substr($this->source, $offset, $end - $offset);
            $text = \ltrim($raw, characters: " \t\r");
            $indent = \strlen($raw) - \strlen($text);
            $start = $offset + $indent;
            if ('' !== $text && !\str_starts_with($text, '%%')) {
                $end = $this->end($start, $end, $line, $indent);
                $body = \rtrim(\substr($this->source, $start, $end - $start));
                if (1 === \preg_match('/^(?:treeView-beta\b|title(?:[\t ]|$)|accTitle\s*:|accDescr\s*:)/i', $body)) {
                    $body = \rtrim(\preg_replace('/%%[^\r\n]*$/', replacement: '', subject: $body) ?? $body);
                }
                $statement = new MermaidStatement($body, $start, $line, $indent);
                if (1 !== \preg_match('/^(?:treeView-beta$|title(?:[\t ]|$)|accTitle\s*:|accDescr\s*[:{])/i', $body)) {
                    $statement = $branches->item($statement);
                }
                if (null !== $statement) {
                    yield $statement;
                }
            }
            $line += \substr_count($this->source, needle: "\n", offset: $offset, length: $end - $offset) + 1;
            $offset = $end + 1;
        }
    }

    private function end(int $start, int $end, int $line, int $indent): int
    {
        $match = [];
        $quoteStart = $start;
        if (1 === \preg_match('/\G[│┃\t ]*[├└┣┗][─━]*[\t ]*/u', $this->source, $match, offset: $start)) {
            $quoteStart += \strlen($match[0]);
        }
        $opening = $this->source[$quoteStart] ?? '';

        $close = false;
        if ('"' === $opening || "'" === $opening) {
            $close = \strpos($this->source, $opening, $quoteStart + 1);
            if (false === $close) {
                throw new MermaidSyntaxError('Unclosed tree-view label', $start, $line, $indent);
            }
        }
        if (false === $close && 1 === \preg_match('/\GaccDescr[\t ]*\{/i', $this->source, $match, offset: $start)) {
            $close = \strpos($this->source, needle: '}', offset: $start + \strlen($match[0]));
            if (false === $close) {
                throw new MermaidSyntaxError('Unclosed accessibility description', $start, $line, $indent);
            }
        }
        if (false !== $close && $close >= $end) {
            $end = \strpos($this->source, needle: "\n", offset: $close);
            $end = false === $end ? \strlen($this->source) : $end;
        }
        if (($end - $start) > MermaidSource::MAX_STATEMENT) {
            throw new MermaidSyntaxError('Statement exceeds the size limit', $start, $line, $indent);
        }

        return $end;
    }
}
