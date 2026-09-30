<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Container\AsIdeService;
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\WorkspaceEditor;

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
        $this->changed($buffer);

        return true;
    }

    /**
     * Replace one range of $buffer — 0-based lines and BYTE columns, the end
     * exclusive — with $text, as ONE undoable edit, told to the same two as
     * {@see replace()}. The caret ends after the new text, as after typing it.
     * The range must be inside the document; the caller checks.
     */
    public function replaceRange(Buffer $buffer, int $line, int $column, int $endLine, int $endColumn, string $text): void
    {
        $document = $buffer->document;
        $document->transact(static function () use ($document, $line, $column, $endLine, $endColumn, $text): void {
            $document->setCursor($line, $column);
            $document->beginSelect();
            $document->setCursor($endLine, $endColumn, selection: SelectionMode::Keep);
            if ($document->hasSelection()) {
                $document->deleteSelection();
            }
            $document->insert($text);
        });
        $this->changed($buffer);
    }

    /**
     * Apply a language server's text edits to $buffer — a format, say — as
     * ONE undoable edit, told to the same two as {@see replace()}. False when
     * they overlap, which leaves the buffer untouched (see
     * {@see WorkspaceEditor::applyTextEdits()}).
     *
     * @param list<array{range: array{start: array{line?: int, character?: int}, end: array{line?: int, character?: int}}, newText: string}> $edits
     */
    public function applyEdits(Buffer $buffer, array $edits): bool
    {
        if (!WorkspaceEditor::applyTextEdits($buffer->document, $edits)) {
            return false;
        }
        $this->changed($buffer);

        return true;
    }

    /** What every edit here is followed by. */
    private function changed(Buffer $buffer): void
    {
        $text = $buffer->document->getText();
        // The buffer may now BE what is on disk — a formatter rewrites the file
        // and applies the same bytes here. Saying "modified" then is a lie, and
        // it costs more than a wrong dot: the 1s poller sees a stamp it does not
        // recognise, reloads identical content, and flashes "Reloaded a.php"
        // after every format.
        if ($this->matchesDisk($buffer, $text)) {
            $buffer->document->markSaved();
            $buffer->stampFromDisk();
        }
        if ($buffer === $this->buffers->active()) {
            $this->editor->invalidate();
        }
        $this->lsp->notifyFullChange($buffer->path, $buffer->document);
    }

    /**
     * Whether $text is byte-for-byte what the file holds.
     *
     * The size is checked first because it decides the common case for nothing:
     * a project-wide replace deliberately leaves the buffer diverged from disk,
     * and reading every touched file to learn that would be a full read per
     * buffer to answer no.
     */
    private function matchesDisk(Buffer $buffer, string $text): bool
    {
        $size = \is_file($buffer->path) ? \filesize($buffer->path) : false;

        return \strlen($text) === $size && \file_get_contents($buffer->path) === $text;
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
