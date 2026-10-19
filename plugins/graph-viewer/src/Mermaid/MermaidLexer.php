<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Statement lexer: quoted/nested labels own their separators; comments do not. */
final readonly class MermaidLexer
{
    private const int MAX_NESTING = 128;

    public function __construct(
        private string $source,
        private bool $textual = false,
        private bool $chart = false,
    ) {}

    /** @return \Generator<int, MermaidStatement> */
    public function statements(): \Generator
    {
        MermaidSource::validate($this->source);
        $line = 0;
        $column = 0;
        $start = null;
        $startLine = 0;
        $startColumn = 0;
        $stack = [];
        $quote = new MermaidQuotedText($this->chart ? "\"'" : '"');
        $edgeText = false;
        $entityEnd = -1;
        $description = false;
        $caption = false;
        $configuration = false;
        $configurationText = new MermaidConfigurationText();
        $configurationOffset = -1;
        $configurationLine = -1;
        $length = \strlen($this->source);
        for ($i = 0; $i < $length; ++$i) {
            $char = $this->source[$i];
            $entityEnd = $this->entityEnd($i, $entityEnd);
            if (!$configuration && (!$caption || $this->chart) && !$description && !$quote->quoted && !$edgeText && $this->commentAt($i, $entityEnd)) {
                if (null !== $start) {
                    yield $this->statement($start, $i, $startLine, $startColumn);
                    $start = null;
                }
                $end = \strpos($this->source, needle: "\n", offset: $i);
                $i = false === $end ? $length : $end - 1;
                continue;
            }
            if (null === $start && ($this->chart || ';' !== $char) && !\ctype_space($char)) {
                $start = $i;
                $startLine = $line;
                $startColumn = $column;
                $caption = $this->caption($i);
                $description = $this->description($i);
                $configurationOffset = $this->configurationOffset($i);
            }
            if (null !== $start && ($i - $start) > MermaidSource::MAX_STATEMENT) {
                throw $this->statement($start, $i, $startLine, $startColumn)->error('Statement exceeds the size limit');
            }
            if ($this->configurationAt($i, $configurationOffset, $configuration, $quote->quoted, $edgeText, $stack)) {
                $configuration = true;
                $configurationLine = $line;
                $configurationText = new MermaidConfigurationText();
            }
            $structural = false;
            if ((!$this->textual && !$caption && !$description) || $configuration) {
                if (!$configuration) {
                    $quote->advance($char);
                }
                $structural = $configuration ? self::configurationCharacter($configurationText, $char, $line === $configurationLine) : !$quote->quoted;
                if (!$this->chart && $structural && [] === $stack && '|' === $char) {
                    $edgeText = !$edgeText;
                }
                if (!$this->chart && $structural && !$edgeText) {
                    $this->delimiter($char, $stack, $i, $line, $column);
                }
            }
            if ($configuration && '}' === $char && $structural && [] === $stack) {
                $configuration = false;
            }
            if ("\n" === $char) {
                $caption = false;
            }
            if ($description && '}' === $char) {
                $description = false;
            }
            if (null !== $start && !$description && !$quote->quoted && !$edgeText && [] === $stack && $this->separator($char, $i, $entityEnd, $caption)) {
                yield $this->statement($start, $i, $startLine, $startColumn);
                $start = null;
            }
            if ("\n" === $char) {
                ++$line;
                $column = 0;
                continue;
            }
            $column += 0x80 === (\ord($char[0]) & 0xC0) ? 0 : 1;
        }
        self::finish($description, $quote->quoted || [] !== $stack, $edgeText, $start ?? $length, $startLine, $startColumn);
        if (null !== $start) {
            yield $this->statement($start, $length, $startLine, $startColumn);
        }
    }

    // @mago-expect lint:no-boolean-flag-parameter -- These flags describe unfinished lexer constructs at EOF.
    private static function finish(bool $description, bool $unclosed, bool $edgeText, int $offset, int $line, int $column): void
    {
        if ($description) {
            throw new MermaidSyntaxError('Unclosed accessibility description', $offset, $line, $column);
        }
        if ($unclosed || $edgeText) {
            throw new MermaidSyntaxError($edgeText ? 'Unclosed |' : 'Unclosed label or delimiter', $offset, $line, $column);
        }
    }

    /** @param list<string> $stack */
    private function configurationAt(int $offset, int $known, bool $active, bool $quoted, bool $edgeText, array $stack): bool
    {
        return $offset === $known || (!$active && !$quoted && !$edgeText && [] === $stack && $this->inlineMetadata($offset));
    }

    private static function configurationCharacter(MermaidConfigurationText $text, string $char, bool $openingLine): bool
    {
        $structural = $text->advance($char);

        // The pinned grammar closes opening-line metadata before YAML sees its
        // comment. Comments on subsequent metadata lines retain native shielding.
        return $structural || ($openingLine && '}' === $char && $text->comment);
    }

    private function commentAt(int $offset, int $entityEnd): bool
    {
        return ($this->textual && '#' === $this->source[$offset] && $offset > $entityEnd) || '%%' === \substr($this->source, $offset, length: 2);
    }

    private function inlineMetadata(int $offset): bool
    {
        return !$this->textual && !$this->chart && '@' === $this->source[$offset] && '{' === ($this->source[$offset + 1] ?? '');
    }

    private function caption(int $offset): bool
    {
        if ($this->chart) {
            return 1 === \preg_match('/\G(?:pie(?:[\t ]+showData)?[\t ]+)?(?:title\b|accTitle\s*:|accDescr\s*:)/i', $this->source, offset: $offset);
        }

        return $this->textual && 1 === \preg_match('/\G(?:accTitle|accDescr)\s*:/i', $this->source, offset: $offset);
    }

    private function separator(string $char, int $offset, int $entityEnd, bool $caption): bool
    {
        return "\n" === $char || (!$caption && ';' === $char && !$this->chart && $offset !== $entityEnd);
    }

    private function description(int $offset): bool
    {
        $pattern = $this->chart ? '/\G(?:pie(?:[\t ]+showData)?[\t ]+)?accDescr\s*\{/i' : '/\GaccDescr\s*\{/i';

        return ($this->textual || $this->chart) && 1 === \preg_match($pattern, $this->source, offset: $offset);
    }

    private function configurationOffset(int $start): int
    {
        if (!$this->textual) {
            return -1;
        }
        // Locate the opener once per statement: copying its growing prefix on every
        // character makes long message bodies quadratic, even without configuration.
        $match = [];
        $pattern = '/\G(?:(?:create\s+)?(?:participant|actor)\s+[^@\r\n;]*\K@(?=\{)|(?:links|properties)\s+[^:\r\n;]+:\s*\K\{)/i';

        return 1 === \preg_match($pattern, $this->source, $match, flags: \PREG_OFFSET_CAPTURE, offset: $start) ? (int) $match[0][1] : -1;
    }

    private function entityEnd(int $offset, int $previous): int
    {
        $match = [];
        if ($this->textual && '#' === $this->source[$offset] && 1 === \preg_match('/\G#[\w]++;/u', $this->source, $match, offset: $offset)) {
            return $offset + \strlen($match[0]) - 1;
        }

        return $previous;
    }

    /** @param list<string> $stack */
    private function delimiter(string $char, array &$stack, int $offset, int $line, int $column): void
    {
        if ('>' === $char && [] === $stack && !\str_contains('-=.<>', $this->source[$offset - 1] ?? '-')) {
            $stack[] = '[';

            return;
        }
        if (\str_contains('[({', $char)) {
            $stack[] = $char;
            if (\count($stack) > self::MAX_NESTING) {
                throw new MermaidSyntaxError('Nesting exceeds the depth limit', $offset, $line, $column);
            }

            return;
        }
        if (\str_contains('])}', $char)) {
            $open = \array_pop($stack);
            if (\strtr($char, [']' => '[', ')' => '(', '}' => '{']) !== $open) {
                throw new MermaidSyntaxError('Unexpected closing delimiter', $offset, $line, $column);
            }
        }
    }

    private function statement(int $start, int $end, int $line, int $column): MermaidStatement
    {
        return new MermaidStatement(\rtrim(\substr($this->source, $start, $end - $start)), $start, $line, $column);
    }
}
