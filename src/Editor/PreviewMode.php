<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * What the editor area shows for a file that has a live preview (markdown,
 * SVG, a graph): the text alone, the text beside its preview, or the preview
 * alone. One per buffer, so each tab keeps the arrangement it was left in.
 *
 * This is a TEXT buffer's setting, not a viewer's: every mode keeps the real
 * document open, and Preview only detaches the editor the way a viewer tab
 * does — the buffer is still edited, saved and synced like any other.
 *
 * Backed, because the session keeps each tab's arrangement across restarts.
 */
enum PreviewMode: string
{
    case Editor = 'editor';
    case Split = 'split';
    case Preview = 'preview';

    /** The next arrangement in the order the toggles read, wrapping. */
    public function next(): self
    {
        return match ($this) {
            self::Editor => self::Split,
            self::Split => self::Preview,
            self::Preview => self::Editor,
        };
    }

    /** Whether the preview is on screen in this arrangement. */
    public function showsPreview(): bool
    {
        return self::Editor !== $this;
    }
}
