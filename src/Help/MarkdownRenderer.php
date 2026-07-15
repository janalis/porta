<?php

declare(strict_types=1);

namespace Porta\Help;

use Porta\Support\AnsiText;
use Porta\Ui\Palette;
use Porta\Ui\Role;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * A small markdown-to-terminal renderer for the F1 help browser: headings,
 * bold/italic/inline code, fenced code blocks, bullet/ordered lists, tables,
 * blockquotes, horizontal rules and links become styled ANSI lines plus
 * {@see LinkSpan}s keyed by rendered position. Prose word-wraps to the pane
 * width (tables and code fences truncate instead). Every inline style is a
 * self-contained SGR region — opened at its start column and closed at its
 * end — so the viewer can splice a highlight over any span with plain column
 * slicing. All color flows through the injected {@see Palette}: with colors
 * off, the output is pure text.
 *
 * @phpstan-type Segment array{text: string, style: string, target: string|null, group: int}
 * @phpstan-type Word array{text: string, width: int, style: string, target: string|null, group: int}
 */
final class MarkdownRenderer
{
    private const int MIN_WIDTH = 20;

    private int $nextGroup = 0;

    /** @var list<string> */
    private array $lines = [];
    /** @var list<LinkSpan> */
    private array $links = [];
    /** Set after a heading: source blank lines are absorbed until real content follows. */
    private bool $swallowBlanks = false;

    public function __construct(
        private readonly Palette $palette,
    ) {}

    public function render(string $markdown, int $width): RenderedDoc
    {
        $width = max(self::MIN_WIDTH, $width);
        $this->nextGroup = 0;
        $this->lines = [];
        $this->links = [];
        $this->swallowBlanks = false;

        $source = explode("\n", $markdown);
        $paragraph = [];
        for ($i = 0, $count = \count($source); $i < $count; ++$i) {
            $line = rtrim($source[$i]);
            $m = [];

            if ('' === trim($line)) {
                $this->flushParagraph($paragraph, $width);
                if (!$this->swallowBlanks) {
                    $this->lines[] = '';
                }
                continue;
            }
            $this->swallowBlanks = false;
            if (str_starts_with(trim($line), '```')) {
                $this->flushParagraph($paragraph, $width);
                $i = $this->fence($source, $i + 1, $width);
                continue;
            }
            if (str_starts_with(ltrim($line), '|')) {
                $this->flushParagraph($paragraph, $width);
                $rows = [];
                for (; $i < $count && str_starts_with(ltrim(rtrim($source[$i])), '|'); ++$i) {
                    $rows[] = trim($source[$i]);
                }
                --$i;
                $this->table($rows, $width);
                continue;
            }
            if (1 === preg_match('/^(#{1,6}) (.+)$/', $line, $m)) {
                $this->flushParagraph($paragraph, $width);
                $this->heading(\strlen($m[1]), $m[2], $width);
                continue;
            }
            if (1 === preg_match('/^\s*(-{3,}|\*{3,}|_{3,})\s*$/', $line)) {
                $this->flushParagraph($paragraph, $width);
                $this->lines[] = $this->palette->of(Role::Muted).str_repeat('─', $width).$this->resetAll();
                continue;
            }
            if (str_starts_with($line, '> ')) {
                $this->flushParagraph($paragraph, $width);
                $prefix = $this->palette->of(Role::Muted).'│ '.$this->palette->fgReset();
                $this->wrapSegments($this->inline(substr($line, offset: 2)), $width, $prefix, 2, $prefix);
                continue;
            }
            if (1 === preg_match('/^(\s*)- (.+)$/', $line, $m)) {
                $this->flushParagraph($paragraph, $width);
                $indent = \strlen($m[1]);
                $prefix = $m[1].$this->palette->of(Role::Muted).'• '.$this->palette->fgReset();
                $this->wrapSegments($this->inline($m[2]), $width, $prefix, $indent + 2, str_repeat(' ', $indent + 2));
                continue;
            }
            if (1 === preg_match('/^(\s*)(\d+\.) (.+)$/', $line, $m)) {
                $this->flushParagraph($paragraph, $width);
                $hang = \strlen($m[1]) + \strlen($m[2]) + 1;
                $this->wrapSegments($this->inline($m[3]), $width, $m[1].$m[2].' ', $hang, str_repeat(' ', $hang));
                continue;
            }
            $paragraph[] = trim($line);
        }
        $this->flushParagraph($paragraph, $width);

        // Collapse a trailing run of blank lines to none.
        while ([] !== $this->lines && '' === end($this->lines)) {
            array_pop($this->lines);
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
        $this->wrapSegments($this->inline(implode(' ', $paragraph)), $width, '', 0, '');
        $paragraph = [];
    }

    /**
     * Renders code lines until the closing fence; returns the fence line's index.
     *
     * @param list<string> $source
     */
    private function fence(array $source, int $from, int $width): int
    {
        $bg = $this->palette->of(Role::Toolbar);
        $count = \count($source);
        $i = $from;
        for (; $i < $count && !str_starts_with(trim($source[$i]), '```'); ++$i) {
            $text = AnsiUtils::truncateToWidth('  '.rtrim($source[$i]), $width, '…');
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
        while ([] !== $this->lines && '' === end($this->lines)) {
            array_pop($this->lines);
        }
        if ([] !== $this->lines) {
            $this->lines[] = '';
            $this->lines[] = '';
        }
        $open = match (true) {
            1 === $level => $this->bold().$this->palette->of(Role::Emphasis),
            2 === $level => $this->bold().$this->palette->of(Role::Bright),
            default => $this->bold(),
        };
        $assembled = $this->assemble($this->inline($text), 0);
        $line = $open.$assembled['text'].$this->resetAll();
        $this->lines[] = AnsiUtils::truncateToWidth($line, $width, '…');
        $this->recordSpans($assembled['spans'], \count($this->lines) - 1, $width);
        if ($level <= 2) {
            $ruleWidth = min($width, max(1, $assembled['width']));
            $rule = 1 === $level
                ? $this->palette->of(Role::Bright).str_repeat('═', max(0, $ruleWidth))
                : $this->palette->of(Role::Muted).str_repeat('─', max(0, $ruleWidth));
            $this->lines[] = $rule.$this->resetAll();
        }
        $this->lines[] = '';
        $this->swallowBlanks = true;
    }

    /** @param list<string> $rows raw `| a | b |` lines (a `---` separator row allowed) */
    private function table(array $rows, int $width): void
    {
        $cells = [];
        $separator = [];
        foreach ($rows as $index => $row) {
            $parts = array_map(trim(...), \array_slice(explode('|', trim($row, characters: '|')), offset: 0));
            if ([] !== $parts && 1 === preg_match('/^:?-{3,}:?$/', $parts[0])) {
                $separator[$index] = true;
                continue;
            }
            $cells[$index] = array_map(fn (string $cell): array => $this->assemble($this->inline($cell), 0), $parts);
        }

        $widths = [];
        foreach ($cells as $rowCells) {
            foreach ($rowCells as $column => $cell) {
                $widths[$column] = max($widths[$column] ?? 0, $cell['width']);
            }
        }

        $headerSeen = false;
        foreach ($rows as $index => $row) {
            if (array_key_exists($index, $separator)) {
                $rule = ' ';
                foreach ($widths as $columnWidth) {
                    $rule .= str_repeat('─', $columnWidth).'  ';
                }
                $this->lines[] = $this->palette->of(Role::Muted).AnsiUtils::truncateToWidth(rtrim($rule), $width, '…').$this->resetAll();
                continue;
            }

            $isHeader = !$headerSeen;
            $headerSeen = true;
            $text = ' ';
            $col = 1;
            $spans = [];
            foreach ($cells[$index] as $column => $cell) {
                foreach ($cell['spans'] as $span) {
                    $spans[] = ['col' => $span['col'] + $col, 'endCol' => $span['endCol'] + $col, 'target' => $span['target'], 'group' => $span['group']];
                }
                $text .= $cell['text'].str_repeat(' ', max(0, ($widths[$column] ?? 0) - $cell['width']) + 2);
                $col += ($widths[$column] ?? 0) + 2;
            }
            $line = $isHeader ? $this->bold().rtrim($text).$this->resetAll() : rtrim($text);
            $this->lines[] = AnsiUtils::truncateToWidth($line, $width, '…');
            $this->recordSpans($spans, \count($this->lines) - 1, $width);
        }
        $this->lines[] = '';
    }

    /**
     * Tokenize one logical unit of markdown into styled segments. Links come
     * first (they own their whole `[text](target)` source run); bold, italic
     * and inline code split the remaining plain stretches.
     *
     * @return list<Segment>
     */
    private function inline(string $text): array
    {
        $segments = [];
        $parts = preg_split('/\[([^\]]*)\]\(([^)]+)\)/', $text, -1, \PREG_SPLIT_DELIM_CAPTURE);
        $parts = false === $parts ? [$text] : $parts;
        for ($i = 0, $count = \count($parts); $i < $count; ++$i) {
            if (1 === ($i % 3)) {
                $label = $parts[$i];
                $target = $this->normalizeTarget($parts[$i + 1]);
                ++$i;
                if (null === $target) {
                    // A pure in-page anchor: styled, but not a followable link.
                    $segments[] = ['text' => $label, 'style' => 'italic', 'target' => null, 'group' => -1];
                    continue;
                }
                $segments[] = ['text' => $label, 'style' => 'link', 'target' => $target, 'group' => $this->nextGroup++];
                continue;
            }
            $chunks = preg_split('/(`[^`]+`|\*\*[^*]+\*\*|\*[^*]+\*)/', $parts[$i], -1, \PREG_SPLIT_DELIM_CAPTURE | \PREG_SPLIT_NO_EMPTY);
            foreach (\is_array($chunks) ? $chunks : [] as $chunk) {
                $segments[] = match (true) {
                    str_starts_with($chunk, '`') => ['text' => trim($chunk, characters: '`'), 'style' => 'code', 'target' => null, 'group' => -1],
                    str_starts_with($chunk, '**') => ['text' => trim($chunk, characters: '*'), 'style' => 'bold', 'target' => null, 'group' => -1],
                    str_starts_with($chunk, '*') => ['text' => trim($chunk, characters: '*'), 'style' => 'italic', 'target' => null, 'group' => -1],
                    default => ['text' => $chunk, 'style' => 'plain', 'target' => null, 'group' => -1],
                };
            }
        }

        return $segments;
    }

    /** The followable file/url part of a link target, or null for in-page anchors. */
    private function normalizeTarget(string $target): ?string
    {
        $target = trim($target);
        $target = str_starts_with($target, '<') ? trim($target, characters: '<>') : explode(' ', $target, limit: 2)[0];
        $file = explode('#', $target, limit: 2)[0];

        return '' === $file ? null : $file;
    }

    /**
     * Greedy word-wrap of styled segments into rendered lines, tracking link
     * spans through the wrapping (a split link emits one span per line).
     *
     * @param list<Segment> $segments
     */
    private function wrapSegments(array $segments, int $width, string $firstPrefix, int $hang, string $contPrefix): void
    {
        $words = [];
        foreach ($segments as $segment) {
            $splitWords = preg_split('/ +/', $segment['text'], -1, \PREG_SPLIT_NO_EMPTY);
            foreach (\is_array($splitWords) ? $splitWords : [] as $word) {
                $wordWidth = AnsiUtils::visibleWidth($word);
                $room = $width - max($hang, 2);
                while ($wordWidth > $room) {
                    // A single over-wide word (long URL/path) hard-breaks.
                    $head = AnsiUtils::sliceByColumn($word, 0, $room);
                    $words[] = ['text' => $head, 'width' => AnsiUtils::visibleWidth($head), 'style' => $segment['style'], 'target' => $segment['target'], 'group' => $segment['group']];
                    $word = AnsiUtils::sliceByColumn($word, $room, \PHP_INT_MAX);
                    $wordWidth = AnsiUtils::visibleWidth($word);
                }
                if ('' !== $word) {
                    $words[] = ['text' => $word, 'width' => $wordWidth, 'style' => $segment['style'], 'target' => $segment['target'], 'group' => $segment['group']];
                }
            }
        }

        $prefix = $firstPrefix;
        $prefixWidth = AnsiUtils::visibleWidth($firstPrefix);
        $lineWords = [];
        $col = $prefixWidth;
        foreach ($words as $word) {
            $needed = ([] === $lineWords ? 0 : 1) + $word['width'];
            if ([] !== $lineWords && ($col + $needed) > $width) {
                $this->emitLine($prefix, $prefixWidth, $lineWords, $width);
                $prefix = $contPrefix;
                $prefixWidth = $hang;
                $lineWords = [];
                $col = $prefixWidth;
                $needed = $word['width'];
            }
            $lineWords[] = $word;
            $col += $needed;
        }
        if ([] !== $lineWords || '' !== $firstPrefix) {
            $this->emitLine($prefix, $prefixWidth, $lineWords, $width);
        }
    }

    /** @param list<Word> $lineWords */
    private function emitLine(string $prefix, int $prefixWidth, array $lineWords, int $width): void
    {
        $assembled = $this->assemble($this->wordsToSegments($lineWords), $prefixWidth);
        $this->lines[] = AnsiUtils::truncateToWidth($prefix.$assembled['text'], $width, '…');
        $this->recordSpans($assembled['spans'], \count($this->lines) - 1, $width);
    }

    /**
     * Re-join placed words into per-line segments (spaces between words of
     * the same run stay inside the run's styled region).
     *
     * @param list<Word> $lineWords
     *
     * @return list<Segment>
     */
    private function wordsToSegments(array $lineWords): array
    {
        /** @var list<Segment> $segments */
        $segments = [];
        foreach ($lineWords as $word) {
            $last = [] !== $segments ? \count($segments) - 1 : null;
            if (null !== $last && $segments[$last]['style'] === $word['style'] && $segments[$last]['group'] === $word['group']) {
                $segments[$last] = ['text' => $segments[$last]['text'].' '.$word['text'], 'style' => $word['style'], 'target' => $word['target'], 'group' => $word['group']];
                continue;
            }
            if (null !== $last) {
                // The separating space stays outside both styled regions.
                $segments[] = ['text' => ' ', 'style' => 'plain', 'target' => null, 'group' => -1];
            }
            $segments[] = ['text' => $word['text'], 'style' => $word['style'], 'target' => $word['target'], 'group' => $word['group']];
        }

        return $segments;
    }

    /**
     * Compose styled segments into one line fragment, producing prefix-relative
     * link spans. Each styled region opens and closes its own SGR codes.
     *
     * @param list<Segment> $segments
     *
     * @return array{text: string, width: int, spans: list<array{col: int, endCol: int, target: string, group: int}>}
     */
    private function assemble(array $segments, int $startCol): array
    {
        $text = '';
        $col = $startCol;
        $spans = [];
        foreach ($segments as $segment) {
            $segmentWidth = AnsiUtils::visibleWidth($segment['text']);
            if ('link' === $segment['style'] && null !== $segment['target']) {
                // Trailing joining spaces stay outside the recorded span.
                $spans[] = ['col' => $col, 'endCol' => $col + AnsiUtils::visibleWidth(rtrim($segment['text'])), 'target' => $segment['target'], 'group' => $segment['group']];
            }
            $text .= $this->open($segment['style']).$segment['text'].$this->close($segment['style']);
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

    private function open(string $style): string
    {
        return match ($style) {
            'bold' => $this->bold(),
            'italic' => $this->palette->hasColor() ? "\x1b[3m" : '',
            'code' => $this->palette->of(Role::Emphasis),
            'link' => ($this->palette->hasColor() ? "\x1b[4m" : '').$this->palette->of(Role::TabAccent),
            default => '',
        };
    }

    private function close(string $style): string
    {
        return match ($style) {
            'bold' => $this->palette->hasColor() ? "\x1b[22m" : '',
            'italic' => $this->palette->hasColor() ? "\x1b[23m" : '',
            'code' => $this->palette->fgReset(),
            'link' => $this->palette->fgReset().($this->palette->hasColor() ? "\x1b[24m" : ''),
            default => '',
        };
    }

    private function bold(): string
    {
        return $this->palette->hasColor() ? "\x1b[1m" : '';
    }

    private function resetAll(): string
    {
        return $this->palette->hasColor() ? $this->palette->reset() : '';
    }
}
