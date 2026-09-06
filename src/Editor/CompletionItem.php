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
        return new self($this->label, $this->insertText, $this->kind, $detail, $this->additionalTextEdits, $this->snippet, $this->raw, $this->documentation);
    }

    /** The same item with the docblock the server sent — like {@see withDetail()}, usually only on resolve. */
    public function withDocumentation(string $documentation): self
    {
        return new self($this->label, $this->insertText, $this->kind, $this->detail, $this->additionalTextEdits, $this->snippet, $this->raw, $documentation);
    }
}
