<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Container\AsIdeService;
use Porta\Support\ProjectPaths;

/**
 * The places you jumped away from, oldest first — pushed by every navigation
 * verb before it moves the caret, popped by navigate-back.
 *
 * A service rather than a field on {@see \Porta\Lsp\NavigationController}
 * because it gained a second reader: PhpStorm's Recent Locations lists the
 * whole stack instead of walking it one pop at a time, and BROWSING the list
 * must not consume it.
 */
#[AsIdeService]
final class JumpHistory
{
    /**
     * Deep enough to cover a session's wandering, shallow enough that the list
     * stays a list. The number is PhpStorm's.
     */
    private const int DEPTH = 50;

    /** @var list<array{path: string, line: int, col: int}> */
    private array $stack = [];

    public function push(string $path, int $line, int $col): void
    {
        $this->stack[] = ['path' => $path, 'line' => $line, 'col' => $col];
        if (\count($this->stack) > self::DEPTH) {
            array_shift($this->stack);
        }
    }

    /**
     * Follow a renamed file — or a renamed DIRECTORY, which moves every entry
     * inside it.
     *
     * {@see EditHistory} has done this since the fact existed and this list did
     * not, though they are siblings and Recent Locations reads this one. The
     * asymmetry was invisible because a stale entry fails quietly: navigate-back
     * refuses to open a path that is gone and leaves the caret where it was, so
     * the key simply does nothing, once per dead entry, up to fifty of them.
     */
    public function rename(string $from, string $to): void
    {
        foreach ($this->stack as $index => $entry) {
            $moved = ProjectPaths::moved($entry['path'], $from, $to);
            if (null === $moved) {
                continue;
            }
            $this->stack[$index] = ['path' => $moved, 'line' => $entry['line'], 'col' => $entry['col']];
        }
    }

    /** Drop every place in a deleted file, or under a deleted directory. */
    public function forget(string $path): void
    {
        // Walked rather than filtered: `array_filter` preserves keys, so the
        // list would need re-indexing — and NOTHING observable would say
        // whether it had been, because `entries()` reverses the stack and that
        // re-indexes for free. Only the property's `list<>` type would catch
        // it, and a type is a gate, not a test.
        $kept = [];
        foreach ($this->stack as $entry) {
            if (ProjectPaths::isUnder($entry['path'], $path)) {
                continue;
            }
            $kept[] = $entry;
        }
        $this->stack = $kept;
    }

    /** @return array{path: string, line: int, col: int}|null */
    public function pop(): ?array
    {
        return array_pop($this->stack);
    }

    /** @return list<array{path: string, line: int, col: int}> most recent first, as the list reads */
    public function entries(): array
    {
        return array_reverse($this->stack);
    }
}
