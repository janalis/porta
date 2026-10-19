<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** XY arrays are flat; quotes own commas and semicolons, accessibility captions own their lines. */
final readonly class XyLexer
{
    public function __construct(
        private string $source,
    ) {}

    /** @return \Generator<int, MermaidStatement> */
    public function statements(): \Generator
    {
        MermaidSource::validate($this->source);
        $quote = new MermaidQuotedText();
        $start = null;
        $startLine = 0;
        $startColumn = 0;
        $line = 0;
        $column = 0;
        $bracket = false;
        $caption = false;
        $description = false;
        $length = \strlen($this->source);
        for ($index = 0; $index < $length; ++$index) {
            $char = $this->source[$index];
            if (!$quote->quoted && !$caption && !$description && '%%' === \substr($this->source, $index, length: 2)) {
                if (null !== $start) {
                    yield $this->statement($start, $index, $startLine, $startColumn);
                    $start = null;
                }
                $end = \strpos($this->source, needle: "\n", offset: $index);
                $index = false === $end ? $length : $end - 1;
                continue;
            }
            if (null === $start && ';' !== $char && !\ctype_space($char)) {
                $start = $index;
                $startLine = $line;
                $startColumn = $column;
                $caption = 1 === \preg_match('/\G(?:accTitle|accDescr)[\t ]*:/i', $this->source, offset: $index);
                $description = 1 === \preg_match('/\GaccDescr[\t ]*\{/i', $this->source, offset: $index);
            }
            if (null !== $start && ($index - $start) > MermaidSource::MAX_STATEMENT) {
                throw new MermaidSyntaxError('Statement exceeds the size limit', $start, $startLine, $startColumn);
            }
            if (!$caption && !$description) {
                $quote->advance($char);
                if (!$quote->quoted && '[' === $char) {
                    if ($bracket) {
                        throw new MermaidSyntaxError('Nested XY arrays are not supported', $index, $line, $column);
                    }
                    $bracket = true;
                }
                if (!$quote->quoted && ']' === $char) {
                    if (!$bracket) {
                        throw new MermaidSyntaxError('Unexpected XY closing bracket', $index, $line, $column);
                    }
                    $bracket = false;
                }
            }
            $closedDescription = $description && '}' === $char;
            if (null !== $start && !$quote->quoted && (("\n" === $char && !$description) || (';' === $char && !$caption && !$description && !$bracket) || $closedDescription)) {
                if ($bracket) {
                    throw new MermaidSyntaxError('Unclosed XY array', $start, $startLine, $startColumn);
                }
                yield $this->statement($start, $closedDescription ? $index + 1 : $index, $startLine, $startColumn);
                $start = null;
                $caption = false;
                $description = false;
            }
            if ("\n" === $char) {
                ++$line;
                $column = 0;
                continue;
            }
            $column += 0x80 === (\ord($char[0]) & 0xC0) ? 0 : 1;
        }
        if ($quote->quoted || $description || $bracket) {
            throw new MermaidSyntaxError('Unclosed XY label, description or array', $start ?? $length, $startLine, $startColumn);
        }
        if (null !== $start) {
            yield $this->statement($start, $length, $startLine, $startColumn);
        }
    }

    private function statement(int $start, int $end, int $line, int $column): MermaidStatement
    {
        $text = \rtrim(\substr($this->source, $start, $end - $start));
        if (\strlen($text) > MermaidSource::MAX_STATEMENT) {
            throw new MermaidSyntaxError('Statement exceeds the size limit', $start, $line, $column);
        }

        return new MermaidStatement($text, $start, $line, $column);
    }
}
