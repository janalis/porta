<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * One completion-popup entry, mapped from a raw LSP CompletionItem by
 * {@see \Porta\Lsp\LspMapper::mapCompletionItems()}: the display strings, the
 * plain insert text (placeholders stripped), the original snippet body when
 * the server marked one, the inline auto-import edits, and the server's raw
 * item for the deferred completionItem/resolve round-trip.
 */
final class CompletionItem
{
    /**
     * @param list<array<array-key, mixed>> $additionalTextEdits inline auto-import edits applied with the completion
     * @param array<string, mixed>          $raw                 the server's original item, for completionItem/resolve
     */
    public function __construct(
        public readonly string $label,
        public readonly string $insertText,
        public readonly string $kind,
        public readonly string $detail,
        public readonly array $additionalTextEdits = [],
        public readonly ?string $snippet = null,
        public readonly array $raw = [],
    ) {}
}
