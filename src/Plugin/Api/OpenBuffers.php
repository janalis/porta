<?php

declare(strict_types=1);

namespace Porta\Plugin\Api;

use Porta\Editor\BufferManager;
use Porta\Editor\BufferSaver;
use Porta\Editor\DocumentReplacer;
use Porta\Editor\FileOpener;
use Porta\Editor\OpenFocus;

/**
 * The open buffers, for a plugin: which files are open and which hold edits
 * not yet saved, each one's text and selection as the user sees them,
 * changing part of it, and saving one.
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
        $buffer = $this->buffers->get($path);

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
        $buffer = $this->buffers->get($path);
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
        $buffer = $this->buffers->get($path);
        if (null === $buffer || $buffer->isViewer()) {
            return \sprintf('%s is not open as text', $path);
        }
        $document = $buffer->document;
        $inside = static fn (int $l, int $c): bool => $l >= 0 && $l < $document->getLineCount() && $c >= 0 && $c <= \strlen($document->getLine($l));
        if (!$inside($line, $column) || !$inside($endLine, $endColumn) || [$endLine, $endColumn] < [$line, $column]) {
            return \sprintf('%d:%d–%d:%d is not a range of %s', $line, $column, $endLine, $endColumn, $path);
        }
        $this->replacer->replaceRange($buffer, $line, $column, $endLine, $endColumn, $text);

        return null;
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
}
