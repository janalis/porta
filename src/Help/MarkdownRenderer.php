<?php

declare(strict_types=1);

namespace Porta\Help;

use Porta\Editor\TextMetrics;
use Porta\Support\AnsiText;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Sgr;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * A small markdown-to-terminal renderer for the F1 help browser: headings,
 * bold/italic/inline code, fenced code blocks, bullet/ordered lists, tables,
 * blockquotes, horizontal rules and links become styled ANSI lines plus
 * {@see LinkSpan}s keyed by rendered position. Prose word-wraps to the pane
 * width, and so does a table CELL: the columns are budgeted against the pane
 * and each cell wraps inside its own, so a row is as tall as its tallest cell
 * and nothing is lost. Only code fences still truncate, because reflowing code
 * changes what it says. Every inline style is a
 * self-contained SGR region — opened at its start column and closed at its
 * end — so the viewer can splice a highlight over any span with plain column
 * slicing. All color flows through the injected {@see Styles}: with colors
 * off, the output is pure text. Styled regions open and close as streaming
 * pens across the word-wrap, so opens come from getAnsiRestore() rather
 * than balanced apply() wraps.
 *
 */
final class MarkdownRenderer
{
    /** Narrowest a table column may be squeezed to before the row truncates instead. */
    private const int MIN_COLUMN = 8;

    private const int MIN_WIDTH = 20;

    private int $nextGroup = 0;

    /** @var list<string> */
    private array $lines = [];
    /** @var list<LinkSpan> */
    private array $links = [];
    /** Set after a heading: source blank lines are absorbed until real content follows. */
    private bool $swallowBlanks = false;

    public function __construct(
        private readonly Styles $styles,
    ) {}

    public function render(string $markdown, int $width): RenderedDoc
    {
        $width = \max(self::MIN_WIDTH, $width);
        $this->nextGroup = 0;
        $this->lines = [];
        $this->links = [];
        $this->swallowBlanks = false;

        $source = \explode("\n", $markdown);
        $paragraph = [];
        for ($i = 0, $count = \count($source); $i < $count; ++$i) {
            $line = \rtrim($source[$i]);
            $m = [];

            if ('' === \trim($line)) {
                $this->flushParagraph($paragraph, $width);
                if (!$this->swallowBlanks) {
                    $this->lines[] = '';
                }
                continue;
            }
            $this->swallowBlanks = false;
            if (\str_starts_with(\trim($line), '```')) {
                $this->flushParagraph($paragraph, $width);
                $i = $this->fence($source, $i + 1, $width);
                continue;
            }
            if (\str_starts_with(\ltrim($line), '|')) {
                $this->flushParagraph($paragraph, $width);
                $rows = [];
                for (; $i < $count && \str_starts_with(\ltrim(\rtrim($source[$i])), '|'); ++$i) {
                    $rows[] = \trim($source[$i]);
                }
                --$i;
                $this->table($rows, $width);
                continue;
            }
            if (1 === \preg_match('/^(#{1,6}) (.+)$/', $line, $m)) {
                $this->flushParagraph($paragraph, $width);
                $this->heading(\strlen($m[1]), $m[2], $width);
                continue;
            }
            if (1 === \preg_match('/^\s*(-{3,}|\*{3,}|_{3,})\s*$/', $line)) {
                $this->flushParagraph($paragraph, $width);
                $this->lines[] = $this->styles->of(Role::Muted)->getAnsiRestore().\str_repeat(Glyphs::MD_RULE, $width).$this->resetAll();
                continue;
            }
            if (\str_starts_with($line, '> ')) {
                $this->flushParagraph($paragraph, $width);
                $prefix = $this->styles->of(Role::Muted)->getAnsiRestore().Glyphs::MD_QUOTE_BAR.' '.$this->fgReset();
                $this->wrapSegments($this->inline(\substr($line, offset: 2)), $width, $prefix, 2, $prefix);
                continue;
            }
            if (1 === \preg_match('/^(\s*)- (.+)$/', $line, $m)) {
                $this->flushParagraph($paragraph, $width);
                $indent = \strlen($m[1]);
                $prefix = $m[1].$this->styles->of(Role::Muted)->getAnsiRestore().Glyphs::MD_BULLET.' '.$this->fgReset();
                $this->wrapSegments($this->inline($m[2]), $width, $prefix, $indent + 2, \str_repeat(' ', $indent + 2));
                continue;
            }
            if (1 === \preg_match('/^(\s*)(\d+\.) (.+)$/', $line, $m)) {
                $this->flushParagraph($paragraph, $width);
                $hang = \strlen($m[1]) + \strlen($m[2]) + 1;
                $this->wrapSegments($this->inline($m[3]), $width, $m[1].$m[2].' ', $hang, \str_repeat(' ', $hang));
                continue;
            }
            $paragraph[] = \trim($line);
        }
        $this->flushParagraph($paragraph, $width);

        // Collapse a trailing run of blank lines to none.
        while ([] !== $this->lines && '' === \end($this->lines)) {
            \array_pop($this->lines);
        }

        return new RenderedDoc($this->lines, $this->links);
    }

    /**
     * Hard-wrapped source prose joins into one logical paragraph before
     * re-wrapping, so wide panes get long lines instead of the author's 72s.
     *
     * @param list<string> $paragraph
     */
    private function flushParagraph(array &$paragraph, int $width): void
    {
        if ([] === $paragraph) {
            return;
        }
        $this->wrapSegments($this->inline(\implode(' ', $paragraph)), $width, '', 0, '');
        $paragraph = [];
    }

    /**
     * Renders code lines until the closing fence; returns the fence line's index.
     *
     * @param list<string> $source
     */
    private function fence(array $source, int $from, int $width): int
    {
        $bg = $this->styles->of(Role::Toolbar)->getAnsiRestore();
        $count = \count($source);
        $i = $from;
        for (; $i < $count && !\str_starts_with(\trim($source[$i]), '```'); ++$i) {
            $text = AnsiText::truncate('  '.\rtrim($source[$i]), $width);
            $this->lines[] = $bg.AnsiText::padRight($text, $width).$this->resetAll();
        }

        return $i;
    }

    /**
     * Terminals have no font sizes (and the column model cannot host DECDHL
     * double-height lines), so heading levels build a typographic hierarchy:
     * H1 gets a `═` rule, H2 a `─` rule, H3+ stays bold-only.
     */
    private function heading(int $level, string $text, int $width): void
    {
        // Typography: two blank rows above (none at the document top), one
        // below — whatever blank lines the author used (the block loop
        // swallows the source blanks that follow).
        while ([] !== $this->lines && '' === \end($this->lines)) {
            \array_pop($this->lines);
        }
        if ([] !== $this->lines) {
            $this->lines[] = '';
            $this->lines[] = '';
        }
        $open = match (true) {
            1 === $level => $this->bold().$this->styles->of(Role::Emphasis)->getAnsiRestore(),
            2 === $level => $this->bold().$this->styles->of(Role::Bright)->getAnsiRestore(),
            default => $this->bold(),
        };
        $assembled = $this->assemble($this->inline($text), 0);
        $line = $open.$assembled['text'].$this->resetAll();
        $this->lines[] = AnsiText::truncate($line, $width);
        $this->recordSpans($assembled['spans'], \count($this->lines) - 1, $width);
        if ($level <= 2) {
            $ruleWidth = \min($width, \max(1, $assembled['width']));
            $rule = 1 === $level
                ? $this->styles->of(Role::Bright)->getAnsiRestore().\str_repeat(Glyphs::MD_HEADING_RULE, \max(0, $ruleWidth))
                : $this->styles->of(Role::Muted)->getAnsiRestore().\str_repeat(Glyphs::MD_RULE, \max(0, $ruleWidth));
            $this->lines[] = $rule.$this->resetAll();
        }
        $this->lines[] = '';
        $this->swallowBlanks = true;
    }

    /** @param list<string> $rows raw `| a | b |` lines (a `---` separator row allowed) */
    /**
     * Column widths that fit the pane, shrinking the widest until they do.
     *
     * The natural widths — each column as wide as its longest cell — are what
     * the table WANTS. When their total overruns the pane the widest column
     * gives up a column at a time, so a long prose column narrows while a
     * column of keys keeps every character of every key. Nothing is dropped:
     * whatever a column loses in width it gains in wrapped rows.
     *
     * The floor stops a column being narrowed into a stack of single letters;
     * below it the table is simply wider than the pane and the row truncate
     * takes over, which is what a two-column terminal deserves.
     *
     * @param array<array-key, list<array{text: string, width: int, spans: list<array{col: int, endCol: int, target: string, group: int}>}>> $cells
     *
     * @return array<array-key, int>
     */
    private static function columnWidths(array $cells, int $width): array
    {
        $widths = [];
        foreach ($cells as $rowCells) {
            foreach ($rowCells as $column => $cell) {
                $widths[$column] = \max($widths[$column] ?? 0, $cell['width']);
            }
        }
        // No empty-set guard: a table of nothing but separator rows yields no
        // columns, `rowWidth([])` is the leading space alone, and one column is
        // never wider than the pane — so the loop that would call max() on an
        // empty array cannot run. The coverage gate is what said so.
        while (self::rowWidth($widths) > $width) {
            $widest = \array_keys($widths, \max($widths), strict: true)[0];
            if ($widths[$widest] <= self::MIN_COLUMN) {
                break;
            }
            --$widths[$widest];
        }

        return $widths;
    }

    /**
     * What a row of these columns occupies: a leading space, each column, and
     * the two trailing spaces after each — exactly what the row assembly emits.
     *
     * @param array<array-key, int> $widths
     */
    private static function rowWidth(array $widths): int
    {
        $total = 1 + (2 * \count($widths));
        foreach ($widths as $columnWidth) {
            $total += $columnWidth;
        }

        return $total;
    }

    /**
     * One cell's segments wrapped to $width, as one fragment per rendered row.
     *
     * Shares the paragraph wrapper's word splitting and greedy packing, so a
     * cell breaks where a paragraph would; the spans come back per fragment and
     * column-relative, which is what lets a link survive being wrapped.
     *
     * @param list<MarkdownSegment> $segments
     *
     * @return list<array{text: string, width: int, spans: list<array{col: int, endCol: int, target: string, group: int}>}>
     */
    private function wrapCell(array $segments, int $width): array
    {
        $fragments = [];
        foreach (self::packWords(self::toWords($segments, $width), $width, firstCol: 0, hang: 0) as $lineWords) {
            $fragments[] = $this->assemble($this->wordsToSegments($lineWords), 0);
        }

        return $fragments;
    }

    /**
     * One markdown table: budget the columns against the pane, then wrap each
     * cell inside its own so no cell loses its tail.
     *
     * @param list<string> $rows the `|`-delimited source lines, separator included
     */
    private function table(array $rows, int $width): void
    {
        $cells = [];
        $sources = [];
        $separator = [];
        foreach ($rows as $index => $row) {
            /** @var list<string> $parts */
            $parts = \array_map(trim(...), \explode('|', \trim($row, characters: '|')));
            if ([] !== $parts && 1 === \preg_match('/^:?-{3,}:?$/', $parts[0])) {
                $separator[$index] = true;
                continue;
            }
            $sources[$index] = \array_map($this->inline(...), $parts);
            $cells[$index] = \array_map(fn (array $segments): array => $this->assemble($segments, 0), $sources[$index]);
        }

        $widths = self::columnWidths($cells, $width);

        $headerSeen = false;
        foreach ($rows as $index => $row) {
            if (\array_key_exists($index, $separator)) {
                $rule = ' ';
                foreach ($widths as $columnWidth) {
                    $rule .= \str_repeat(Glyphs::MD_RULE, \max(0, $columnWidth)).'  ';
                }
                $this->lines[] = $this->styles->of(Role::Muted)->getAnsiRestore().AnsiText::truncate(\rtrim($rule), $width).$this->resetAll();
                continue;
            }

            $isHeader = !$headerSeen;
            $headerSeen = true;

            // Each cell wraps inside its own budgeted column, so the row is as
            // tall as its tallest cell and every column keeps its text. The
            // cells were parsed once above; only the wrapping needs the width.
            $wrapped = [];
            $height = 1;
            foreach ($sources[$index] as $column => $segments) {
                $wrapped[$column] = $this->wrapCell($segments, $widths[$column] ?? 1);
                $height = \max($height, \count($wrapped[$column]));
            }

            foreach (\range(start: 0, end: $height - 1) as $physical) {
                $text = ' ';
                $col = 1;
                $spans = [];
                foreach ($wrapped as $column => $fragments) {
                    $fragment = $fragments[$physical] ?? ['text' => '', 'width' => 0, 'spans' => []];
                    foreach ($fragment['spans'] as $span) {
                        $spans[] = ['col' => $span['col'] + $col, 'endCol' => $span['endCol'] + $col, 'target' => $span['target'], 'group' => $span['group']];
                    }
                    $text .= $fragment['text'].\str_repeat(' ', \max(0, ($widths[$column] ?? 0) - $fragment['width']) + 2);
                    $col += ($widths[$column] ?? 0) + 2;
                }
                $line = $isHeader ? $this->bold().\rtrim($text).$this->resetAll() : \rtrim($text);
                $this->lines[] = AnsiText::truncate($line, $width);
                $this->recordSpans($spans, \count($this->lines) - 1, $width);
            }
        }
        $this->lines[] = '';
    }

    /**
     * Tokenize one logical unit of markdown into styled segments. Links come
     * first (they own their whole `[text](target)` source run); bold, italic
     * and inline code split the remaining plain stretches.
     *
     * @return list<MarkdownSegment>
     */
    private function inline(string $text): array
    {
        $segments = [];
        $parts = \preg_split('/\[([^\]]*)\]\(([^)]+)\)/', $text, -1, \PREG_SPLIT_DELIM_CAPTURE);
        $parts = false === $parts ? [$text] : $parts;
        for ($i = 0, $count = \count($parts); $i < $count; ++$i) {
            if (1 === ($i % 3)) {
                $label = $parts[$i];
                $target = $this->normalizeTarget($parts[$i + 1]);
                ++$i;
                if (null === $target) {
                    // A pure in-page anchor: styled, but not a followable link.
                    $segments[] = new MarkdownSegment($label, MarkdownSpanKind::Italic);
                    continue;
                }
                $segments[] = new MarkdownSegment($label, MarkdownSpanKind::Link, $target, $this->nextGroup++);
                continue;
            }
            $chunks = \preg_split('/(`[^`]+`|\*\*[^*]+\*\*|\*[^*]+\*)/', $parts[$i], -1, \PREG_SPLIT_DELIM_CAPTURE | \PREG_SPLIT_NO_EMPTY);
            foreach (\is_array($chunks) ? $chunks : [] as $chunk) {
                $segments[] = match (true) {
                    \str_starts_with($chunk, '`') => new MarkdownSegment(\trim($chunk, characters: '`'), MarkdownSpanKind::Code),
                    \str_starts_with($chunk, '**') => new MarkdownSegment(\trim($chunk, characters: '*'), MarkdownSpanKind::Bold),
                    \str_starts_with($chunk, '*') => new MarkdownSegment(\trim($chunk, characters: '*'), MarkdownSpanKind::Italic),
                    default => new MarkdownSegment($chunk),
                };
            }
        }

        return $segments;
    }

    /** The followable file/url part of a link target, or null for in-page anchors. */
    private function normalizeTarget(string $target): ?string
    {
        $target = \trim($target);
        $target = \str_starts_with($target, '<') ? \trim($target, characters: '<>') : \explode(' ', $target, limit: 2)[0];
        $file = \explode('#', $target, limit: 2)[0];

        return '' === $file ? null : $file;
    }

    /**
     * Greedy word-wrap of styled segments into rendered lines, tracking link
     * spans through the wrapping (a split link emits one span per line).
     *
     * @param list<MarkdownSegment> $segments
     */
    private function wrapSegments(array $segments, int $width, string $firstPrefix, int $hang, string $contPrefix): void
    {
        $words = self::toWords($segments, $width - \max($hang, 2));

        $prefix = $firstPrefix;
        $prefixWidth = AnsiUtils::visibleWidth($firstPrefix);
        foreach (self::packWords($words, $width, $prefixWidth, $hang) as $index => $lineWords) {
            $this->emitLine(0 === $index ? $prefix : $contPrefix, 0 === $index ? $prefixWidth : $hang, $lineWords, $width);
        }
    }

    /**
     * Split styled segments into words, hard-breaking any single word wider
     * than $room (a long URL or path has no break to greedily take).
     *
     * @param list<MarkdownSegment> $segments
     *
     * @return list<MarkdownWord>
     */
    private static function toWords(array $segments, int $room): array
    {
        $room = \max(1, $room);
        $words = [];
        // A segment's first word touches the last one when no space parts them
        // in the source — a link or an emphasis run followed by punctuation.
        // Rejoined with a space, `[the guide](g.md).` read "the guide .".
        $spaceBefore = true;
        foreach ($segments as $segment) {
            $splitWords = \preg_split('/ +/', $segment->text, -1, \PREG_SPLIT_NO_EMPTY);
            $glued = !$spaceBefore && !\str_starts_with($segment->text, ' ');
            if ([] !== $splitWords && false !== $splitWords) {
                $spaceBefore = \str_ends_with($segment->text, ' ');
            }
            foreach (\is_array($splitWords) ? $splitWords : [] as $word) {
                $wordWidth = AnsiUtils::visibleWidth($word);
                while ($wordWidth > $room) {
                    // The head drops a wide glyph straddling the cut, so the rest
                    // starts where the head ENDS, not at column $room: cutting the
                    // rest at the column dropped that glyph from both halves, and a
                    // CJK paragraph (one "word", no spaces) lost one glyph per line.
                    // At least one whole glyph per piece, so a glyph wider than
                    // the room still advances the loop (the line's truncate clips).
                    $cut = \max(\strlen(AnsiUtils::sliceByColumn($word, 0, $room)), TextMetrics::nextGrapheme($word, 0));
                    $head = \substr($word, offset: 0, length: $cut);
                    $words[] = new MarkdownWord($head, AnsiUtils::visibleWidth($head), $segment->kind, $segment->target, $segment->group);
                    $word = \substr($word, \strlen($head));
                    $wordWidth = AnsiUtils::visibleWidth($word);
                }
                if ('' !== $word) {
                    $words[] = new MarkdownWord($word, $wordWidth, $segment->kind, $segment->target, $segment->group, $glued);
                }
                $glued = false;
            }
        }

        return $words;
    }

    /**
     * Greedily pack words into lines of $width, the first line starting at
     * $firstCol and the rest at $hang.
     *
     * Shared by the paragraph wrapper and the table-cell wrapper: one greedy
     * packer, so a cell breaks its text exactly where a paragraph would.
     *
     * @param list<MarkdownWord> $words
     *
     * @return list<list<MarkdownWord>>
     */
    private static function packWords(array $words, int $width, int $firstCol, int $hang): array
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

    /** @param list<MarkdownWord> $lineWords */
    private function emitLine(string $prefix, int $prefixWidth, array $lineWords, int $width): void
    {
        $assembled = $this->assemble($this->wordsToSegments($lineWords), $prefixWidth);
        $this->lines[] = AnsiText::truncate($prefix.$assembled['text'], $width);
        $this->recordSpans($assembled['spans'], \count($this->lines) - 1, $width);
    }

    /**
     * Re-join placed words into per-line segments (spaces between words of
     * the same run stay inside the run's styled region).
     *
     * @param list<MarkdownWord> $lineWords
     *
     * @return list<MarkdownSegment>
     */
    private function wordsToSegments(array $lineWords): array
    {
        /** @var list<MarkdownSegment> $segments */
        $segments = [];
        foreach ($lineWords as $word) {
            $last = [] !== $segments ? \count($segments) - 1 : null;
            $space = $word->glued ? '' : ' ';
            if (null !== $last && $segments[$last]->kind === $word->kind && $segments[$last]->group === $word->group) {
                $segments[$last] = new MarkdownSegment($segments[$last]->text.$space.$word->text, $word->kind, $word->target, $word->group);
                continue;
            }
            if (null !== $last && !$word->glued) {
                // The separating space stays outside both styled regions.
                $segments[] = new MarkdownSegment(' ');
            }
            $segments[] = new MarkdownSegment($word->text, $word->kind, $word->target, $word->group);
        }

        return $segments;
    }

    /**
     * Compose styled segments into one line fragment, producing prefix-relative
     * link spans. Each styled region opens and closes its own SGR codes.
     *
     * @param list<MarkdownSegment> $segments
     *
     * @return array{text: string, width: int, spans: list<array{col: int, endCol: int, target: string, group: int}>}
     */
    private function assemble(array $segments, int $startCol): array
    {
        $text = '';
        $col = $startCol;
        $spans = [];
        foreach ($segments as $segment) {
            $segmentWidth = AnsiUtils::visibleWidth($segment->text);
            if (MarkdownSpanKind::Link === $segment->kind && null !== $segment->target) {
                // Trailing joining spaces stay outside the recorded span.
                $spans[] = ['col' => $col, 'endCol' => $col + AnsiUtils::visibleWidth(\rtrim($segment->text)), 'target' => $segment->target, 'group' => $segment->group];
            }
            $text .= $this->open($segment->kind).$segment->text.$this->close($segment->kind);
            $col += $segmentWidth;
        }

        return ['text' => $text, 'width' => $col - $startCol, 'spans' => $spans];
    }

    /** @param list<array{col: int, endCol: int, target: string, group: int}> $spans */
    private function recordSpans(array $spans, int $line, int $width): void
    {
        // A truncated line ends in the '…' cell — spans reaching it are hidden.
        $limit = AnsiUtils::visibleWidth($this->lines[$line]) >= $width ? $width - 1 : $width;
        foreach ($spans as $span) {
            if ($span['endCol'] > $limit) {
                continue; // truncated out of view — never Tab-focus an invisible link
            }
            $this->links[] = new LinkSpan($line, $span['col'], $span['endCol'], $span['target'], $span['group']);
        }
    }

    private function open(MarkdownSpanKind $kind): string
    {
        return match ($kind) {
            MarkdownSpanKind::Bold => $this->bold(),
            MarkdownSpanKind::Italic => $this->styles->hasColor() ? "\x1b[3m" : '',
            MarkdownSpanKind::Code => $this->styles->of(Role::Emphasis)->getAnsiRestore(),
            MarkdownSpanKind::Link => ($this->styles->hasColor() ? Sgr::UNDERLINE : '').$this->styles->of(Role::TabAccent)->getAnsiRestore(),
            MarkdownSpanKind::Plain => '',
        };
    }

    private function close(MarkdownSpanKind $kind): string
    {
        return match ($kind) {
            MarkdownSpanKind::Bold => $this->styles->hasColor() ? Sgr::NORMAL_INTENSITY : '',
            MarkdownSpanKind::Italic => $this->styles->hasColor() ? "\x1b[23m" : '',
            MarkdownSpanKind::Code => $this->fgReset(),
            MarkdownSpanKind::Link => $this->fgReset().($this->styles->hasColor() ? "\x1b[24m" : ''),
            MarkdownSpanKind::Plain => '',
        };
    }

    private function bold(): string
    {
        return $this->styles->hasColor() ? Sgr::BOLD : '';
    }

    /** Close an open foreground pen (nothing was opened in monochrome). */
    private function fgReset(): string
    {
        return $this->styles->hasColor() ? Sgr::DEFAULT_FG : '';
    }

    private function resetAll(): string
    {
        return $this->styles->hasColor() ? Sgr::RESET : '';
    }
}
