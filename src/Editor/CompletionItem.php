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
final readonly class CompletionItem
{
    /**
     * @param list<array<array-key, mixed>> $additionalTextEdits inline auto-import edits applied with the completion
     * @param array<string, mixed>          $raw                 the server's original item, for completionItem/resolve
     */
    public function __construct(
        public string $label,
        public string $insertText,
        public string $kind,
        public string $detail,
        public array $additionalTextEdits = [],
        public ?string $snippet = null,
        public array $raw = [],
    ) {}
}
