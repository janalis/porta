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
 * - **Emit only well-formed escapes.** Core drops the markup of any single line
 *   carrying a CSI that never terminates, and renders that line plain: the
 *   orphaned tail is visible TEXT, so it widens the row and drags every
 *   column-placed decoration with it. `tempest/highlight` does this on some
 *   attribute lines, which is why the check exists.
 *
 * Markup is NOT required to reduce to its input byte-for-byte, whatever an
 * earlier version of this docblock claimed — nothing ever enforced it and the
 * JSON backend deliberately strips diff markers. Changing the TEXT is a
 * transformation; breaking an ESCAPE is a fault. Only the second is guarded.
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
