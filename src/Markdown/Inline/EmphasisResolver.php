<?php

declare(strict_types=1);

namespace Porta\Markdown\Inline;

/**
 * The spec's "process emphasis" procedure: walk the delimiter stack from the
 * bottom, match each closer with the nearest compatible opener below it, and
 * wrap what lies between in emphasis, strong emphasis or a strikethrough.
 * The openers-bottom table keeps a long line of unmatched runs linear.
 */
final class EmphasisResolver
{
    public ?Delimiter $top = null;

    public function push(Delimiter $delimiter): void
    {
        $delimiter->previous = $this->top;
        if (null !== $this->top) {
            $this->top->next = $delimiter;
        }
        $this->top = $delimiter;
    }

    private function remove(Delimiter $delimiter): void
    {
        if (null !== $delimiter->previous) {
            $delimiter->previous->next = $delimiter->next;
        }
        if (null === $delimiter->next) {
            $this->top = $delimiter->previous;

            return;
        }
        $delimiter->next->previous = $delimiter->previous;
    }

    public function process(?Delimiter $bottom): void
    {
        /** @var array<string, Delimiter|null> $openersBottom */
        $openersBottom = [];
        $closer = $this->top;
        while (null !== $closer && $closer->previous !== $bottom) {
            $closer = $closer->previous;
        }
        while (null !== $closer) {
            if (!$closer->canClose) {
                $closer = $closer->next;
                continue;
            }
            $key = $closer->char.('~' === $closer->char ? '' : ($closer->canOpen ? 3 : 0) + ($closer->original % 3));
            $opener = $this->findOpener($closer, $bottom, $openersBottom[$key] ?? null);
            if (null !== $opener) {
                $closer = $this->match($opener, $closer);
                continue;
            }
            $openersBottom[$key] = $closer->previous;
            $next = $closer->next;
            if (!$closer->canOpen) {
                $this->remove($closer);
            }
            $closer = $next;
        }
        while (null !== $this->top && $this->top !== $bottom) {
            $this->remove($this->top);
        }
    }

    private function findOpener(Delimiter $closer, ?Delimiter $bottom, ?Delimiter $stop): ?Delimiter
    {
        for ($opener = $closer->previous; null !== $opener && $opener !== $bottom && $opener !== $stop; $opener = $opener->previous) {
            if ($opener->char !== $closer->char || !$opener->canOpen) {
                continue;
            }
            if ('~' === $closer->char) {
                if ($opener->count === $closer->count) {
                    return $opener;
                }
                continue;
            }
            $oddMatch = ($closer->canOpen || $opener->canClose) && 0 !== ($closer->original % 3) && 0 === (($opener->original + $closer->original) % 3);
            if (!$oddMatch) {
                return $opener;
            }
        }

        return null;
    }

    /** Wrap what lies between $opener and $closer; returns the delimiter to look at next. */
    private function match(Delimiter $opener, Delimiter $closer): ?Delimiter
    {
        $use = match (true) {
            '~' === $closer->char => $closer->count,
            $closer->count >= 2 && $opener->count >= 2 => 2,
            default => 1,
        };
        $openItem = $opener->item;
        $closeItem = $closer->item;
        $opener->count -= $use;
        $closer->count -= $use;
        $openItem->literal = \substr($openItem->literal, offset: 0, length: $opener->count);
        $openItem->end -= $use;
        $closeItem->literal = \substr($closeItem->literal, $use);
        $closeItem->start += $use;
        $kind = match (true) {
            '~' === $closer->char => ItemKind::Strikethrough,
            1 === $use => ItemKind::Emphasis,
            default => ItemKind::Strong,
        };
        $wrapper = new InlineItem($kind, '', $openItem->end, $closeItem->start);
        $openItem->moveFollowingInto($wrapper, $closeItem);
        $openItem->insertAfter($wrapper);
        // Every delimiter between the two is now inside the wrapper, plain text.
        for ($between = $closer->previous; null !== $between && $between !== $opener; $between = $between->previous) {
            $this->remove($between);
        }
        if (0 === $opener->count) {
            $openItem->unlink();
            $this->remove($opener);
        }
        if (0 === $closer->count) {
            $next = $closer->next;
            $closeItem->unlink();
            $this->remove($closer);

            return $next;
        }

        return $closer;
    }
}
