<?php

declare(strict_types=1);

namespace Porta\Help\Layout;

use Porta\Markdown\Node\BlockQuote;
use Porta\Markdown\Node\FootnoteDefinition;
use Porta\Markdown\Node\ListBlock;
use Porta\Markdown\Node\ListItem;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The blocks that hold blocks: a list hangs each item's content from its
 * marker (a bullet that changes with depth, a right-aligned number, a task's
 * box — a link that toggles it in the source), a quote puts a bar in front of
 * its content, an alert a coloured bar and a title, and a footnote its number.
 */
final class ContainerLayout
{
    private const array BULLETS = [Glyphs::MD_BULLET, Glyphs::MD_BULLET_2, Glyphs::MD_BULLET_3];

    private int $bulletDepth = 0;

    public function __construct(
        private readonly StylePen $pen,
        private readonly LayoutState $state,
        private readonly BlockLayout $blocks,
        private readonly PageWords $words,
    ) {}

    /** @return list<PageLine> */
    public function list(ListBlock $list, int $width): array
    {
        $numberWidth = \strlen((string) ($list->start + \count($list->items) - 1)) + 1;
        $bullet = self::BULLETS[\min($this->bulletDepth, \count(self::BULLETS) - 1)];
        $this->bulletDepth += $list->ordered ? 0 : 1;
        $lines = [];
        foreach ($list->items as $index => $item) {
            $marker = $list->ordered ? \str_pad(($list->start + $index).$list->marker, $numberWidth, pad_string: ' ', pad_type: \STR_PAD_LEFT) : $bullet;
            $drawn = $this->item($item, $marker, $width, $list->tight ? 0 : 1);
            if ([] !== $lines && !$list->tight) {
                $lines[] = PageLine::blank($lines[\count($lines) - 1]->origin);
            }
            \array_push($lines, ...$drawn);
        }
        $this->bulletDepth -= $list->ordered ? 0 : 1;

        return $lines;
    }

    /** @return list<PageLine> */
    private function item(ListItem $item, string $marker, int $width, int $gap): array
    {
        $prefix = new PageLine($this->pen->role($marker, Role::Muted).' ', AnsiUtils::visibleWidth($marker) + 1);
        if (null !== $item->checked && null !== $item->checkbox) {
            $box = '['.($item->checked ? Glyphs::MD_CHECK : ' ').']';
            $target = 'task:'.$item->checkbox->start.'-'.$item->checkbox->end;
            $painted = $this->pen->role($box, $item->checked ? Role::TabAccent : Role::Muted);
            $prefix = new PageLine($painted.' ', 4, 0, [[0, 3, $target, $this->state->group()]])->after($prefix);
        }
        $origin = $this->state->lineOf($item->range->start);
        $body = $this->blocks->blocks($item->blocks, \max(1, $width - $prefix->width), $gap);

        return $this->hang([] === $body ? [new PageLine('', 0, $origin)] : $body, $prefix, PageLine::spaces($prefix->width));
    }

    /**
     * @param list<PageLine> $lines
     *
     * @return list<PageLine>
     */
    private function hang(array $lines, PageLine $first, PageLine $rest): array
    {
        $hung = [];
        foreach ($lines as $index => $line) {
            $prefix = 0 === $index ? $first : $rest;
            $hung[] = ('' === $line->text && 0 !== $index ? $line : $line->after($prefix))->withOrigin($line->origin);
        }

        return $hung;
    }

    /** @return list<PageLine> */
    public function quote(BlockQuote $quote, int $width): array
    {
        $body = $this->blocks->blocks($quote->blocks, \max(1, $width - 2));
        $origin = $this->state->lineOf($quote->range->start);
        if (null === $quote->alert) {
            $bar = new PageLine($this->pen->role(Glyphs::MD_QUOTE_BAR, Role::Muted).' ', 2);

            return $this->barred([] === $body ? [PageLine::blank($origin)] : $body, $bar);
        }
        $role = $this->words->role($quote->alert);
        $bar = new PageLine($this->pen->role(Glyphs::MD_ALERT_BAR, $role).' ', 2);
        $title = new PageLine(
            $this->pen->paint($this->words->title($quote->alert), new InlineStyle(InlineStyle::BOLD, $role)),
            AnsiUtils::visibleWidth($this->words->title($quote->alert)),
            $origin,
        );

        return $this->barred([$title, ...$body], $bar);
    }

    /**
     * Every line behind the bar, blank ones too, so the bar runs unbroken.
     *
     * @param list<PageLine> $lines
     *
     * @return list<PageLine>
     */
    private function barred(array $lines, PageLine $bar): array
    {
        return \array_map(static fn (PageLine $line): PageLine => $line->after($bar)->withOrigin($line->origin), $lines);
    }

    /** @return list<PageLine> */
    public function footnote(FootnoteDefinition $definition, int $number, int $width): array
    {
        $marker = $number.'.';
        $prefix = new PageLine($this->pen->role($marker, Role::Muted).' ', \strlen($marker) + 1);
        $origin = $this->state->lineOf($definition->range->start);
        $body = $this->blocks->blocks($definition->blocks, \max(1, $width - $prefix->width));
        $body = [] === $body ? [new PageLine('', 0, $origin)] : $body;
        $back = new PageLine(' '.$this->pen->paint(Glyphs::MD_BACKREF, new InlineStyle(InlineStyle::LINK)), 2, 0, [[1, 2, '#fnref-'.$definition->label, $this->state->group()]]);
        $last = \count($body) - 1;
        $body[$last] = $body[$last]->then($back);
        $lines = $this->hang($body, $prefix, PageLine::spaces($prefix->width));
        $lines[0] = $lines[0]->withAnchor('fn-'.$definition->label);

        return $lines;
    }
}
