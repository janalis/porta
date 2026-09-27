<?php

declare(strict_types=1);

namespace Porta\Lsp;

/**
 * The text an LSP `InlayHint` puts on screen.
 *
 * A hint is virtual text spliced between two characters, so its edges matter:
 * a server says "keep me off the code" either with the `paddingLeft` /
 * `paddingRight` flags or by starting the label with a space — phpantom sends
 * `" 46 references"` after a class name. Either way one space is kept on that
 * side. Trimming the label, as the mapper once did, glued it to the name.
 */
final class InlayHintLabel
{
    /**
     * The label with its inner whitespace collapsed and its requested padding,
     * or null when there is nothing to show.
     *
     * @param array<string, mixed> $hint
     */
    public static function of(array $hint): ?string
    {
        /** @var scalar|array<array-key, mixed> $label */
        $label = $hint['label'] ?? '';
        if (\is_array($label)) {
            // label is an InlayHintLabelPart[]; concatenate the parts' text.
            $label = \implode('', \array_map(static fn ($part): string => \is_array($part) ? (string) ($part['value'] ?? '') : (string) $part, $label));
        }
        $collapsed = (string) \preg_replace('/\s+/u', replacement: ' ', subject: (string) $label);
        $text = \trim($collapsed);
        if ('' === $text) {
            return null;
        }
        $left = true === ($hint['paddingLeft'] ?? null) || \str_starts_with($collapsed, ' ');
        $right = true === ($hint['paddingRight'] ?? null) || \str_ends_with($collapsed, ' ');

        return ($left ? ' ' : '').$text.($right ? ' ' : '');
    }
}
