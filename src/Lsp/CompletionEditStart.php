<?php

declare(strict_types=1);

namespace Porta\Lsp;

/**
 * Where a completion item's textEdit starts: a TextEdit's range, or an
 * InsertReplaceEdit's `insert` range — the one the spec has a client use by
 * default. What the accept replaces runs from there to the caret, not only the
 * word before it, which stops at `-`, `\`, `@` or `.`: `background-co` became
 * `background-background-color`.
 */
final class CompletionEditStart
{
    /**
     * @param array<array-key, mixed> $item a CompletionItem off the wire
     *
     * @return array{line: int, character: int}|null
     */
    public static function of(array $item): ?array
    {
        $edit = \is_array($item['textEdit'] ?? null) ? $item['textEdit'] : [];
        // @mago-expect analysis:mixed-assignment -- a decoded range, narrowed on the next line
        $range = $edit['range'] ?? $edit['insert'] ?? null;
        // @mago-expect analysis:mixed-assignment -- narrowed in the return
        $start = \is_array($range) ? $range['start'] ?? null : null;

        return (
            \is_array($start) && \is_int($start['line'] ?? null) && \is_int($start['character'] ?? null)
                ? ['line' => $start['line'], 'character' => $start['character']]
                : null
        );
    }
}
