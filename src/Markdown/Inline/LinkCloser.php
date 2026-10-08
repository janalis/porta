<?php

declare(strict_types=1);

namespace Porta\Markdown\Inline;

/**
 * What a `]` makes of the bracket it closes: an inline link
 * (`](dest "title")`), a full reference (`][label]`), or a collapsed or
 * shortcut one (`][]`, `]`) whose own text is the label — or nothing.
 */
final class LinkCloser
{
    /**
     * @param int $after the offset just past the `]`
     *
     * @return array{string, string, int}|null the destination, the title and where the link ends
     */
    public function close(string $subject, int $after, Bracket $opener, InlineContext $context): ?array
    {
        return $this->inline($subject, $after) ?? $this->reference($subject, $after, $opener, $context);
    }

    /** @return array{string, string, int}|null */
    private function inline(string $subject, int $after): ?array
    {
        if ('(' !== ($subject[$after] ?? '')) {
            return null;
        }
        $at = LinkSyntax::skipSpace($subject, $after + 1);
        if (')' === ($subject[$at] ?? '')) {
            return ['', '', $at + 1];
        }
        $destination = LinkSyntax::destination($subject, $at);
        if (null === $destination) {
            return null;
        }
        $at = LinkSyntax::skipSpace($subject, $destination[1]);
        $title = '';
        if ($at > $destination[1]) {
            $found = LinkSyntax::title($subject, $at);
            if (null !== $found) {
                $title = $found[0];
                $at = LinkSyntax::skipSpace($subject, $found[1]);
            }
        }

        return ')' === ($subject[$at] ?? '') ? [$destination[0], $title, $at + 1] : null;
    }

    /** @return array{string, string, int}|null */
    private function reference(string $subject, int $after, Bracket $opener, InlineContext $context): ?array
    {
        $m = [];
        $end = $after;
        $label = null;
        // @noinspection NotOptimalRegularExpressionsInspection -- `\\\\.` is a backslash and ANY character, newline too: the /s is needed
        if (1 === \preg_match('/\G\[((?:[^\\\\\[\]]|\\\\.){0,999})\]/s', $subject, $m, flags: 0, offset: $after)) {
            $end = $after + \strlen($m[0]);
            $label = '' === $m[1] ? null : $m[1];
        }
        // A collapsed (`[]`) or shortcut reference is labelled by the link's own text.
        $label ??= $opener->bracketAfter ? null : \substr($subject, $opener->index + 1, $after - $opener->index - 2);
        if (null === $label) {
            return null;
        }
        $reference = $context->references[LinkSyntax::normalize($label)] ?? null;
        if (null === $reference) {
            return null;
        }

        return [$reference->destination, $reference->title, $end];
    }
}
