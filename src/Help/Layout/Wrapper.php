<?php

declare(strict_types=1);

namespace Porta\Help\Layout;

use Porta\Editor\TextMetrics;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * Word-wraps styled fragments into page lines: greedy packing, a word too
 * wide for any line cut at a glyph boundary, a hanging indent for the lines
 * after the first. A link split across lines keeps one group, so Tab still
 * focuses it as one; every word keeps the source bytes it was written in.
 */
final readonly class Wrapper
{
    /** The bytes one column wide each: printable ASCII. */
    private const string PRINTABLE = " !\"#$%&'()*+,-./0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[\\]^_`abcdefghijklmnopqrstuvwxyz{|}~";

    public function __construct(
        private StylePen $pen,
    ) {}

    /**
     * @param list<Fragment> $fragments
     *
     * @return list<PageLine>
     */
    public function wrap(array $fragments, int $width, PageLine $first, PageLine $rest): array
    {
        $lines = [];
        $prefix = $first;
        foreach ($this->breakLines($fragments) as $part) {
            $words = self::toWords($part, $width - \max($rest->width, 2));
            foreach (self::packWords($words, $width, $prefix->width, $rest->width) as $lineWords) {
                $lines[] = $this->line($lineWords)->after($prefix);
                $prefix = $rest;
            }
        }

        return $lines;
    }

    /**
     * The fragments between hard breaks, each a run of lines of its own.
     *
     * @param list<Fragment> $fragments
     *
     * @return list<list<Fragment>>
     */
    private function breakLines(array $fragments): array
    {
        $parts = [[]];
        foreach ($fragments as $fragment) {
            if ($fragment->isBreak) {
                $parts[] = [];
                continue;
            }
            $parts[\count($parts) - 1][] = $fragment;
        }

        return $parts;
    }

    /**
     * Fragments split into words, a word wider than $room cut into pieces.
     *
     * @param list<Fragment> $fragments
     *
     * @return list<Word>
     */
    public static function toWords(array $fragments, int $room): array
    {
        $room = \max(1, $room);
        $words = [];
        // A fragment's first word touches the last one when no space parts
        // them in the source: `[the guide](g.md).` must not read "the guide .".
        $spaceBefore = true;
        foreach ($fragments as $fragment) {
            $pieces = self::spaced($fragment->text);
            $glued = !$spaceBefore && !\str_starts_with($fragment->text, ' ');
            $spaceBefore = [] === $pieces ? $spaceBefore || '' !== $fragment->text : \str_ends_with($fragment->text, ' ');
            // Printable ASCII is as wide as it is long: most prose skips the
            // grapheme measure word by word.
            $ascii = \strlen($fragment->text) === \strspn($fragment->text, self::PRINTABLE);
            $source = null === $fragment->source ? -1 : $fragment->source->start;
            foreach ($pieces as [$text, $offset]) {
                $fits = $ascii && \strlen($text) <= $room;
                \array_push(
                    $words,
                    ...$fits
                        ? [new Word($text, \strlen($text), $fragment, $glued, $source < 0 || !$fragment->verbatim ? $source : $source + $offset)]
                        : self::cut($fragment, $text, $offset, $room, $glued),
                );
                $glued = false;
            }
        }

        return $words;
    }

    /**
     * The runs of $text between spaces, each with its byte offset.
     *
     * @return list<array{string, int}>
     */
    private static function spaced(string $text): array
    {
        $pieces = [];
        $length = \strlen($text);
        for ($offset = \strspn($text, characters: ' '); $offset < $length; $offset += \strspn($text, characters: ' ', offset: $offset)) {
            $word = \strcspn($text, characters: ' ', offset: $offset);
            $pieces[] = [\substr($text, $offset, $word), $offset];
            $offset += $word;
        }

        return $pieces;
    }

    /** @return list<Word> one word, or the pieces of one too wide for $room */
    private static function cut(Fragment $fragment, string $text, int $offset, int $room, bool $glued): array
    {
        $words = [];
        $width = AnsiUtils::visibleWidth($text);
        while ($width > $room) {
            // The head drops a wide glyph straddling the cut, so the rest
            // starts where the head ENDS — a CJK run loses no glyph per line.
            $cut = \max(\strlen(AnsiUtils::sliceByColumn($text, 0, $room)), TextMetrics::nextGrapheme($text, 0));
            $head = \substr($text, offset: 0, length: $cut);
            $words[] = new Word($head, AnsiUtils::visibleWidth($head), $fragment, $glued, self::sourceOf($fragment, $offset));
            $text = \substr($text, $cut);
            $offset += $cut;
            $width = AnsiUtils::visibleWidth($text);
            $glued = true;
        }
        if ('' !== $text) {
            $words[] = new Word($text, $width, $fragment, $glued, self::sourceOf($fragment, $offset));
        }

        return $words;
    }

    private static function sourceOf(Fragment $fragment, int $offset): int
    {
        return null === $fragment->source ? -1 : $fragment->source->start + ($fragment->verbatim ? $offset : 0);
    }

    /**
     * Greedily pack words into lines of $width, the first starting at
     * $firstCol and the rest at $hang. Tables pack each cell the same way.
     *
     * @param list<Word> $words
     *
     * @return list<list<Word>>
     */
    public static function packWords(array $words, int $width, int $firstCol, int $hang): array
    {
        $lines = [];
        $lineWords = [];
        $col = $firstCol;
        foreach ($words as $word) {
            $needed = ([] === $lineWords || $word->glued ? 0 : 1) + $word->width;
            if ([] !== $lineWords && ($col + $needed) > $width) {
                $lines[] = $lineWords;
                $lineWords = [];
                $col = $hang;
                $needed = $word->width;
            }
            $lineWords[] = $word;
            $col += $needed;
        }
        $lines[] = $lineWords;

        return $lines;
    }

    /**
     * One line's words drawn: a styled run per stretch of one fragment's
     * style and link, the spaces between runs left plain.
     *
     * @param list<Word> $words
     */
    public function line(array $words): PageLine
    {
        $text = '';
        $col = 0;
        $links = [];
        $cells = [];
        $anchors = [];
        foreach ($this->runs($words) as $run) {
            [$runText, $runCol, $runWidth, $fragment] = [$run['text'], $col + $run['lead'], $run['width'], $run['fragment']];
            $text .= \str_repeat(' ', \max(0, $run['lead'])).$this->pen->paint($runText, $fragment->style);
            if (null !== $fragment->target) {
                $links[] = [$runCol, $runCol + $runWidth, $fragment->target, $fragment->group];
            }
            \array_push($cells, ...$run['cells']);
            \array_push($anchors, ...null === $fragment->anchor ? [] : [$fragment->anchor]);
            $col = $runCol + $runWidth;
        }

        return new PageLine($text, $col, 0, $links, $cells, \array_values(\array_unique($anchors)));
    }

    /**
     * Words grouped into runs: consecutive words of one fragment share a run
     * and the spaces inside it; a space before a run stays outside its style.
     *
     * @param list<Word> $words
     *
     * @return list<array{text: string, lead: int, width: int, fragment: Fragment, cells: list<array{int, int, int, int, string}>}>
     */
    private function runs(array $words): array
    {
        $runs = [];
        $col = 0;
        foreach ($words as $index => $word) {
            $space = 0 === $index || $word->glued ? 0 : 1;
            $last = \count($runs) - 1;
            $joins = $last >= 0 && $runs[$last]['fragment'] === $word->from;
            $start = $col + $space;
            $cell = self::cell($word, $start);
            $col = $start + $word->width;
            if (!$joins) {
                $runs[] = ['text' => $word->text, 'lead' => $space, 'width' => $word->width, 'fragment' => $word->from, 'cells' => [$cell]];
                continue;
            }
            $runs[$last]['text'] .= \str_repeat(' ', $space).$word->text;
            $runs[$last]['width'] += $space + $word->width;
            $runs[$last]['cells'][] = $cell;
        }

        return $runs;
    }

    /** @return array{int, int, int, int, string} */
    private static function cell(Word $word, int $col): array
    {
        $verbatim = $word->from->verbatim;
        $end = $verbatim ? $word->sourceStart + \strlen($word->text) : $word->from->source->end ?? -1;

        return [$col, $col + $word->width, $word->sourceStart, $end, $verbatim ? $word->text : ''];
    }
}
