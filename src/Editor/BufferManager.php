<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Container\AsIdeService;
use Porta\Highlight\CodeHighlighterFactory;
use Porta\Support\IndexMove;

/**
 * Holds all open editor buffers. Each buffer keeps its own Document (and
 * therefore cursor + undo history) and its own diagnostics, so switching
 * files never loses state.
 */
#[AsIdeService]
final class BufferManager
{
    /** @var array<string, Buffer> keyed by absolute path, in open order */
    private array $buffers = [];
    private ?string $activePath = null;
    private int $focusCounter = 0;
    private readonly CodeHighlighterFactory $highlighters;

    public function __construct(
        ?CodeHighlighterFactory $highlighters = null,
        private readonly ?EditHistory $editHistory = null,
    ) {
        $this->highlighters = $highlighters ?? new CodeHighlighterFactory();
    }

    public function open(string $path, string $content): Buffer
    {
        $path = $this->sameFileAlreadyOpen($path) ?? $path;
        if (!\array_key_exists($path, $this->buffers)) {
            $this->buffers[$path] = new Buffer($path, $this->trackedDocument($path, $content), $this->highlighters->forFile($path));
        }
        $buffer = $this->buffers[$path];
        $this->activePath = $path;
        $buffer->lastFocused = ++$this->focusCounter;

        return $buffer;
    }

    /**
     * Open (or re-activate) a PANE tab: an empty document plus the payload that
     * pane's loader produced. The four near-identical per-viewer factories this
     * replaced differed only in which field they filled.
     *
     * The payload is re-assigned on re-activation, so an explicit re-open of a
     * document that changed on disk shows the new bytes instead of waiting out
     * the filesystem poll.
     */
    public function openPane(string $path, string $paneId, mixed $payload): Buffer
    {
        $this->buffers[$path] ??= new Buffer($path, new Document(''), $this->highlighters->forFile($path), $paneId, $payload);
        $buffer = $this->buffers[$path];
        $buffer->panePayload = $payload;
        $this->activePath = $path;
        $buffer->lastFocused = ++$this->focusCounter;

        return $buffer;
    }

    /**
     * A text document that reports its edits, so `Last Edit Location` knows
     * which FILE each one happened in — the pairing a Document cannot make on
     * its own, because it does not know its path.
     *
     * Only here, never in {@see openPane()}: a viewer's document is an empty
     * stand-in nobody types into, and recording its "edits" would put a picture
     * in the list of places you were writing.
     */
    private function trackedDocument(string $path, string $content): Document
    {
        $document = new Document($content);
        $history = $this->editHistory;
        if (null !== $history) {
            $document->setEditHook(static function (int $line) use ($history, $path): void {
                $history->record($path, $line);
            });
        }

        return $document;
    }

    /**
     * The key of an already-open buffer over the SAME FILE as $path, or null.
     *
     * Paths reach this class through `realpath()`, which looks like a canonical
     * name and is not one: on a case-insensitive filesystem it returns the case
     * it was given, so `src/ide.php` and `src/Ide.php` are two keys over one
     * file and the editor opens the same bytes twice. Edit both and the second
     * save discards the first.
     *
     * Asked of the FILESYSTEM rather than of the string — same device, same
     * inode. That needs no guess about which platform folds case, stays correct
     * on one that does not (two names, two inodes, nothing merges), and answers
     * for hard links, which are the same question in different clothes.
     *
     * An exact key hit short-circuits, so the ordinary open costs nothing.
     */
    private function sameFileAlreadyOpen(string $path): ?string
    {
        if (\array_key_exists($path, $this->buffers) || [] === $this->buffers) {
            return null;
        }
        $id = self::fileId($path);
        if (null === $id) {
            return null; // no file behind it yet: a new file is its own buffer
        }
        foreach (\array_keys($this->buffers) as $open) {
            if ($id === self::fileId($open)) {
                return $open;
            }
        }

        return null;
    }

    /**
     * A file's identity on disk — device and inode — or null when $path names
     * no file.
     *
     * One expression rather than a guard per failure, so both outcomes run
     * every line: a missing file and a `stat()` that raced away answer the same
     * `null`, and a pane tab whose path is not a file answers it too, which is
     * why the caller can compare answers without excluding them first.
     */
    private static function fileId(string $path): ?string
    {
        $stat = \is_file($path) ? \stat($path) : false;

        return \is_array($stat) ? $stat['dev'].':'.$stat['ino'] : null;
    }

    public function has(string $path): bool
    {
        return \array_key_exists($path, $this->buffers);
    }

    public function get(string $path): ?Buffer
    {
        return $this->buffers[$path] ?? null;
    }

    public function active(): ?Buffer
    {
        return null !== $this->activePath ? $this->buffers[$this->activePath] ?? null : null;
    }

    public function setActive(string $path): ?Buffer
    {
        if (!\array_key_exists($path, $this->buffers)) {
            return null;
        }
        $this->activePath = $path;
        $this->buffers[$path]->lastFocused = ++$this->focusCounter;

        return $this->buffers[$path];
    }

    /**
     * Activate the buffer $offset positions away in open order (wraps).
     */
    public function cycle(int $offset): ?Buffer
    {
        $paths = \array_keys($this->buffers);
        if (\count($paths) < 2 || null === $this->activePath) {
            return null;
        }
        $index = \array_search($this->activePath, $paths, strict: true);
        $next = $paths[((((int) $index + $offset) % \count($paths)) + \count($paths)) % \count($paths)];

        return $this->setActive($next);
    }

    /**
     * Close a buffer; returns the buffer to show next, or null if none left.
     */
    public function close(string $path): ?Buffer
    {
        if (!\array_key_exists($path, $this->buffers)) {
            return $this->active();
        }
        unset($this->buffers[$path]);

        if ($this->activePath !== $path) {
            return $this->active();
        }

        $next = null;
        foreach ($this->buffers as $buffer) {
            if (!(null === $next || $buffer->lastFocused > $next->lastFocused)) {
                continue;
            }

            $next = $buffer;
        }
        $this->activePath = $next?->path;
        if (null !== $next) {
            $next->lastFocused = ++$this->focusCounter;
        }

        return $next;
    }

    /**
     * Move the buffer at position $from to position $to in the open order (as
     * seen in the tab strip), preserving the Buffer objects and the active/focus
     * state. Out-of-range $from or a no-op move do nothing; $to is clamped.
     */
    public function reorder(int $from, int $to): void
    {
        $paths = \array_keys($this->buffers);
        $to = IndexMove::target($from, $to, \count($paths));
        if (null === $to) {
            return;
        }
        $paths = IndexMove::apply($paths, $from, $to);

        $reordered = [];
        foreach ($paths as $path) {
            $reordered[$path] = $this->buffers[$path];
        }
        $this->buffers = $reordered;
    }

    /**
     * Move the active buffer $offset positions in the open order (no wrap).
     * Returns true when it actually moved.
     */
    public function moveActive(int $offset): bool
    {
        if (null === $this->activePath) {
            return false;
        }
        $paths = \array_keys($this->buffers);
        $from = (int) \array_search($this->activePath, $paths, strict: true);
        $to = $from + $offset;
        if ($to < 0 || $to >= \count($paths)) {
            return false;
        }
        $this->reorder($from, $to);

        return true;
    }

    /** @return list<Buffer> in open order */
    public function all(): array
    {
        return \array_values($this->buffers);
    }

    /** @return list<Buffer> most recently focused first */
    public function recent(): array
    {
        $buffers = \array_values($this->buffers);
        \usort($buffers, static fn (Buffer $a, Buffer $b): int => $b->lastFocused <=> $a->lastFocused);

        return $buffers;
    }

    public function dirtyCount(): int
    {
        return \count(\array_filter($this->buffers, static fn (Buffer $b): bool => $b->document->isDirty()));
    }
}
