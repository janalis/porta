<?php

declare(strict_types=1);

namespace Porta\Highlight;

use Tempest\Highlight\Highlighter;
use Tempest\Highlight\TerminalTheme;

/**
 * The built-in `tempest/highlight` backend.
 *
 * Synchronous and in-process: a full-document parse of a ~1300-line PHP file
 * measures around 6–16 ms, which is what makes it usable directly on the way to
 * a render frame.
 *
 * It claims every language: Tempest falls back to its own `TextLanguage` for a
 * grammar it doesn't know, so an unfamiliar name yields uncolored text rather
 * than an error. That makes it the natural last resort before
 * {@see PlainTextHighlightProvider}.
 */
final class TempestHighlightProvider implements ThemeAwareHighlightProvider
{
    private Highlighter $highlighter;

    public function __construct(
        private readonly TerminalTheme $theme,
    ) {
        $this->highlighter = TempestBackend::newHighlighter($theme);
    }

    public function id(): string
    {
        return 'tempest';
    }

    public function supports(string $language): bool
    {
        return '' !== $language;
    }

    public function highlight(HighlightRequest $request, HighlightSink $sink): void
    {
        $sink->deliver($request->documentId, $request->version, $this->highlighter->parse($request->text, $request->language));
    }

    /**
     * Tempest bakes the theme's escape codes into its token renderer when the
     * Highlighter is constructed, so swapping the {@see SwitchableTerminalTheme}
     * inner is not enough — the Highlighter itself must be rebuilt from the
     * (already-swapped) holder.
     */
    public function refreshTheme(): void
    {
        $this->highlighter = TempestBackend::newHighlighter($this->theme);
    }
}
