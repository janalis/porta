<?php

declare(strict_types=1);

namespace Porta\Workbench\Menu;

use Porta\Container\AsIdeService;

/**
 * Which file a menu invocation is ABOUT.
 *
 * A context menu opened on a tree row or a tab names a path, and that path is
 * usually not the active buffer's — right-clicking `Other.php` in the tree to
 * ask for its history is the whole point. Core's own tree items capture the
 * node in a closure, but a plugin item is a COMMAND ID: it carries no argument,
 * so the path has to reach the handler some other way.
 *
 * It travels here, set for the duration of the dispatch and cleared after, and
 * plugins read it through `PluginApi::contextFile()` (which falls back to the
 * active file, so a command invoked from the palette or a keystroke still
 * means "this file"). Deliberately scoped to ONE call rather than left
 * standing while the menu is open: a stale path outliving its menu would make
 * the NEXT invocation act on the wrong file, which is the failure this exists
 * to prevent.
 */
#[AsIdeService]
final class MenuFileContext
{
    private ?string $file = null;

    /** Run $action with $path as the invocation's subject. */
    public function with(string $path, \Closure $action): void
    {
        $previous = $this->file;
        $this->file = $path;
        try {
            $action();
        } finally {
            $this->file = $previous;
        }
    }

    /** The path the running invocation is about, or null outside one. */
    public function file(): ?string
    {
        return $this->file;
    }
}
