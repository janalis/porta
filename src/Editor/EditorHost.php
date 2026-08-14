<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * What the editing-verb dispatcher ({@see EditorActions}) needs from the
 * widget hosting it: repaint/notify plumbing, the prompt and jump primitives,
 * the outbound LSP hooks and the per-language editing facts. Implemented by
 * the editor widget; tests record calls through a fake.
 */
interface EditorHost
{
    public function invalidate(): void;

    /** Fire the app-level change hooks (dirty marker, LSP didChange, …). */
    public function notifyChange(): void;

    // @mago-expect lint:no-boolean-flag-parameter -- the bool IS the domain here, not a hidden second responsibility.
    public function openPrompt(string $label, string $initial, callable $onSubmit, bool $danger = false): void;

    public function jumpTo(int $line, int $utf16Col): void;

    public function setSignatureHint(?string $hint): void;

    /** Copied text → the OSC 52 clipboard hook. */
    public function fireCopy(string $text): void;

    public function fireSignatureHelp(int $line, int $utf16Col): void;

    /** The handler validates via prepareRename, then owns the prompt + rename. */
    public function requestRename(int $line, int $utf16Col, string $word): void;

    /** Viewport rows, for page up/down. */
    public function contentRows(): int;

    public function commentPrefix(): ?string;

    public function autoPairsEnabled(): bool;

    public function isInsertable(string $data): bool;
}
