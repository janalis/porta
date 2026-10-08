<?php

declare(strict_types=1);

namespace Porta\Markdown\Inline;

/**
 * GFM's bare links, found once everything else is parsed: in runs of text
 * written as-is (no escape or entity inside), outside links and images, so a
 * `www.` in a link's own text stays its text and the link stays whole. A run
 * may span pieces the inline parser kept apart, such as an `_` that opened no
 * emphasis.
 */
final readonly class AutolinkPass
{
    public function __construct(
        private string $subject,
    ) {}

    /** @noinspection NullPointerExceptionInspection -- custos misses the narrowing by the loop condition */
    public function apply(InlineItem $parent): void
    {
        $run = [];
        $item = $parent->first;
        while (null !== $item) {
            $next = $item->next;
            if ($this->verbatim($item) && ([] === $run || $run[\count($run) - 1]->end === $item->start)) {
                $run[] = $item;
                $item = $next;
                continue;
            }
            $this->link($run);
            $run = $this->verbatim($item) ? [$item] : [];
            if (\in_array($item->kind, [ItemKind::Emphasis, ItemKind::Strong, ItemKind::Strikethrough], strict: true)) {
                $this->apply($item);
            }
            $item = $next;
        }
        $this->link($run);
    }

    private function verbatim(InlineItem $item): bool
    {
        return ItemKind::Text === $item->kind && \substr($this->subject, $item->start, $item->end - $item->start) === $item->literal;
    }

    /**
     * Replace a run with its text split around the links found in it.
     *
     * @param list<InlineItem> $run
     */
    private function link(array $run): void
    {
        if ([] === $run) {
            return;
        }
        $start = $run[0]->start;
        $end = $run[\count($run) - 1]->end;
        $pieces = $this->pieces($start, $end);
        if ([] === \array_filter($pieces, static fn (InlineItem $piece): bool => ItemKind::Autolink === $piece->kind)) {
            return;
        }
        $anchor = $run[0];
        foreach ($pieces as $piece) {
            $anchor->insertAfter($piece);
            $anchor = $piece;
        }
        foreach ($run as $item) {
            $item->unlink();
        }
    }

    /** @return list<InlineItem> the text from $start to $end, links and the text between them */
    private function pieces(int $start, int $end): array
    {
        $text = \substr($this->subject, offset: 0, length: $end);
        // Every bare link has an `@`, a `www.` or a `://`: a run without one
        // is text, read once rather than tried at every word.
        if (1 !== \preg_match('/@|www\.|:\/\//i', $text, offset: $start)) {
            return [$this->text($start, $end)];
        }
        $pieces = [];
        $textStart = $start;
        for ($at = $start; $at < $end; ++$at) {
            $before = 0 === $at ? "\n" : $this->subject[$at - 1];
            $link = \str_contains(ExtendedAutolinks::BOUNDARY, $before) ? ExtendedAutolinks::at($text, $at) : null;
            if (null === $link) {
                continue;
            }
            if ($at > $textStart) {
                $pieces[] = $this->text($textStart, $at);
            }
            $pieces[] = $this->autolink($link, $at);
            $at += $link[2] - 1;
            $textStart = $at + 1;
        }
        if ($textStart < $end) {
            $pieces[] = $this->text($textStart, $end);
        }

        return $pieces;
    }

    private function text(int $start, int $end): InlineItem
    {
        return new InlineItem(ItemKind::Text, \substr($this->subject, $start, $end - $start), $start, $end);
    }

    /** @param array{string, string, int} $link */
    private function autolink(array $link, int $at): InlineItem
    {
        [$text, $destination, $length] = $link;
        $item = new InlineItem(ItemKind::Autolink, '', $at, $at + $length);
        $item->destination = $destination;
        $item->appendChild(new InlineItem(ItemKind::Text, $text, $at, $at + $length));

        return $item;
    }
}
