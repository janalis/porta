<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Highlight\DarkTerminalTheme;
use Porta\Image\ImageData;
use Porta\Support\IntMath;
use Tempest\Highlight\TerminalTheme;

/**
 * Holds all open editor buffers. Each buffer keeps its own Document (and
 * therefore cursor + undo history) and its own diagnostics, so switching
 * files never loses state.
 */
final class BufferManager
{
    /** @var array<string, Buffer> keyed by absolute path, in open order */
    private array $buffers = [];
    private ?string $activePath = null;
    private int $focusCounter = 0;
    private readonly TerminalTheme $syntaxTheme;

    public function __construct(?TerminalTheme $syntaxTheme = null)
    {
        $this->syntaxTheme = $syntaxTheme ?? new DarkTerminalTheme();
    }

    public function open(string $path, string $content): Buffer
    {
        $this->buffers[$path] ??= new Buffer($path, new Document($content), $this->syntaxTheme);
        $buffer = $this->buffers[$path];
        $this->activePath = $path;
        $buffer->lastFocused = ++$this->focusCounter;

        return $buffer;
    }

    /** Open an image tab: an empty document plus the decoded pixels. */
    public function openImage(string $path, ImageData $image): Buffer
    {
        $this->buffers[$path] ??= new Buffer($path, new Document(''), $this->syntaxTheme, $image);
        $buffer = $this->buffers[$path];
        $this->activePath = $path;
        $buffer->lastFocused = ++$this->focusCounter;

        return $buffer;
    }

    /** Open (or re-activate) a rendered help tab carrying the page's markdown. */
    public function openHelp(string $path, string $markdown): Buffer
    {
        $this->buffers[$path] ??= new Buffer($path, new Document(''), $this->syntaxTheme, helpMarkdown: $markdown);
        $buffer = $this->buffers[$path];
        $buffer->helpMarkdown = $markdown; // refresh when the page changed on disk
        $this->activePath = $path;
        $buffer->lastFocused = ++$this->focusCounter;

        return $buffer;
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
        $paths = array_keys($this->buffers);
        if (\count($paths) < 2 || null === $this->activePath) {
            return null;
        }
        $index = array_search($this->activePath, $paths, strict: true);
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
        $paths = array_keys($this->buffers);
        $count = \count($paths);
        if ($from < 0 || $from >= $count) {
            return;
        }
        $to = IntMath::clamp($to, 0, $count - 1);
        if ($from === $to) {
            return;
        }
        $moved = $paths[$from];
        array_splice($paths, $from, length: 1);
        array_splice($paths, $to, length: 0, replacement: $moved);

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
        $paths = array_keys($this->buffers);
        $from = (int) array_search($this->activePath, $paths, strict: true);
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
        return array_values($this->buffers);
    }

    /** @return list<Buffer> most recently focused first */
    public function recent(): array
    {
        $buffers = array_values($this->buffers);
        usort($buffers, static fn (Buffer $a, Buffer $b) => $b->lastFocused <=> $a->lastFocused);

        return $buffers;
    }

    public function dirtyCount(): int
    {
        return \count(array_filter($this->buffers, static fn (Buffer $b) => $b->document->isDirty()));
    }
}
