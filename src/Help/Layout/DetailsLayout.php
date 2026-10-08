<?php

declare(strict_types=1);

namespace Porta\Help\Layout;

use Porta\Markdown\Node\Block;
use Porta\Markdown\Node\HtmlBlock;
use Porta\Markdown\SourceRange;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * A `<details>` section: its summary on a row of its own behind a `▸`, a link
 * that opens it — then `▾`, and what it holds below, indented. CommonMark
 * cuts a section written with blank lines inside into sibling blocks (an
 * HTML block opening it, the markdown between, an HTML block closing it), so
 * the section is gathered back from its siblings here. Whether it is open is
 * the reader's view, never written to the source.
 */
final readonly class DetailsLayout
{
    private const int INDENT = 2;

    public function __construct(
        private StylePen $pen,
        private LayoutState $state,
        private BlockLayout $blocks,
        private HtmlBlockLayout $html,
        private PageWords $words,
    ) {}

    /**
     * $blocks with each HTML block cut after every `</details>` that more
     * follows: CommonMark keeps `</details>` and a `<details>` on the next
     * line as one block, where a README means two sections.
     *
     * @param list<Block> $blocks
     *
     * @return list<Block>
     */
    public function separated(array $blocks): array
    {
        $separated = [];
        foreach ($blocks as $block) {
            \array_push($separated, ...$block instanceof HtmlBlock ? $this->cut($block) : [$block]);
        }

        return $separated;
    }

    /** @return non-empty-list<HtmlBlock> */
    private function cut(HtmlBlock $block): array
    {
        $literal = $block->literal;
        $close = \stripos($literal, needle: '</details');
        $end = false === $close ? false : \strpos($literal, needle: '>', offset: $close);
        if (false === $end) {
            return [$block];
        }
        ++$end;
        $next = $end + \strspn($literal, characters: " \t\r\n", offset: $end);
        if ($next >= \strlen($literal)) {
            return [$block];
        }
        // Each piece keeps its own bytes when the block is written as it reads
        // (not inside a quote or a list, whose markers the literal has lost).
        $range = $block->range;
        $exact = \str_starts_with($literal, \rtrim($this->state->source->slice($range)));
        $head = new HtmlBlock(\substr($literal, offset: 0, length: $end), $exact ? new SourceRange($range->start, $range->start + $end) : $range);
        $tail = new HtmlBlock(\substr($literal, $next), $exact ? new SourceRange($range->start + $next, $range->end) : $range);

        return [$head, ...$this->cut($tail)];
    }

    /**
     * The blocks of the section opening at $blocks[$index], through the one
     * that closes it (or to the end); null when no section opens there.
     *
     * @param list<Block> $blocks
     *
     * @return non-empty-list<Block>|null
     */
    public function section(array $blocks, int $index): ?array
    {
        $first = $blocks[$index];
        if (!$first instanceof HtmlBlock || 1 !== \preg_match('/<details\b/i', $first->literal)) {
            return null;
        }
        $section = [$first];
        if (self::closes($first->literal)) {
            return $section;
        }
        for ($next = $index + 1, $count = \count($blocks); $next < $count; ++$next) {
            $section[] = $blocks[$next];
            if ($blocks[$next] instanceof HtmlBlock && self::closes($blocks[$next]->literal)) {
                break;
            }
        }

        return $section;
    }

    private static function closes(string $literal): bool
    {
        return 1 === \preg_match('/<\/details>/i', $literal);
    }

    /**
     * @param non-empty-list<Block> $section
     *
     * @return list<PageLine>
     */
    public function lines(array $section, int $width): array
    {
        $opening = $section[0];
        \assert($opening instanceof HtmlBlock, description: 'a section opens with the HTML block that opens it');
        $start = $opening->range->start;
        $number = $this->state->detailsNumber($start);
        $open = \array_key_exists($number, $this->state->openDetails);
        $m = [];
        $summary = 1 === \preg_match('/<summary\b[^>]*>(.*?)<\/summary>/is', $opening->literal, $m) ? \trim(\preg_replace('/\s+/', replacement: ' ', subject: \strip_tags($m[1])) ?? '') : '';
        $summary = '' === $summary ? $this->words->details() : \html_entity_decode($summary, \ENT_QUOTES | \ENT_HTML5, encoding: 'UTF-8');
        $marker = $open ? Glyphs::MD_DETAILS_OPEN : Glyphs::MD_DETAILS_CLOSED;
        $text = $this->pen->role($marker, Role::TabAccent).' '.$this->pen->paint($summary, new InlineStyle(InlineStyle::BOLD));
        $width2 = 2 + AnsiUtils::visibleWidth($summary);
        $line = new PageLine($text, $width2, $this->state->lineOf($start), [[0, $width2, 'details:'.$number, $this->state->action()]]);
        if (!$open) {
            return [$line];
        }

        return [$line, ...$this->body($section, \max(1, $width - self::INDENT))];
    }

    /**
     * What the section holds: the opening block's own text after its summary,
     * then the blocks between — indented under the summary.
     *
     * @param non-empty-list<Block> $section
     *
     * @return list<PageLine>
     */
    private function body(array $section, int $width): array
    {
        $opening = $section[0];
        \assert($opening instanceof HtmlBlock, description: 'a section opens with the HTML block that opens it');
        $rest = \preg_replace(['/^.*?<\/summary>/is', '/<\/?details\b[^>]*>/i'], replacement: '', subject: $opening->literal) ?? '';
        $lines = $this->html->literal($rest, $opening, $width, $this->state);
        $inner = \array_slice($section, offset: 1);
        $last = $inner[\count($inner) - 1] ?? null;
        if ($last instanceof HtmlBlock && self::closes($last->literal)) {
            \array_pop($inner);
        }
        $children = $this->blocks->blocks($inner, $width);
        if ([] !== $lines && [] !== $children) {
            $lines[] = PageLine::blank($lines[\count($lines) - 1]->origin);
        }
        $indent = PageLine::spaces(self::INDENT);

        return \array_map(static fn (PageLine $line): PageLine => ('' === $line->text ? $line : $line->after($indent))->withOrigin($line->origin), [...$lines, ...$children]);
    }
}
