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
 *   many `"\n"`-separated lines as the input. {@see CodeHighlighter} guards
 *   that one and falls back to plain text for the whole buffer, because a line
 *   count it cannot trust makes every row the wrong row.
 * - **Reduce to the input.** Each line's markup must strip back to that line's
 *   own source. {@see CodeHighlighter} checks this per line and renders any
 *   line that fails from the FILE instead — losing its colours, never its text.
 *   Both faults seen in practice are `tempest/highlight`'s: a CSI that never
 *   terminates (its parameters become visible text, widening the row), and its
 *   `Base` language deleting `{+ … +}` diff markers from every language. Core
 *   repairs the first where the shape allows and re-checks the result.
 *
 * This contract was stated here long before anything enforced it, which is how
 * both faults reached the screen. A provider that trips it now costs that line
 * its colours.
 *
 * This interface lives in `Porta\Highlight` — the consuming namespace — so the
 * highlighting subsystem never depends on `Porta\Plugin`, mirroring
 * {@see \Porta\Lsp\LanguageServerProvider}.
 */
interface SyntaxHighlightProvider
{
    /** Stable id matching /^[a-z0-9][a-z0-9_-]*$/i; the value users name in config. */
    public string $id { get; }

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
