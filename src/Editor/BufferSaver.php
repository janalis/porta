<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Container\AsIdeService;
use Porta\Event\BufferSaveRequested;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Save an open buffer on behalf of something other than the user — a plugin,
 * and through the mcp plugin an agent — and say plainly when it will not.
 *
 * The save itself is the user's own ({@see BufferSaveRequested}), so it does
 * all the user's save does: the editorconfig rendering, the BOM, local
 * history's snapshot, the language servers' didSave, `onFileSaved`.
 *
 * What it will NOT do is the one thing the keyboard path makes a question of:
 * overwrite a file that changed on disk while the buffer held edits. The user
 * is asked to press Ctrl+S again; a caller that cannot see the screen is
 * refused outright instead, because retrying would silently throw away
 * whatever the other writer did.
 */
#[AsIdeService]
final readonly class BufferSaver
{
    public function __construct(
        private BufferManager $buffers,
        private EventDispatcherInterface $events,
    ) {}

    /** Null once saved (or with nothing to save), else why it was not. */
    public function save(string $path): ?string
    {
        $buffer = $this->buffers->get($path);

        return match (true) {
            null === $buffer => 'it is not open in the editor',
            $buffer->isViewer() => 'it is not a text file',
            $buffer->diskChangedUnderEdit => 'it changed on disk since it was opened, and saving would overwrite that — the user has to decide',
            !$buffer->isDirty() => null,
            default => $this->write($buffer, $path),
        };
    }

    /** Null once the user's own save wrote the dirty $buffer, else why it did not. */
    private function write(Buffer $buffer, string $path): ?string
    {
        $this->events->dispatch(new BufferSaveRequested($path));

        return $buffer->isDirty() ? 'it could not be written' : null;
    }
}
