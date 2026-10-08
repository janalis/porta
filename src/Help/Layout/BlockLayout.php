<?php

declare(strict_types=1);

namespace Porta\Help\Layout;

use Porta\Markdown\Node\Block;
use Porta\Markdown\Node\BlockQuote;
use Porta\Markdown\Node\CodeBlock;
use Porta\Markdown\Node\FootnoteDefinition;
use Porta\Markdown\Node\Heading;
use Porta\Markdown\Node\HtmlBlock;
use Porta\Markdown\Node\ListBlock;
use Porta\Markdown\Node\Paragraph;
use Porta\Markdown\Node\Table;
use Porta\Markdown\Node\ThematicBreak;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;

/**
 * A document's blocks as page lines, the way GitHub lays them out: a blank
 * line between blocks (none inside a tight list item), headings with room
 * above and a rule under the first two levels, lists hung from their
 * markers, quotes and alerts behind a bar, and the footnotes gathered at the
 * end. Containers lay their children out narrower and put their prefix in
 * front; every line keeps the source line its block starts on.
 */
final readonly class BlockLayout
{
    private InlineFragments $inlines;
    private Wrapper $wrapper;
    private ContainerLayout $containers;
    private DetailsLayout $details;

    public function __construct(
        private StylePen $pen,
        private LayoutState $state,
        private CodeLayout $code,
        private TableLayout $tables,
        private HtmlBlockLayout $html,
        private ImageLayout $images,
        PageWords $words,
    ) {
        $this->inlines = new InlineFragments($state);
        $this->wrapper = new Wrapper($pen);
        $this->containers = new ContainerLayout($pen, $state, $this, $words);
        $this->details = new DetailsLayout($pen, $state, $this, $html, $words);
    }

    /**
     * @param list<Block> $blocks
     * @param int         $gap    blank lines between blocks: 1, or 0 in a tight list item
     *
     * @return list<PageLine>
     */
    public function blocks(array $blocks, int $width, int $gap = 1): array
    {
        $lines = [];
        for ($index = 0, $count = \count($blocks); $index < $count; ++$index) {
            $block = $blocks[$index];
            if ($block instanceof FootnoteDefinition) {
                continue; // gathered at the end of the page
            }
            $section = $this->details->section($blocks, $index);
            $drawn = null === $section ? $this->block($block, $width) : $this->details->lines($section, $width);
            $index += null === $section ? 0 : \count($section) - 1;
            if ([] === $drawn) {
                continue;
            }
            $before = $block instanceof Heading ? 2 : $gap;
            for ($i = 0; [] !== $lines && $i < $before; ++$i) {
                // A blank belongs to the block above it: a view brought to a block never opens on the gap before it.
                $lines[] = PageLine::blank($lines[\count($lines) - 1]->origin);
            }
            \array_push($lines, ...$drawn);
        }

        return $lines;
    }

    /** @return list<PageLine> */
    private function block(Block $block, int $width): array
    {
        $origin = $this->state->lineOf($block->range->start);
        $lines = match (true) {
            $block instanceof Paragraph => $this->paragraph($this->inlines->of($block->inlines), $width),
            $block instanceof Heading => $this->heading($block, $width),
            $block instanceof ThematicBreak => [new PageLine($this->pen->role(\str_repeat(Glyphs::MD_RULE, \max(0, $width)), Role::Muted), $width)],
            $block instanceof CodeBlock => $this->code->lines($block, $width, $this->state),
            $block instanceof Table => $this->tables->lines($block, $width, $this->state),
            $block instanceof HtmlBlock => $this->html->lines($block, $width, $this->state),
            $block instanceof ListBlock => $this->containers->list($block, $width),
            default => $block instanceof BlockQuote ? $this->containers->quote($block, $width) : [], // the blocks a container holds: a quote, or a footnote kept for the end
        };
        $keep = $block instanceof CodeBlock || $block instanceof ListBlock || $block instanceof BlockQuote || $block instanceof HtmlBlock || $block instanceof Table;

        return $keep ? $lines : \array_map(static fn (PageLine $line): PageLine => $line->withOrigin($origin), $lines);
    }

    /**
     * A paragraph: its text wrapped, or the image it is nothing but.
     *
     * @param list<Fragment> $fragments
     *
     * @return list<PageLine>
     */
    private function paragraph(array $fragments, int $width): array
    {
        return $this->images->of($fragments, $width, $this->state) ?? $this->wrap($fragments, $width);
    }

    /**
     * @param list<Fragment> $fragments
     *
     * @return list<PageLine>
     */
    public function wrap(array $fragments, int $width): array
    {
        if ([] === $fragments) {
            return [];
        }

        return $this->wrapper->wrap($fragments, $width, new PageLine(), new PageLine());
    }

    /**
     * Terminals have no font sizes, so levels are drawn as a typographic
     * hierarchy: H1 bold in the emphasis colour over a `═` rule, H2 bold and
     * bright over a `─` rule — both the page's full width, as GitHub draws
     * them — H3 and H4 bold, H5 bold and muted, H6 muted.
     *
     * @return list<PageLine>
     */
    private function heading(Heading $heading, int $width): array
    {
        $style = match ($heading->level) {
            1 => new InlineStyle(InlineStyle::BOLD, Role::Emphasis),
            2 => new InlineStyle(InlineStyle::BOLD, Role::Bright),
            3, 4 => new InlineStyle(InlineStyle::BOLD),
            5 => new InlineStyle(InlineStyle::BOLD | InlineStyle::MUTED),
            default => new InlineStyle(InlineStyle::MUTED),
        };
        $lines = $this->wrap($this->inlines->of($heading->inlines, $style), $width);
        if ([] === $lines) {
            $lines = [new PageLine()];
        }
        $lines[0] = $lines[0]->withAnchor($this->state->slug(InlineFragments::plainText($heading->inlines)));
        if ($heading->level <= 2) {
            $rule = 1 === $heading->level
                ? $this->pen->role(\str_repeat(Glyphs::MD_HEADING_RULE, \max(0, $width)), Role::Bright)
                : $this->pen->role(\str_repeat(Glyphs::MD_RULE, \max(0, $width)), Role::Muted);
            $lines[] = new PageLine($rule, $width);
        }

        return $lines;
    }

    /**
     * The footnotes referenced on the page, in the order of their first
     * reference, under a rule: each numbered, ending in a `↩` back to it.
     *
     * @return list<PageLine>
     */
    public function footnotes(int $width): array
    {
        $lines = [];
        for ($index = 0; $index < \count($this->state->footnoteNumbers); ++$index) {
            $label = \array_keys($this->state->footnoteNumbers)[$index];
            $definition = $this->state->footnotes[$label] ?? null;
            if (null !== $definition) {
                \array_push($lines, ...$this->containers->footnote($definition, $index + 1, $width));
            }
        }
        if ([] === $lines) {
            return [];
        }
        $origin = $lines[0]->origin;

        return [PageLine::blank($origin), new PageLine($this->pen->role(\str_repeat(Glyphs::MD_RULE, \max(0, $width)), Role::Muted), $width, $origin), ...$lines];
    }
}
