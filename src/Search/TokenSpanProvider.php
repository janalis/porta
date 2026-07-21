<?php

declare(strict_types=1);

namespace Porta\Search;

/**
 * Plugin-supplied comment/string classification for project search.
 *
 * Deliberately separate from the colouring seam
 * ({@see \Porta\Highlight\SyntaxHighlightProvider}) even though both describe
 * "what kind of token is this":
 *
 * - **Different paths.** Colouring runs per document version, on the way to a
 *   render frame, and may answer asynchronously. This runs once per *matched
 *   file* inside a project search's completion callback, where there is nowhere
 *   to park a continuation — so it is synchronous, and must stay cheap.
 * - **Different failure modes.** A colouring fault degrades to plain text; a
 *   classification fault degrades to "classify nothing", which makes the scope
 *   filter a no-op rather than silently dropping results.
 * - **A backend can usefully do one and decline the other.** An out-of-process
 *   highlighter should decline this: search classifies every matched file, so a
 *   500-hit search would mean 500 subprocesses.
 *
 * The interface lives in `Porta\Search`, the consuming namespace, so search
 * never depends on `Porta\Plugin` — the same arrangement as
 * {@see \Porta\Lsp\LanguageServerProvider}.
 */
interface TokenSpanProvider
{
    /**
     * Comment/string byte spans of $content as `[start, end)` offsets, or null
     * to decline so the built-in classifier serves the language.
     *
     * @return list<array{start: int, end: int}>|null
     */
    public function commentStringSpans(string $content, string $language): ?array;
}
