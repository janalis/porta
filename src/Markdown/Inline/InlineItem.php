<?php

declare(strict_types=1);

namespace Porta\Markdown\Inline;

/**
 * An inline node while the inline parser still rearranges them: a doubly
 * linked list, so emphasis and links can gather the items between their
 * delimiters. $start and $end are byte positions in the text being parsed.
 * {@see InlineFreezer} turns the finished tree into immutable nodes.
 */
final class InlineItem
{
    public ?InlineItem $parent = null;
    public ?InlineItem $first = null;
    public ?InlineItem $last = null;
    public ?InlineItem $prev = null;
    public ?InlineItem $next = null;
    public string $destination = '';
    public string $title = '';

    public function __construct(
        public readonly ItemKind $kind,
        public string $literal,
        public int $start,
        public int $end,
    ) {}

    public function appendChild(self $child): void
    {
        $child->unlink();
        $child->parent = $this;
        if (null === $this->last) {
            $this->first = $child;
            $this->last = $child;

            return;
        }
        $this->last->next = $child;
        $child->prev = $this->last;
        $this->last = $child;
    }

    public function insertAfter(self $sibling): void
    {
        $sibling->unlink();
        $sibling->next = $this->next;
        $sibling->prev = $this;
        $sibling->parent = $this->parent;
        if (null !== $this->next) {
            $this->next->prev = $sibling;
        }
        if (null === $this->next && null !== $this->parent) {
            $this->parent->last = $sibling;
        }
        $this->next = $sibling;
    }

    public function unlink(): void
    {
        if (null !== $this->prev) {
            $this->prev->next = $this->next;
        }
        if (null === $this->prev && null !== $this->parent) {
            $this->parent->first = $this->next;
        }
        if (null !== $this->next) {
            $this->next->prev = $this->prev;
        }
        if (null === $this->next && null !== $this->parent) {
            $this->parent->last = $this->prev;
        }
        $this->parent = null;
        $this->prev = null;
        $this->next = null;
    }

    /** Move every sibling after this one, up to (not including) $stop, into $container. */
    public function moveFollowingInto(self $container, ?self $stop): void
    {
        $item = $this->next;
        while (null !== $item && $item !== $stop) {
            $next = $item->next;
            $container->appendChild($item);
            $item = $next;
        }
    }
}
