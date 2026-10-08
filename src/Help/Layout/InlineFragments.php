<?php

declare(strict_types=1);

namespace Porta\Help\Layout;

use Porta\Markdown\Inline\LinkSyntax;
use Porta\Markdown\Node\CodeSpan;
use Porta\Markdown\Node\Emoji;
use Porta\Markdown\Node\Emphasis;
use Porta\Markdown\Node\FootnoteReference;
use Porta\Markdown\Node\HardBreak;
use Porta\Markdown\Node\HtmlInline;
use Porta\Markdown\Node\Image;
use Porta\Markdown\Node\Inline;
use Porta\Markdown\Node\Link;
use Porta\Markdown\Node\Node;
use Porta\Markdown\Node\SoftBreak;
use Porta\Markdown\Node\Strikethrough;
use Porta\Markdown\Node\Strong;
use Porta\Markdown\Node\Text;

/**
 * A block's inline nodes as the styled fragments the page draws: emphasis as
 * bold, italic or struck runs, code tinted, links underlined and grouped,
 * footnote references as `[n]`, an image as its alt text leading to the file,
 * and the inline HTML a README uses (`<kbd>`, `<br>`, `<b>`, `<a href>`…)
 * through {@see HtmlInlineTags}. Every fragment keeps the source it came from.
 */
final class InlineFragments
{
    /** A code span's padding inside its tint: a no-break space, so wrapping never drops it. */
    private const string PAD = "\u{00A0}";

    private InlineStyle $style;
    private ?string $target = null;
    private int $group = -1;
    private readonly HtmlInlineTags $html;

    public function __construct(
        private readonly LayoutState $state,
    ) {
        $this->style = new InlineStyle();
        $this->html = new HtmlInlineTags();
    }

    /**
     * @param list<Inline> $inlines
     *
     * @return list<Fragment>
     */
    public function of(array $inlines, InlineStyle $base = new InlineStyle()): array
    {
        $this->style = $base;
        $this->target = null;
        $this->group = -1;
        $this->html->reset();

        return $this->all($inlines);
    }

    /**
     * @param list<Node> $inlines
     *
     * @return list<Fragment>
     */
    private function all(array $inlines): array
    {
        $fragments = [];
        foreach ($inlines as $inline) {
            \array_push($fragments, ...$this->one($inline));
        }

        return $fragments;
    }

    /** @return list<Fragment> */
    private function one(Node $inline): array
    {
        return match (true) {
            $inline instanceof Text => [$this->text($inline->literal, $inline)],
            $inline instanceof SoftBreak => [$this->fragment(' ', $inline)],
            $inline instanceof HardBreak => [Fragment::lineBreak()],
            $inline instanceof CodeSpan => [$this->styled(InlineStyle::CODE, self::PAD.\str_replace("\t", replace: ' ', subject: $inline->literal).self::PAD, $inline)],
            $inline instanceof Emphasis => $this->nested(InlineStyle::ITALIC, $inline),
            $inline instanceof Strong => $this->nested(InlineStyle::BOLD, $inline),
            $inline instanceof Strikethrough => $this->nested(InlineStyle::STRIKE, $inline),
            $inline instanceof Link => $this->link($inline->destination, $inline),
            $inline instanceof Image => [$this->image($inline)],
            $inline instanceof FootnoteReference => [$this->footnote($inline)],
            $inline instanceof Emoji => [$this->fragment($inline->glyph, $inline)],
            $inline instanceof HtmlInline => $this->htmlTag($inline),
            default => [],
        };
    }

    private function text(string $literal, Node $node): Fragment
    {
        $text = \str_replace("\t", replace: ' ', subject: $literal);

        return new Fragment($text, $this->style, $this->target, $this->group, $node->range, $text === $literal && $node->range->length() === \strlen($literal));
    }

    private function fragment(string $text, Node $node): Fragment
    {
        return new Fragment($text, $this->style, $this->target, $this->group, $node->range);
    }

    private function styled(int $flag, string $text, Node $node): Fragment
    {
        return new Fragment($text, $this->style->with($flag), $this->target, $this->group, $node->range);
    }

    /** @return list<Fragment> */
    private function nested(int $flag, Node $node): array
    {
        $outer = $this->style;
        $this->style = $outer->with($flag);
        $fragments = $this->all($node->children());
        $this->style = $outer;

        return $fragments;
    }

    /** @return list<Fragment> */
    private function link(string $destination, Node $node): array
    {
        if (null !== $this->target) {
            return $this->all($node->children()); // a link inside a link reads as its text
        }
        [$outerStyle, $this->style] = [$this->style, $this->style->with(InlineStyle::LINK)];
        [$this->target, $this->group] = [$destination, $this->state->group()];
        $fragments = $this->all($node->children());
        [$this->style, $this->target, $this->group] = [$outerStyle, null, -1];

        return $fragments;
    }

    private function image(Image $image): Fragment
    {
        return $this->picture(self::plainText($image->inlines), new ImageRef($image->destination), $image);
    }

    /** An image the page cannot draw: its alt text, leading to the file (or to the link around it). */
    private function picture(string $alt, ImageRef $image, Node $node): Fragment
    {
        $alt = \trim($alt);
        $label = '' === $alt ? \basename(\explode('?', $image->source)[0]) : $alt;
        $inLink = null !== $this->target;

        return new Fragment(
            '['.$label.']',
            $this->style->with(InlineStyle::LINK)->with(InlineStyle::MUTED),
            $inLink ? $this->target : $image->source,
            $inLink ? $this->group : $this->state->group(),
            $node->range,
            image: $image,
        );
    }

    private function footnote(FootnoteReference $reference): Fragment
    {
        $number = $this->state->footnote($reference->label);
        // A label matches its definition as a link label does, case aside.
        $label = LinkSyntax::normalize($reference->label);
        $anchor = 'fnref-'.$label;

        return new Fragment('['.$number.']', $this->style->with(InlineStyle::LINK), '#fn-'.$label, $this->state->group(), $reference->range, anchor: $anchor);
    }

    /** @return list<Fragment> */
    private function htmlTag(HtmlInline $tag): array
    {
        $parsed = HtmlInlineTags::parse($tag->literal);
        if (null !== $parsed && 'img' === $parsed[0]) {
            return [$this->picture($parsed[2]['alt'] ?? '', new ImageRef($parsed[2]['src'] ?? '', $parsed[2]['width'] ?? ''), $tag)];
        }
        $effect = $this->html->apply($tag->literal, $this->style, $this->target);
        if (null === $effect) {
            return [new Fragment($tag->literal, $this->style->with(InlineStyle::MUTED), $this->target, $this->group, $tag->range)];
        }
        [$this->style, $target, $fragments] = $effect;
        if ($target !== $this->target) {
            $this->target = $target;
            $this->group = null === $target ? -1 : $this->state->group();
        }

        return $fragments;
    }

    /** @param list<Node> $inlines */
    public static function plainText(array $inlines): string
    {
        $text = '';
        foreach ($inlines as $inline) {
            $text .= match (true) {
                $inline instanceof Text, $inline instanceof CodeSpan => $inline->literal,
                $inline instanceof SoftBreak, $inline instanceof HardBreak => ' ',
                $inline instanceof Emoji => $inline->glyph,
                default => self::plainText($inline->children()),
            };
        }

        return $text;
    }
}
