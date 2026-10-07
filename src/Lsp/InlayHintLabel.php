<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Support\AnsiText;

/**
 * The text an LSP `InlayHint` puts on screen, and the margins around it.
 *
 * A hint is virtual text spliced between two characters, so its edges matter:
 * a server says "keep me off the code" either with the `paddingLeft` /
 * `paddingRight` flags or by starting the label with a space — phpantom sends
 * `" 46 references"` after a class name. Either way that side gets a margin.
 * Trimming the label without keeping the margin, as the mapper once did,
 * glued it to the name.
 *
 * The label is the server's text spliced straight into a row, so control
 * characters go: collapsing whitespace does not remove an escape byte.
 */
final class InlayHintLabel
{
    /**
     * The label with its inner whitespace collapsed, and which sides want a
     * margin, or null when there is nothing to show.
     *
     * @param array<string, mixed> $hint
     *
     * @return array{text: string, left: bool, right: bool}|null
     */
    public static function of(array $hint): ?array
    {
        /** @var scalar|array<array-key, mixed> $label */
        $label = $hint['label'] ?? '';
        if (\is_array($label)) {
            // label is an InlayHintLabelPart[]; concatenate the parts' text.
            $label = \implode('', \array_map(static fn ($part): string => \is_array($part) ? (string) ($part['value'] ?? '') : (string) $part, $label));
        }
        $text = AnsiText::oneLine((string) $label);
        if ('' === $text) {
            return null;
        }
        // The edges as sent: a leading or trailing whitespace is a margin.
        $left = true === ($hint['paddingLeft'] ?? null) || 1 === \preg_match('/^\s/u', (string) $label);
        $right = true === ($hint['paddingRight'] ?? null) || 1 === \preg_match('/\s$/u', (string) $label);

        return ['text' => $text, 'left' => $left, 'right' => $right];
    }
}
