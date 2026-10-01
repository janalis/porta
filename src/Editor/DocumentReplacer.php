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
     * {@see replace()}.
     *
     * The buffer's caret and selection STAY where the user left them — moved
     * along with the text they sat after, pulled to the end of the new text
     * when the range swallowed them — because the edit is someone else's: an
     * agent's, while the user may be typing elsewhere in the same file. The
     * range must be inside the document; the caller checks.
     */
    public function replaceRange(Buffer $buffer, int $line, int $column, int $endLine, int $endColumn, string $text): void
    {
        $this->replaceRanges($buffer, [['line' => $line, 'column' => $column, 'endLine' => $endLine, 'endColumn' => $endColumn, 'text' => $text]]);
    }

    /**
     * {@see replaceRange()} for several ranges at once, still ONE undoable
     * edit: a collaborator's change that touched three places is one thing
     * they did. The ranges are in the document as it is NOW, in order, and
     * none overlaps the next; the caller checks.
     *
     * @param non-empty-list<array{line: int, column: int, endLine: int, endColumn: int, text: string}> $ranges
     */
    public function replaceRanges(Buffer $buffer, array $ranges): void
    {
        $document = $buffer->document;
        $caret = [$document->cursorLine, $document->cursorCol];
        $anchor = self::anchor($document->getSelectionRange(), $caret);
        // Last first: an edit never moves the text BEFORE it, so every range
        // still names the text it was given for when its turn comes — and so
        // do the caret and the anchor, shifted by each edit after them.
        $ranges = \array_reverse($ranges);
        $document->transact(static function () use ($document, $ranges): void {
            foreach ($ranges as $range) {
                $document->setCursor($range['line'], $range['column']);
                $document->beginSelect();
                $document->setCursor($range['endLine'], $range['endColumn'], selection: SelectionMode::Keep);
                if ($document->hasSelection()) {
                    $document->deleteSelection();
                }
                $document->insert($range['text']);
            }
        });
        foreach ($ranges as $range) {
            $text = $range['text'];
            $newlines = \substr_count($text, needle: "\n");
            $last = \strrpos($text, needle: "\n");
            $start = [$range['line'], $range['column']];
            $end = [$range['endLine'], $range['endColumn']];
            $after = [$range['line'] + $newlines, false === $last ? $range['column'] + \strlen($text) : \strlen($text) - $last - 1];
            $caret = self::shifted($caret, $start, $end, $after);
            $anchor = null === $anchor ? null : self::shifted($anchor, $start, $end, $after);
        }
        $document->setCursor($caret[0], $caret[1]);
        if (null !== $anchor) {
            $document->setSelectionRange($anchor[0], $anchor[1], $caret[0], $caret[1]);
        }
        $this->changed($buffer);
    }

    /**
     * The end of a selection the caret is NOT at — where it was started —
     * or null with nothing selected: the range is normalized and says only
     * which end comes first.
     *
     * @param array{startLine: int, startCol: int, endLine: int, endCol: int}|null $range
     * @param array{int, int}                                                     $caret
     *
     * @return array{int, int}|null
     */
    private static function anchor(?array $range, array $caret): ?array
    {
        if (null === $range) {
            return null;
        }
        $start = [$range['startLine'], $range['startCol']];

        return $start === $caret ? [$range['endLine'], $range['endCol']] : $start;
    }

    /**
     * Where a position lands once [$start, $end) became text ending at $after:
     * before the range it stays, inside it goes to the new end, after it
     * shifts — by columns too when it shared the range's last line.
     *
     * @param array{int, int} $at
     * @param array{int, int} $start
     * @param array{int, int} $end
     * @param array{int, int} $after
     *
     * @return array{int, int}
     */
    private static function shifted(array $at, array $start, array $end, array $after): array
    {
        if ($at <= $start) {
            return $at;
        }
        if ($at < $end) {
            return $after;
        }
        if ($at[0] === $end[0]) {
            return [$after[0], $after[1] + $at[1] - $end[1]];
        }

        return [$at[0] + $after[0] - $end[0], $at[1]];
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
