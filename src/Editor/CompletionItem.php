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
        public string $documentation = '',
        /** The server marked it deprecated: drawn struck through, still offered. */
        public bool $deprecated = false,
        /**
         * Where the server's textEdit starts (UTF-16, as sent), when it gave
         * one: the text from there to the caret is what the item replaces —
         * not only the word before the caret, which stops at `-`, `\`, `@` or
         * `.`, and left `background-` in front of `background-color`.
         *
         * @var array{line: int, character: int}|null
         */
        public ?array $replaceFrom = null,
    ) {}

    /**
     * The same item with the type the server only gives on resolve.
     *
     * Measured, and it is why this exists: phpactor's completion RESPONSE
     * carries label, kind and insertText and nothing else — no `detail` at all
     * — while `completionItem/resolve` answers `pub greet(string $who): string`
     * for the same item. A type column fed only by the first response is empty
     * against that server.
     */
    public function withDetail(string $detail): self
    {
        return clone($this, ['detail' => $detail]);
    }

    /** The same item with the docblock the server sent — like {@see withDetail()}, usually only on resolve. */
    public function withDocumentation(string $documentation): self
    {
        return clone($this, ['documentation' => $documentation]);
    }
}
