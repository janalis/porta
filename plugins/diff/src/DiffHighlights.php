<?php

declare(strict_types=1);

namespace Porta\DiffPlugin;

use Porta\DiffPlugin\Widget\DiffViewRenderer;
use Porta\Highlight\CodeHighlighter;
use Porta\Highlight\DarkTerminalTheme;
use Porta\Highlight\TempestHighlightProvider;
use Tempest\Highlight\TerminalTheme;

/**
 * A diff's syntax colours: the context and added lines of every hunk, in one
 * batch, keyed "hunk:line" as {@see DiffViewRenderer} reads them. Deleted
 * lines stay plain — the pre-image no longer matches the file's highlight
 * state.
 *
 * Hunks are non-contiguous lines, possibly from different files: always the
 * built-in engine, never a plugin's. The highlighter caches by version, so one
 * bumped counter per call keeps repeated renders of one result free.
 */
final class DiffHighlights
{
    private readonly CodeHighlighter $highlighter;

    private int $version = 0;

    public function __construct(?TerminalTheme $theme = null)
    {
        $theme ??= new DarkTerminalTheme();
        $this->highlighter = new CodeHighlighter(new TempestHighlightProvider($theme), $theme);
    }

    /**
     * $document's lines coloured as $path's language.
     *
     * @return array<string, string> "hunk:line" => the line's text, highlighted
     */
    public function of(DiffDocument $document, string $path): array
    {
        $this->highlighter->setFile($path);
        $keys = [];
        $texts = [];
        foreach ($document->hunks as $h => $hunk) {
            foreach ($hunk->lines as $l => $line) {
                if ('+' !== $line->origin && ' ' !== $line->origin) {
                    continue;
                }
                $keys[] = $h.':'.$l;
                $texts[] = DiffViewRenderer::shown($line->text);
            }
        }
        $highlighted = $this->highlighter->highlight($texts, ++$this->version);
        $out = [];
        foreach ($keys as $n => $key) {
            $out[$key] = $highlighted[$n] ?? '';
        }

        return $out;
    }

    /** A live theme switch: the next {@see of()} colours with the new one. */
    public function refreshTheme(): void
    {
        $this->highlighter->refreshTheme();
    }
}
