<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphFont;

/**
 * A Markdown string label (`"`…`"`) as the pinned HTML label path draws it: `**strong**` and
 * `__strong__` bold, `*em*` and `_em_` italic, nested emphasis bold italic, `\` escapes, and
 * line breaks from newlines and `<br>`. Emphasis follows CommonMark's delimiter rules, as
 * marked does: a run opens before text and closes after it, and `_` never works inside a word.
 */
final readonly class MermaidMarkdown
{
    /** Pinned labels wrap at `wrappingWidth` pixels; a 16px label averages 7.5 pixels a character. */
    private const float CHARACTER = 7.5;

    /** @param list<list<array{string, GraphFont}>> $lines styled runs per line */
    private function __construct(
        public array $lines,
    ) {}

    /** The Markdown body of a backtick-quoted label, or null for any other label. */
    public static function body(string $raw): ?string
    {
        $text = \trim($raw);
        if (\str_starts_with($text, '"') && \str_ends_with($text, '"') && \strlen($text) >= 2) {
            $text = \substr($text, offset: 1, length: -1);
        }

        return \strlen($text) >= 2 && \str_starts_with($text, '`') && \str_ends_with($text, '`') ? \substr($text, offset: 1, length: -1) : null;
    }

    public static function parse(string $markdown): self
    {
        $text = \preg_replace('~<br\s*/?>~i', replacement: "\n", subject: $markdown) ?? $markdown;
        $text = \preg_replace('/\n{2,}/', replacement: "\n", subject: $text) ?? $text;
        $lines = [];
        // Pinned breaks drop the indentation after each newline (`\n *` becomes `<br/>`).
        foreach (\explode("\n", $text) as $line) {
            $lines[] = self::inline(\ltrim($line, characters: ' '));
        }

        return new self($lines);
    }

    /** The label's text without its markup, one line per visual line. */
    public function plain(): string
    {
        return \implode("\n", \array_map(static fn (array $runs): string => \implode('', \array_map(static fn (array $run): string => $run[0], $runs)), $this->lines));
    }

    /**
     * Pinned `markdownAutoWrap`: words wrap greedily at the width a `wrappingWidth` label holds;
     * a word longer than a line keeps its own line unbroken.
     */
    public function wrap(float $wrappingWidth): self
    {
        $limit = \max(1, (int) \floor($wrappingWidth / self::CHARACTER));
        $lines = [];
        foreach ($this->lines as $runs) {
            $characters = [];
            foreach ($runs as [$text, $font]) {
                foreach (\mb_str_split($text) as $character) {
                    $characters[] = [$character, $font];
                }
            }
            foreach (self::breaks($characters, $limit) as [$from, $to]) {
                $lines[] = self::merged(\array_slice($characters, $from, $to - $from));
            }
        }

        return new self($lines);
    }

    /**
     * Greedy line ranges over a line's characters: each range ends after the last word that
     * fits, and the spaces that break a line are dropped.
     *
     * @param list<array{string, GraphFont}> $characters
     * @return list<array{int, int}>
     */
    private static function breaks(array $characters, int $limit): array
    {
        /** @var list<array{int, int}> $words */
        $words = [];
        $start = -1;
        foreach ([...$characters, [' ', GraphFont::Regular]] as $index => [$character]) {
            if (' ' !== $character) {
                $start = $start < 0 ? $index : $start;
                continue;
            }
            if ($start >= 0) {
                $words[] = [$start, $index];
                $start = -1;
            }
        }
        /** @var list<array{int, int}> $ranges */
        $ranges = [];
        foreach ($words as [$from, $to]) {
            $previous = \array_pop($ranges);
            if (null !== $previous && ($to - $previous[0]) <= $limit) {
                $ranges[] = [$previous[0], $to];
                continue;
            }
            if (null !== $previous) {
                $ranges[] = $previous;
            }
            $ranges[] = [$from, $to];
        }

        return [] === $ranges ? [[0, 0]] : $ranges;
    }

    /**
     * Adjacent runs of one face joined.
     *
     * @param list<array{string, GraphFont}> $runs
     * @return list<array{string, GraphFont}>
     */
    private static function merged(array $runs): array
    {
        /** @var list<array{string, GraphFont}> $merged */
        $merged = [];
        foreach ($runs as [$text, $font]) {
            $previous = \array_pop($merged);
            if (null !== $previous && $previous[1] === $font) {
                $merged[] = [$previous[0].$text, $font];
                continue;
            }
            if (null !== $previous) {
                $merged[] = $previous;
            }
            $merged[] = [$text, $font];
        }

        return $merged;
    }

    /**
     * One line's emphasis: CommonMark's delimiter-run algorithm over `*` and `_`.
     *
     * @return list<array{string, GraphFont}>
     */
    private static function inline(string $line): array
    {
        [$tokens, $delimiters] = self::tokens($line);
        $styles = \array_fill(0, \count($tokens), value: [false, false]);
        foreach (\array_keys($delimiters) as $closer) {
            while ($delimiters[$closer]['count'] > 0 && $delimiters[$closer]['close']) {
                $opener = self::opener($delimiters, $closer);
                if (null === $opener) {
                    break;
                }
                $strong = $delimiters[$opener]['count'] >= 2 && $delimiters[$closer]['count'] >= 2;
                for ($index = $delimiters[$opener]['slot'] + 1; $index < $delimiters[$closer]['slot']; ++$index) {
                    $styles[$index][$strong ? 0 : 1] = true;
                }
                $used = $strong ? 2 : 1;
                $delimiters[$opener]['count'] -= $used;
                $delimiters[$closer]['count'] -= $used;
                // Inner delimiters left unmatched between the pair are text now.
                foreach (\array_keys($delimiters) as $index) {
                    if ($index <= $opener || $index >= $closer) {
                        continue;
                    }
                    $delimiters[$index]['open'] = false;
                    $delimiters[$index]['close'] = false;
                }
            }
        }
        $runs = [];
        foreach ($tokens as $index => $token) {
            $delimiter = \array_find($delimiters, static fn (array $delimiter): bool => $index === $delimiter['slot']);
            $text = null === $delimiter ? $token : \str_repeat($delimiter['char'], \max(0, $delimiter['count']));
            if ('' !== $text) {
                $runs[] = [$text, self::font($styles[$index])];
            }
        }

        return self::merged($runs);
    }

    /**
     * @param array<int, array{char: string, count: int, original: int, open: bool, close: bool, slot: int}> $delimiters
     */
    private static function opener(array $delimiters, int $closer): ?int
    {
        for ($index = $closer - 1; $index >= 0; --$index) {
            $candidate = $delimiters[$index];
            $both = $candidate['close'] || $delimiters[$closer]['open'];
            // CommonMark's rule of three keeps `*a**b*` from pairing the wrong runs.
            $three = $both && 0 === (($candidate['original'] + $delimiters[$closer]['original']) % 3) && (0 !== ($candidate['original'] % 3) || 0 !== ($delimiters[$closer]['original'] % 3));
            if ($candidate['open'] && $candidate['count'] > 0 && $candidate['char'] === $delimiters[$closer]['char'] && !$three) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Text tokens and delimiter runs; an escaped character is plain text.
     *
     * @return array{list<string>, array<int, array{char: string, count: int, original: int, open: bool, close: bool, slot: int}>}
     */
    private static function tokens(string $line): array
    {
        $tokens = [''];
        $delimiters = [];
        $characters = \mb_str_split($line);
        $count = \count($characters);
        for ($index = 0; $index < $count; ++$index) {
            $character = $characters[$index];
            if ('\\' === $character && ($index + 1) < $count && 1 === \preg_match('/[[:punct:]]/', $characters[$index + 1])) {
                $tokens[\count($tokens) - 1] .= $characters[++$index];
                continue;
            }
            if ('*' !== $character && '_' !== $character) {
                $tokens[\count($tokens) - 1] .= $character;
                continue;
            }
            $length = 1;
            while (($index + $length) < $count && $characters[$index + $length] === $character) {
                ++$length;
            }
            $before = $characters[$index - 1] ?? ' ';
            $after = $characters[$index + $length] ?? ' ';
            [$open, $close] = self::flanking($character, $before, $after);
            $tokens[] = \str_repeat($character, $length);
            $delimiters[] = ['char' => $character, 'count' => $length, 'original' => $length, 'open' => $open, 'close' => $close, 'slot' => \count($tokens) - 1];
            $tokens[] = '';
            $index += $length - 1;
        }

        return [$tokens, $delimiters];
    }

    /** @return array{bool, bool} whether a delimiter run can open and can close emphasis */
    private static function flanking(string $character, string $before, string $after): array
    {
        $space = static fn (string $text): bool => 1 === \preg_match('/^\s$/u', $text);
        $punctuation = static fn (string $text): bool => 1 === \preg_match('/^[\p{P}\p{S}]$/u', $text);
        $left = !$space($after) && (!$punctuation($after) || $space($before) || $punctuation($before));
        $right = !$space($before) && (!$punctuation($before) || $space($after) || $punctuation($after));
        if ('*' === $character) {
            return [$left, $right];
        }

        return [$left && (!$right || $punctuation($before)), $right && (!$left || $punctuation($after))];
    }

    /** @param array{bool, bool} $style bold, italic */
    private static function font(array $style): GraphFont
    {
        return match ($style) {
            [true, true] => GraphFont::BoldItalic,
            [true, false] => GraphFont::Bold,
            [false, true] => GraphFont::Italic,
            default => GraphFont::Regular,
        };
    }
}
