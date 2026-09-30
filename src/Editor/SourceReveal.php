<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Tui;

/**
 * Click-to-source for every preview: a pane says where in its text the thing
 * clicked was written, and this puts the caret there, centred.
 *
 * Beside the preview the text takes the keys, since the reader asked to go to
 * it. With the preview shown alone the caret moves all the same and the
 * preview keeps the keys, so the caret is waiting when the text comes back. A
 * viewer tab has no text, and nothing happens.
 *
 * Concrete on the editor for {@see \Porta\Editor\EditorFileActions}' reason:
 * it hands the widget to setFocus() as well as moving its caret.
 */
final readonly class SourceReveal
{
    public function __construct(
        private BufferManager $buffers,
        private CodeEditorWidget $editor,
        private Tui $tui,
    ) {}

    public function reveal(int $line, int $byteCol): void
    {
        $active = $this->buffers->active();
        if (null === $active?->previewArrangement()) {
            return;
        }
        $document = $active->document;
        $line = \max(0, \min($line, $document->getLineCount() - 1));
        $byteCol = \max(0, \min($byteCol, \strlen($document->getLine($line))));
        $this->editor->jumpTo($line, $document->byteColToUtf16($line, $byteCol));
        if (null !== $this->editor->getContext()) {
            $this->tui->setFocus($this->editor); // on screen: the preview is beside it
        }
        $this->tui->requestRender();
    }
}
