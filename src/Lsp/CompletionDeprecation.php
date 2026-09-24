<?php

declare(strict_types=1);

namespace Porta\Lsp;

/**
 * Whether a raw completion item is deprecated: CompletionItemTag.Deprecated —
 * the one tag the protocol defines — or the older boolean a server may still
 * send instead. Kept out of {@see LspMapper}, whose branching is at its limit.
 */
final class CompletionDeprecation
{
    public const int TAG = 1;

    /** @param array<string, mixed> $item */
    public static function of(array $item): bool
    {
        $tags = \is_array($item['tags'] ?? null) ? $item['tags'] : [];

        return \in_array(self::TAG, $tags, strict: true) || true === ($item['deprecated'] ?? null);
    }
}
