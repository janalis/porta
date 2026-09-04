<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * The signature popup's content: the label the server sent, and which slice of
 * it is the argument being typed.
 *
 * One value rather than two fields on {@see \Porta\Widget\CodeEditorWidget},
 * which is the codebase's widest class and already pins `too-many-properties`.
 * They are one fact anyway — an active range without its label means nothing —
 * so carrying them apart only invited them to drift.
 */
final readonly class SignatureHint
{
    /**
     * @param array{0: int, 1: int}|null $activeRange byte slice of $label holding the active argument
     */
    public function __construct(
        public string $label,
        public ?array $activeRange = null,
    ) {}
}
