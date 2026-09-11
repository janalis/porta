<?php

declare(strict_types=1);

namespace Porta\Tree;

use Porta\Container\AsIdeService;

/**
 * Paths cut or copied in the file tree, waiting for a paste.
 *
 * Deliberately NOT {@see \Porta\Support\Clipboard}, which is the system text
 * clipboard and stays exactly as it is — "Copy Path" puts a STRING there for
 * another program to read, while this holds files for this tree to move. Wiring
 * both through one object would make Copy Path and Copy mean the same thing,
 * and the first of them to run would win.
 *
 * Its own class rather than two fields on {@see TreeOperations} because the
 * keyboard path and the context menu both reach it, and because "what is on the
 * clipboard" decides whether a Paste item is offered at all — a question asked
 * while building a menu, before any operation is chosen.
 */
#[AsIdeService]
final class TreePathClipboard
{
    /** @var list<string> */
    private array $paths = [];

    private bool $copy = false;

    /** @param list<string> $paths absolute, in tree order */
    public function cut(array $paths): void
    {
        $this->paths = $paths;
        $this->copy = false;
    }

    /** @param list<string> $paths absolute, in tree order */
    public function copy(array $paths): void
    {
        $this->paths = $paths;
        $this->copy = true;
    }

    public function isEmpty(): bool
    {
        return [] === $this->paths;
    }

    /** @return list<string> */
    public function paths(): array
    {
        return $this->paths;
    }

    /** Whether the pending paste copies rather than moves. */
    public function isCopy(): bool
    {
        return $this->copy;
    }

    /**
     * Forget the pending paths.
     *
     * A CUT is spent by its paste — the files are not there any more, and a
     * second paste would name paths that no longer exist. A copy survives, so
     * the same set can be pasted into several places, which is the behaviour
     * every file manager has.
     */
    public function clear(): void
    {
        $this->paths = [];
        $this->copy = false;
    }
}
