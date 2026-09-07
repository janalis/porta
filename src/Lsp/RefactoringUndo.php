<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Container\AsIdeService;
use Porta\Editor\BufferManager;
use Porta\Event\FileRewritten;
use Porta\Support\Files;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Takes back the last multi-file refactoring, whole.
 *
 * `Ctrl+Z` is per DOCUMENT — that is what an undo stack is — so a rename across
 * twenty files was twenty separate undos in the buffers that happened to be
 * open, and nothing at all for the files that were not. This is the one verb
 * that spans them, which is why it is a verb rather than a smarter `Ctrl+Z`:
 * pretending the editor's undo reached other files would be worse than an
 * honest second key.
 *
 * **It refuses rather than guesses.** Everything it would touch is checked
 * first, and if ANY part has moved on since the refactoring — a buffer edited,
 * a file changed on disk — nothing is undone and the reason names the file.
 * Undoing a buffer that has been typed into since would take back the typing,
 * not the rename; restoring a file that changed on disk would discard whatever
 * changed it. Both are worse than saying no, and a partial undo of a
 * refactoring is the state nobody can reason about.
 *
 * One refactoring is remembered, not a stack. The second undo of a rename is
 * not a thing anyone asks for, and a stack would have to answer what happens
 * when its entries interleave with per-buffer undos — a question with no good
 * answer and no user behind it.
 *
 * **Text edits only.** An edit that also CREATES, RENAMES or DELETES files is
 * not remembered at all ({@see decline()}), because undoing its text and leaving
 * the new file standing is the partial state this class exists to refuse.
 */
#[AsIdeService]
final class RefactoringUndo
{
    /** @var array<string, int> buffer path => document version right after the edit */
    private array $buffers = [];

    /** @var array<string, array{previous: string, written: string}> disk path => what it held before and after */
    private array $files = [];

    /** Whether the next thing recorded starts a new refactoring. */
    private bool $fresh = false;

    /** Whether THIS refactoring has been ruled out, so nothing more is recorded for it. */
    private bool $declined = false;

    public function __construct(
        private readonly BufferManager $bufferManager,
        private readonly EventDispatcherInterface $events,
        private readonly Filesystem $fs = new Filesystem(),
    ) {}

    /**
     * Start remembering a fresh refactoring — LAZILY.
     *
     * The previous one is not discarded here but on the first thing recorded,
     * and the difference is a defect that was already shipped once: an edit
     * every file of which is refused changes nothing, and clearing eagerly threw
     * away the undo of the refactoring before it for a keystroke that did
     * nothing at all. Placing the eager call after one refusal check merely
     * moved the hole to the next kind of refusal — per-file overlap, which
     * happens deeper. Lazily, "nothing was recorded" and "nothing is forgotten"
     * are the same condition by construction, whatever the reason.
     */
    public function begin(): void
    {
        $this->fresh = true;
        $this->declined = false;
    }

    /**
     * This refactoring cannot be taken back — discard what was remembered, now.
     *
     * For an edit carrying RESOURCE OPERATIONS. Files created, renamed or
     * deleted are not recorded here, so undoing would restore the text and
     * leave the new file standing, or write content back to a path the rename
     * moved. A partial undo of a refactoring is the state nobody can reason
     * about, and the previous refactoring is not the answer either: the user
     * pressing undo means the thing they just did.
     */
    public function decline(): void
    {
        $this->clear();
        // For the REST of this refactoring, not just until the next record: an
        // edit that creates a file usually edits it immediately afterwards, and
        // remembering that half would offer an undo of exactly the part that
        // makes no sense alone.
        $this->declined = true;
    }

    /**
     * Whether this group may record — clearing the previous one the first time
     * it does.
     */
    private function openGroup(): bool
    {
        if ($this->declined) {
            return false;
        }
        if ($this->fresh) {
            $this->clear();
        }

        return true;
    }

    private function clear(): void
    {
        $this->buffers = [];
        $this->files = [];
        $this->fresh = false;
    }

    /**
     * A buffer took part. The version is read AFTER the edit, so a later
     * keystroke changes it and the guard below notices.
     */
    public function recordBuffer(string $path, int $versionAfter): void
    {
        if ($this->openGroup()) {
            $this->buffers[$path] = $versionAfter;
        }
    }

    /** A file with no buffer took part: what it held, and what was written over it. */
    public function recordFile(string $path, string $previous, string $written): void
    {
        if ($this->openGroup()) {
            $this->files[$path] = ['previous' => $previous, 'written' => $written];
        }
    }

    /**
     * Whether there is a refactoring to take back.
     *
     * A bool, not a name. Carrying a label would make one value answer two
     * questions — "is there anything" and "what was it" — and an empty label
     * would then read as nothing to undo.
     */
    public function hasPending(): bool
    {
        return [] !== $this->buffers || [] !== $this->files;
    }

    /**
     * Take it all back, or none of it.
     *
     * Returns the path that stopped it, or null on success — a path rather than
     * a bool because "something changed since" is only actionable if it says
     * which file.
     */
    public function undo(): ?string
    {
        $blocker = $this->firstChanged();
        if (null !== $blocker) {
            return $blocker;
        }

        foreach (array_keys($this->buffers) as $path) {
            $this->bufferManager->get($path)?->document->undo();
        }
        foreach ($this->files as $path => $texts) {
            try {
                $this->fs->dumpFile($path, $texts['previous']);
            } catch (IOException) {
                // Writable a moment ago and not now (a full disk, a read-only
                // mount). Named like any other blocker; the buffers are already
                // back, which is the same partial outcome the forward direction
                // accepts for an unwritable file.
                return $path;
            }
            // The refactored version is what this write replaces, and it is
            // about to exist nowhere — the same reason the forward direction
            // announces itself.
            $this->events->dispatch(new FileRewritten($path, $texts['written']));
        }

        $this->clear();

        return null;
    }

    /**
     * The first thing that has moved on since the refactoring, or null when
     * nothing has. Checked for EVERYTHING before anything is undone.
     */
    private function firstChanged(): ?string
    {
        foreach ($this->buffers as $path => $version) {
            $document = $this->bufferManager->get($path)?->document;
            // A closed buffer is not a blocker: its edit was applied and saved
            // or discarded by the close, and either way this class cannot undo
            // what it no longer holds. It is dropped from the walk above by the
            // same null check.
            if (null !== $document && $document->getVersion() !== $version) {
                return $path;
            }
        }
        foreach ($this->files as $path => $texts) {
            if (Files::readWritable($path) !== $texts['written']) {
                return $path;
            }
        }

        return null;
    }
}
