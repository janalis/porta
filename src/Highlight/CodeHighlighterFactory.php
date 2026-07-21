<?php

declare(strict_types=1);

namespace Porta\Highlight;

use Porta\Support\LanguageMap;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Tui;
use Tempest\Highlight\TerminalTheme;

/**
 * Builds {@see CodeHighlighter}s with the backend resolved for the file.
 *
 * Resolution happens here, at construction, rather than per call — the backend
 * is fixed for a buffer's lifetime, so enabling or disabling a highlighter
 * plugin takes effect for files opened afterwards. That matches the existing
 * plugin caveat that default keybindings need a restart.
 *
 * {@see forFragments()} is deliberately separate. The diff view highlights a
 * synthetic, non-contiguous array of lines gathered from different hunks, under
 * a version counter it reuses across files — input no real incremental parser
 * should be handed. Fragments therefore always use the built-in backend, never
 * a plugin's.
 */
final class CodeHighlighterFactory
{
    private readonly HighlightRegistry $registry;
    private readonly TerminalTheme $syntaxTheme;

    public function __construct(
        ?HighlightRegistry $registry = null,
        ?TerminalTheme $syntaxTheme = null,
        private readonly ?Tui $tui = null,
        private readonly ?CodeEditorWidget $editor = null,
    ) {
        $this->syntaxTheme = $syntaxTheme ?? new DarkTerminalTheme();
        $this->registry = $registry ?? new HighlightRegistry(new NoHighlightPlugins(), new HighlightPreferences());
    }

    /** A highlighter for $path, using whichever backend claims its language. */
    public function forFile(string $path): CodeHighlighter
    {
        $provider = $this->registry->providerFor(LanguageMap::highlightForPath($path) ?? '');

        return new CodeHighlighter($provider, $this->syntaxTheme, $this->repaint(...));
    }

    /**
     * Show a late answer from an asynchronous backend.
     *
     * Requesting a frame is not enough on its own: widgets memoise their
     * rendered lines, so the editor would re-render from its own cache and
     * never re-read the highlighter. The invalidate is what makes the repaint
     * real — the same pairing `BufferService::applyDiagnostics()` uses.
     */
    private function repaint(): void
    {
        $this->editor?->invalidate();
        $this->tui?->requestRender();
    }

    /** A highlighter for loose lines (diff hunks, the empty-editor placeholder). */
    public function forFragments(): CodeHighlighter
    {
        return new CodeHighlighter(new TempestHighlightProvider($this->syntaxTheme), $this->syntaxTheme);
    }
}
