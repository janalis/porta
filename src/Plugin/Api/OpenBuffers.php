<?php

declare(strict_types=1);

namespace Porta\Plugin\Api;

use Porta\Editor\BufferManager;
use Porta\Editor\BufferSaver;

/**
 * The open buffers, for a plugin: which files are open and which hold edits
 * not yet saved, each one's text as the user sees it, and saving one.
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
    ) {}

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
