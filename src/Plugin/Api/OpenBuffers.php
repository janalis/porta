<?php

declare(strict_types=1);

namespace Porta\Plugin\Api;

use Porta\Editor\BufferManager;
use Porta\Editor\BufferSaver;
use Porta\Editor\DocumentReplacer;
use Porta\Editor\FileOpener;
use Porta\Editor\OpenFocus;
use Porta\Lsp\Uri;
use Porta\Lsp\WorkspaceEditor;
use Porta\Lsp\WorkspaceEditOutcome;

/**
 * The open buffers, for a plugin: which files are open and which hold edits
 * not yet saved, each one's text and selection as the user sees them,
 * changing part of it, and saving one — and the files under them: creating,
 * moving and deleting one, which a buffer has to follow. Those three go
 * through the same path a language server's refactoring does, so an open
 * buffer is re-pointed with its unsaved edits, every path-keyed store hears
 * of it, and a delete hands what it removed to local history.
 *
 * Reached through {@see PluginApi::openBuffers()}. Paths are absolute. Viewer
 * tabs — an image, a graph — have no text, so they are left out of
 * {@see files()} and read as none.
 */
final readonly class OpenBuffers
{
    public function __construct(
        private BufferManager $buffers,
        private BufferSaver $saver,
        private DocumentReplacer $replacer,
        private FileOpener $files,
        private WorkspaceEditor $workspace,
    ) {}

    /**
     * Open a file as a text tab BEHIND the one the user is on — no tab comes
     * forward, no focus moves — so a change can be made to it where the user
     * sees it without being taken there. An open one is left as it is. Null
     * once it is open as text, else why not: not a file, or not text (an
     * image, a file too large to edit).
     */
    public function open(string $path): ?string
    {
        if (null === $this->text($path)) {
            $this->files->openFile($path, OpenFocus::Background);
        }

        return null === $this->text($path) ? \sprintf('%s cannot be opened as text', $path) : null;
    }

    /**
     * Every open TEXT tab, in tab order: its path and whether it has edits not
     * yet saved.
     *
     * @return list<array{path: string, dirty: bool}>
     */
    public function files(): array
    {
        $files = [];
        foreach ($this->buffers->all() as $buffer) {
            if ($buffer->isViewer()) {
                continue;
            }
            $files[] = ['path' => $buffer->path, 'dirty' => $buffer->isDirty()];
        }

        return $files;
    }

    /**
     * An open buffer's text as it is in the editor — unsaved edits included;
     * null when no text tab has that file open.
     */
    public function text(string $path): ?string
    {
        $buffer = $this->buffers->find($path);

        return null === $buffer || $buffer->isViewer() ? null : $buffer->document->getText();
    }

    /**
     * What is selected in an open buffer — 0-based line and byte column of
     * each end, as {@see PluginApi::caretPosition()} gives them, and the
     * text. Null when nothing is, or no text tab has that file open.
     *
     * @return array{line: int, column: int, endLine: int, endColumn: int, text: string}|null
     */
    public function selection(string $path): ?array
    {
        $buffer = $this->buffers->find($path);
        $range = null === $buffer || $buffer->isViewer() ? null : $buffer->document->getSelectionRange();
        if (null === $buffer || null === $range) {
            return null;
        }

        return [
            'line' => $range['startLine'],
            'column' => $range['startCol'],
            'endLine' => $range['endLine'],
            'endColumn' => $range['endCol'],
            'text' => $buffer->document->getSelectedText(),
        ];
    }

    /**
     * Replace a range of an open buffer with $text — 0-based lines and byte
     * columns as {@see PluginApi::caretPosition()} gives them, the end
     * exclusive — as ONE edit the user undoes, not saved. Null once applied,
     * else why not: not open, not text, or a range outside the document.
     */
    public function edit(string $path, int $line, int $column, int $endLine, int $endColumn, string $text): ?string
    {
        return $this->edits($path, [['line' => $line, 'column' => $column, 'endLine' => $endLine, 'endColumn' => $endColumn, 'text' => $text]]);
    }

    /**
     * {@see edit()} for several ranges of one buffer at once, as ONE edit the
     * user undoes: each range in the text as it is NOW, in document order,
     * none overlapping the next. Null once applied (an empty list changes
     * nothing), else why not — and then nothing was applied.
     *
     * @param list<array{line: int, column: int, endLine: int, endColumn: int, text: string}> $edits
     */
    public function edits(string $path, array $edits): ?string
    {
        $buffer = $this->buffers->find($path);
        if (null === $buffer || $buffer->isViewer()) {
            return \sprintf('%s is not open as text', $path);
        }
        $document = $buffer->document;
        $inside = static fn (int $l, int $c): bool => $l >= 0 && $l < $document->getLineCount() && $c >= 0 && $c <= \strlen($document->getLine($l));
        $previous = [0, 0];
        $refusal = null;
        foreach ($edits as $e) {
            $start = [$e['line'], $e['column']];
            $end = [$e['endLine'], $e['endColumn']];
            if (!$inside(...$start) || !$inside(...$end) || $end < $start || $start < $previous) {
                $refusal = \sprintf('%d:%d–%d:%d is not a range of %s after the one before it', $e['line'], $e['column'], $e['endLine'], $e['endColumn'], $path);

                break;
            }
            $previous = $end;
        }
        if (null === $refusal && [] !== $edits) {
            $this->replacer->replaceRanges($buffer, $edits);
        }

        return $refusal;
    }

    /**
     * A number that changes whenever an open buffer's text does — so a plugin
     * comparing copies of it reads the text only when there is something new.
     * Null when it is not open as text.
     */
    public function version(string $path): ?int
    {
        $buffer = $this->buffers->find($path);

        return null === $buffer || $buffer->isViewer() ? null : $buffer->document->version;
    }

    /**
     * Answer undo and redo in an open buffer instead of its own history —
     * each closure saying whether it changed anything — until
     * {@see reclaimHistory()}. For a shared session, where Ctrl+Z must take
     * back the user's own edits and not a collaborator's typed since. The
     * buffer's own history is forgotten on both hand-overs. False when it is
     * not open as text.
     *
     * @param \Closure(): bool $undo
     * @param \Closure(): bool $redo
     */
    public function delegateHistory(string $path, \Closure $undo, \Closure $redo): bool
    {
        $buffer = $this->buffers->find($path);
        if (null === $buffer || $buffer->isViewer()) {
            return false;
        }
        $buffer->document->historyDelegate = ['undo' => $undo, 'redo' => $redo];

        return true;
    }

    /** Give an open buffer its own undo and redo back — from now on, nothing earlier. */
    public function reclaimHistory(string $path): void
    {
        $document = $this->buffers->find($path)?->document;
        if (null !== $document) {
            $document->historyDelegate = null;
        }
    }

    /**
     * Refuse the user's keystrokes that would change an open buffer — the
     * caret, the selection and copy still work, and a flash says why. Edits
     * from code ({@see edit()}) still land. False when it is not open as text.
     */
    public function makeReadOnly(string $path): bool
    {
        $buffer = $this->buffers->find($path);
        if (null === $buffer || $buffer->isViewer()) {
            return false;
        }
        $buffer->document->readOnly = true;

        return true;
    }

    /** Let the user edit an open buffer again. */
    public function makeWritable(string $path): void
    {
        $document = $this->buffers->find($path)?->document;
        if (null !== $document) {
            $document->readOnly = false;
        }
    }

    /**
     * Save an open buffer as Ctrl+S does — local history, the language
     * servers and `onFileSaved` all hear of it. Null once saved (or with
     * nothing to save), else why not: not open, not text, or CHANGED ON DISK
     * under the edits, which is refused rather than overwritten — the user is
     * asked about that one, and a plugin cannot ask for them.
     */
    public function save(string $path): ?string
    {
        return $this->saver->save($path);
    }

    /**
     * Create a file holding $text — folders on the way included — as a file on
     * disk, not opened. Null once created, else why not: it already exists,
     * or it is outside the project.
     */
    public function create(string $path, string $text = ''): ?string
    {
        $changes = [['kind' => 'create', 'uri' => Uri::fromPath($path)]];
        if ('' !== $text) {
            $changes[] = [
                'textDocument' => ['uri' => Uri::fromPath($path), 'version' => null],
                'edits' => [['range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 0]], 'newText' => $text]],
            ];
        }

        return self::whyNot($this->workspace->applyWorkspaceEdit(['documentChanges' => $changes]));
    }

    /**
     * Rename or move a file or folder; an open buffer under it follows, unsaved
     * edits and all. The folder it goes into is created. Null once moved, else
     * why not: nothing there, something already at $to, or either side
     * outside the project.
     */
    public function move(string $from, string $to): ?string
    {
        return self::whyNot($this->workspace->applyWorkspaceEdit(['documentChanges' => [['kind' => 'rename', 'oldUri' => Uri::fromPath($from), 'newUri' => Uri::fromPath($to)]]]));
    }

    /**
     * Delete a FILE — its open buffer closes — handing what it held to
     * whoever keeps history ({@see \Porta\Event\FileDeleted}). Null once
     * deleted, else why not: nothing there, a folder, outside the project, or
     * a buffer with unsaved edits, which are the user's and are not a plugin's
     * to throw away.
     */
    public function delete(string $path): ?string
    {
        return match (true) {
            \is_dir($path) => \sprintf('%s is a folder — only files are deleted this way', $path),
            true === $this->buffers->find($path)?->isDirty() => \sprintf('%s has unsaved changes in the editor — they are the user\'s to keep or drop', $path),
            default => self::whyNot($this->workspace->applyWorkspaceEdit(['documentChanges' => [['kind' => 'delete', 'uri' => Uri::fromPath($path)]]])),
        };
    }

    /**
     * Why an edit did not do what was asked, or null when it did: refused
     * before anything ran, or a path the disk would not write — a create
     * under a file, a move into a folder that cannot be written.
     */
    private static function whyNot(WorkspaceEditOutcome $outcome): ?string
    {
        return $outcome->refused ?? (null === ($path = $outcome->skipped[0] ?? null) ? null : \sprintf('%s could not be written', $path));
    }
}
