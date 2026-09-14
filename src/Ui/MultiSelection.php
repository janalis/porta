<?php

declare(strict_types=1);

namespace Porta\Ui;

/**
 * The set of rows a list has selected beyond its cursor, keyed by a stable
 * identity rather than by index.
 *
 * {@see ListSelection} owns the cursor — one index, which is what a list needs
 * to navigate. This owns the SET, and it is deliberately not the same object:
 * every other list in the IDE selects exactly one row, and widening
 * `ListSelection` for the one caller that does not would put a set nobody uses
 * on every picker in the app.
 *
 * Keyed by path because a tree rebuilds: a directory expands, a file is
 * created, and every index below the change moves. An index-keyed selection
 * would silently point at different files after a refresh, which is the worst
 * possible failure for a command that acts on "the selected files".
 * {@see keepOnly()} is the other half — a path that has left the tree leaves
 * the selection with it.
 */
final class MultiSelection
{
    /** @var array<string, true> */
    private array $paths = [];

    private ?string $anchor = null;

    /** Select exactly $path — a plain click, or moving the cursor with no modifier. */
    public function only(string $path): void
    {
        $this->paths = [$path => true];
        $this->anchor = $path;
    }

    /**
     * Add or remove one path, and anchor there.
     *
     * The anchor moves even when the path was REMOVED: the anchor is where the
     * next range starts from, and that is the row last acted on, not the row
     * last added.
     */
    public function toggle(string $path): void
    {
        if (\array_key_exists($path, $this->paths)) {
            unset($this->paths[$path]);
            $this->anchor = $path;

            return;
        }
        $this->paths[$path] = true;
        $this->anchor = $path;
    }

    /**
     * Select exactly $paths, leaving the anchor where it is — a range
     * extension, which is repeatable: shift-clicking twice from the same anchor
     * replaces the range rather than growing it.
     *
     * @param list<string> $paths
     */
    public function replaceRange(array $paths): void
    {
        $this->paths = [];
        foreach ($paths as $path) {
            $this->paths[$path] = true;
        }
    }

    public function has(string $path): bool
    {
        return \array_key_exists($path, $this->paths);
    }

    public function count(): int
    {
        return \count($this->paths);
    }

    /** Where a range extension starts, or null when nothing has been selected yet. */
    public function anchor(): ?string
    {
        return $this->anchor;
    }

    public function clear(): void
    {
        $this->paths = [];
        $this->anchor = null;
    }

    /**
     * Drop everything that is no longer in the list.
     *
     * @param list<string> $existing
     */
    public function keepOnly(array $existing): void
    {
        $keep = \array_fill_keys($existing, value: true);
        $this->paths = \array_intersect_key($this->paths, $keep);
        if (null !== $this->anchor && !\array_key_exists($this->anchor, $keep)) {
            $this->anchor = null;
        }
    }
}
