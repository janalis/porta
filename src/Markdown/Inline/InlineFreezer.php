<?php

declare(strict_types=1);

namespace Porta\Markdown\Inline;

use Porta\Markdown\MappedText;
use Porta\Markdown\Node\CodeSpan;
use Porta\Markdown\Node\Emoji;
use Porta\Markdown\Node\Emphasis;
use Porta\Markdown\Node\FootnoteReference;
use Porta\Markdown\Node\HardBreak;
use Porta\Markdown\Node\HtmlInline;
use Porta\Markdown\Node\Image;
use Porta\Markdown\Node\Inline;
use Porta\Markdown\Node\Link;
use Porta\Markdown\Node\SoftBreak;
use Porta\Markdown\Node\Strikethrough;
use Porta\Markdown\Node\Strong;
use Porta\Markdown\Node\Text;

/**
 * The inline parser's linked items as immutable nodes: adjacent text merged
 * into one run, every range mapped back to the source.
 */
final readonly class InlineFreezer
{
    public function __construct(
        private MappedText $text,
    ) {}

    /** @return list<Inline> */
    public function freeze(InlineItem $parent): array
    {
        $nodes = [];
        for ($item = $parent->first; null !== $item; $item = $item->next) {
            foreach ($this->node($item) as $node) {
                $last = $nodes[\count($nodes) - 1] ?? null;
                if ($node instanceof Text && $last instanceof Text) {
                    $nodes[\count($nodes) - 1] = new Text($last->literal.$node->literal, $last->range->union($node->range));
                    continue;
                }
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /** @return list<Inline> */
    private function node(InlineItem $item): array
    {
        $range = $this->text->range($item->start, $item->end);

        return match ($item->kind) {
            ItemKind::Text => '' === $item->literal ? [] : [new Text($item->literal, $range)],
            ItemKind::SoftBreak => [new SoftBreak($range)],
            ItemKind::HardBreak => [new HardBreak($range)],
            ItemKind::Code => [new CodeSpan($item->literal, $range)],
            ItemKind::Html => [new HtmlInline($item->literal, $range)],
            ItemKind::Emphasis => [new Emphasis($this->freeze($item), $range)],
            ItemKind::Strong => [new Strong($this->freeze($item), $range)],
            ItemKind::Strikethrough => [new Strikethrough($this->freeze($item), $range)],
            ItemKind::Link => [new Link($item->destination, $item->title, $this->freeze($item), $range)],
            ItemKind::Autolink => [new Link($item->destination, '', $this->freeze($item), $range, autolink: true)],
            ItemKind::Image => [new Image($item->destination, $item->title, $this->freeze($item), $range)],
            ItemKind::FootnoteReference => [new FootnoteReference($item->literal, $range)],
            ItemKind::Emoji => [new Emoji($item->title, $item->literal, $range)],
        };
    }
}
