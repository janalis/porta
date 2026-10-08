<?php

declare(strict_types=1);

namespace Porta\Markdown\Block;

use Porta\Markdown\Inline\LinkReference;
use Porta\Markdown\Inline\LinkSyntax;

/**
 * Link reference definitions (`[label]: /url "title"`) at the start of a
 * paragraph. They are not content: the paragraph loses them, and the first
 * definition of a label is the one reference links resolve to.
 */
final class ReferenceDefinitions
{
    /**
     * The length of the definition at the start of $text, recorded into
     * $references unless the label is already defined; 0 when there is none.
     *
     * @param array<string, LinkReference> $references
     */
    public static function take(string $text, array &$references): int
    {
        $label = LinkSyntax::label($text, 0);
        if (null === $label || ':' !== ($text[$label[1]] ?? '')) {
            return 0;
        }
        $destination = LinkSyntax::destination($text, LinkSyntax::skipSpace($text, $label[1] + 1));
        if (null === $destination) {
            return 0;
        }
        $end = self::titleAndLineEnd($text, $destination[1]);
        if (null === $end) {
            return 0;
        }
        // The label scanner refuses a blank label, so no key normalises to ''.
        $references[LinkSyntax::normalize($label[0])] ??= new LinkReference($destination[0], $end[1]);

        return $end[0];
    }

    /**
     * After the destination: an optional title, then nothing but spaces to the
     * end of the line. A title that leaves text after it is dropped and the
     * definition ends with the destination's line instead.
     *
     * @return array{int, string}|null where the definition ends, and its title
     */
    private static function titleAndLineEnd(string $text, int $afterDestination): ?array
    {
        $beforeTitle = $afterDestination;
        $at = LinkSyntax::skipSpace($text, $beforeTitle);
        $title = $at !== $beforeTitle ? LinkSyntax::title($text, $at) : null;
        if (null !== $title) {
            $end = self::lineEnd($text, $title[1]);
            if (null !== $end) {
                return [$end, $title[0]];
            }
        }
        $end = self::lineEnd($text, $beforeTitle);

        return null === $end ? null : [$end, ''];
    }

    /** The offset after the line ending, when only spaces stand between $at and it. */
    private static function lineEnd(string $text, int $at): ?int
    {
        $m = [];

        return 1 === \preg_match('/\G[ \t]*(?:\n|$)/', $text, $m, flags: 0, offset: $at) ? $at + \strlen($m[0]) : null;
    }
}
