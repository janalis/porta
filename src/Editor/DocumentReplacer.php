<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Container\AsIdeService;
use Porta\Lsp\LspSessionPool;

/**
 * Swap a buffer's whole text as ONE undoable edit, and tell the two things that
 * have to hear about it — the editor (only when the buffer is the one on
 * screen) and the language server.
 *
 * The three lines are trivial; keeping them together is not. Project-wide
 * replace wrote them twice and a plugin needs them a third time, and every
 * copy has the same two ways to be subtly wrong: repainting a buffer nobody is
 * looking at, or skipping the didChange so the server's copy silently drifts
 * from ours.
 *
 * Deliberately NOT the reload path: {@see FilesystemReconciler} also
 * `markSaved()`s and refreshes git marks, and folding it in here would need a
 * flag — which would make this a mode switch rather than a verb.
 */
#[AsIdeService]
final readonly class DocumentReplacer
{
    public function __construct(
        private BufferManager $buffers,
        private EditorInvalidator $editor,
        private LspSessionPool $lsp,
    ) {}

    /**
     * Replace $buffer's text. Returns false when the text is identical — a
     * caller that changed nothing must not push an undo entry, mark the buffer
     * modified, or tell the server anything.
     */
    public function replace(Buffer $buffer, string $text): bool
    {
        if ($text === $buffer->document->getText()) {
            return false;
        }
        $buffer->document->replaceAll($text);
        if ($buffer === $this->buffers->active()) {
            $this->editor->invalidate();
        }
        $this->lsp->notifyFullChange($buffer->path, $buffer->document);

        return true;
    }

    /**
     * The active TEXT buffer's swap. False when nothing is open, when the
     * active tab is a VIEWER pane (its document is neutralized — writing into
     * it would edit a buffer whose content is an image), or when the text is
     * unchanged.
     */
    public function replaceActive(string $text): bool
    {
        $buffer = $this->buffers->active();

        return null !== $buffer && !$buffer->isViewer() && $this->replace($buffer, $text);
    }
}
