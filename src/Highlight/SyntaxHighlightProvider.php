<?php

declare(strict_types=1);

namespace Porta\Highlight;

/**
 * A syntax-highlighting backend.
 *
 * Implementations are consumed by {@see CodeHighlighter}, which owns the
 * caching, the document-version bookkeeping, the size cap and the line-splitting
 * — a provider only has to turn text into ANSI-marked text.
 *
 * The contract has two hard rules:
 *
 * - **Never block.** This runs on the way to a render frame. Anything touching
 *   I/O belongs in an asynchronous provider (see the delivery seam added for
 *   out-of-process backends), not in a synchronous one.
 * - **Preserve line structure.** The returned markup must contain exactly as
 *   many `"\n"`-separated lines as the input, and must reduce to the input
 *   byte-for-byte once ANSI codes are stripped. {@see CodeHighlighter} guards
 *   this and falls back to plain text rather than render a mangled buffer, but
 *   a provider that trips the guard is simply broken.
 *
 * This interface lives in `Porta\Highlight` — the consuming namespace — so the
 * highlighting subsystem never depends on `Porta\Plugin`, mirroring
 * {@see \Porta\Lsp\LanguageServerProvider}.
 */
interface SyntaxHighlightProvider
{
    /** Stable id matching /^[a-z0-9][a-z0-9_-]*$/i; the value users name in config. */
    public function id(): string;

    /** Whether this backend colors $language (a {@see \Porta\Support\LanguageMap} grammar name). */
    public function supports(string $language): bool;

    /**
     * Colour $request, handing the result to $sink.
     *
     * Synchronous backends call `$sink->deliver()` before returning;
     * asynchronous ones return immediately and deliver later from the event
     * loop, while core paints with whatever it already has.
     */
    public function highlight(HighlightRequest $request, HighlightSink $sink): void;
}
