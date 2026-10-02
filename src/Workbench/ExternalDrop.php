<?php

declare(strict_types=1);

namespace Porta\Workbench;

use Porta\Container\AsIdeService;
use Porta\Editor\BufferManager;
use Porta\Editor\FileOpener;
use Porta\Editor\OpenFocus;
use Porta\Event\TreeDropRequested;
use Porta\I18n\Translator;
use Porta\Support\ProjectPaths;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Workbench\View\ViewRegistry;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Tui\Tui;

/**
 * Files and folders dragged in from OUTSIDE the IDE — Finder, a file manager,
 * a browser's downloads — and what each drop zone does with them.
 *
 * The zone is the whole decision, so it is the one argument besides the
 * paths. Who found the zone is not this class's business: kitty's
 * drag-and-drop protocol says where the pointer let go
 * ({@see \Porta\Input\DragDropProtocol}); every other terminal pastes the
 * paths into whatever has focus, and the focus is the zone
 * ({@see \Porta\Input\InputDispatcher}).
 *
 * - The tree COPIES into the folder, Finder's way: a taken name becomes
 *   `name (2)` — the source is somebody else's file, so nothing is moved
 *   and nothing is asked.
 * - An open file ASKS. A drop and a pasted path are the same bytes, and
 *   inserting a path into code is a thing people do on purpose.
 * - The tab strip, or an editor with no file, OPENS. A folder there
 *   is revealed in the tree when it is the project's; otherwise it becomes
 *   the project when nothing is open to lose its place, and when something is,
 *   its files open beside what is there.
 * - A text field TYPES the paths, plainly — what a terminal pasted was
 *   quoted for a shell, and a search box is not one.
 */
#[AsIdeService]
final class ExternalDrop
{
    /** More than this from one folder is a listing, not a set of tabs. */
    public const int MAX_FOLDER_FILES = 20;

    /** @see isTyping() */
    private bool $typing = false;

    public function __construct(
        private readonly CodeEditorWidget $editor,
        private readonly FileTreeWidget $tree,
        private readonly Tui $tui,
        private readonly BufferManager $buffers,
        private readonly FileOpener $opener,
        private readonly ProjectSwitcher $switcher,
        private readonly ViewRegistry $views,
        private readonly Notifier $notifier,
        private readonly Translator $translator,
        private readonly EventDispatcherInterface $events,
        private readonly string $rootDir,
    ) {}

    /**
     * @param non-empty-list<string> $paths  absolute and existing, spelled as dropped ({@see \Porta\Input\DroppedPaths})
     * @param string|null            $folder the tree folder under the drop; null outside the tree
     */
    public function drop(array $paths, DropZone $zone, ?string $folder = null): void
    {
        match ($zone) {
            DropZone::Tree => $this->copyInto($paths, $folder),
            DropZone::Editor => $this->ask($paths),
            DropZone::Empty => $this->open($paths),
            DropZone::Text => $this->typeText(\implode(' ', $paths)),
        };
    }

    /**
     * Whether the paste going through the input chain right now is one this
     * class is typing — a paste of paths that exist, which must not be
     * mistaken for a drop a second time ({@see \Porta\Input\PasteDrop}).
     */
    public function isTyping(): bool
    {
        return $this->typing;
    }

    /**
     * Type $text into the field that has the keys, back through the whole
     * input chain as a paste: which field that is, is the chain's to say — an
     * open picker before the focused widget, as for any key. Public for the
     * text a drop carried that names no file, which a field takes all the same
     * ({@see \Porta\Input\DragDropProtocol}).
     */
    public function typeText(string $text): void
    {
        $this->typing = true;

        try {
            $this->tui->handleInput("\x1b[200~".$text."\x1b[201~");
        } finally {
            $this->typing = false;
        }
    }

    /**
     * Through the tree's own drop command, so the copy inherits everything it
     * does: saving a dirty buffer first, refusing a folder into itself, the
     * refresh, and one working-tree announcement for the lot.
     *
     * Both sides are put in ONE spelling first: the tree keys its rows by the
     * raw root, a drop spells its paths however the terminal did, and on macOS
     * `/var` is `/private/var` — the guard against copying a folder into itself
     * compares the two, and a mismatch would sail past it into a mirror that
     * never ends. Only the folders are resolved: a dropped symlink keeps its
     * own name in the copy. A dropped path exists, so its folder resolves.
     *
     * @param non-empty-list<string> $paths
     */
    private function copyInto(array $paths, ?string $folder): void
    {
        $target = null === $folder ? false : \realpath($folder);
        if (false === $target) {
            $this->notifier->flash($this->translator->trans('Drop on a folder to copy into it'));

            return;
        }
        $sources = \array_map(static fn (string $path): string => (string) \realpath(\dirname($path)).'/'.\basename($path), $paths);
        $this->events->dispatch(new TreeDropRequested($sources, $target, copy: true, conflict: DropConflict::KeepBoth));
    }

    /** @param non-empty-list<string> $paths */
    private function ask(array $paths): void
    {
        $count = \count($paths);
        $title = 1 === $count
            ? $this->translator->trans('%name% dropped', ['%name%' => \basename($paths[0])])
            : $this->translator->trans('%count% items dropped', ['%count%' => $count]);
        $items = [
            ['label' => $this->translator->trans('Insert Path|Insert Paths', ['%count%' => $count]), 'hint' => '', 'value' => 'insert'],
            ['label' => $this->translator->trans('Open'), 'hint' => '', 'value' => 'open'],
        ];
        $this->tui->setFocus($this->editor);
        $this->editor->openListOverlay($title, $items, function (string $choice) use ($paths): void {
            if ('open' === $choice) {
                $this->open($paths);

                return;
            }
            // The plain paths, as a text field gets them — the shell's quoting
            // means nothing in code. Typed as the terminal's own paste, so it
            // lands as one would: in the clipboard ring, one line per caret,
            // refused by a read-only buffer.
            $this->editor->handleInput("\x1b[200~".\implode(' ', $paths)."\x1b[201~");
        });
        $this->tui->requestRender();
    }

    /** @param non-empty-list<string> $paths */
    private function open(array $paths): void
    {
        // The card stands in for the empty editor; what was dropped on it is
        // what the user wants to see next, not the card still covering it.
        if ($this->views->isOpen('welcome')) {
            $this->views->close('welcome');
        }
        // Asked once, before anything opens: a drop of a file AND a folder
        // must not have its own file count as the session it would replace.
        $nothingOpen = [] === $this->buffers->all();
        foreach ($paths as $path) {
            if (!\is_dir($path)) {
                $this->opener->openFile($path, OpenFocus::Editor);

                continue;
            }
            $this->openFolder($path, $nothingOpen && 1 === \count($paths));
        }
    }

    // @mago-expect lint:no-boolean-flag-parameter -- whether the folder may replace the session is decided once for the whole drop, by the caller that saw all of it.
    private function openFolder(string $folder, bool $mayBecomeProject): void
    {
        $root = \realpath($this->rootDir);
        $real = \realpath($folder);
        if (false !== $root && false !== $real && ProjectPaths::isUnder($real, $root)) {
            $this->tree->revealPath($folder);
            $this->tui->setFocus($this->tree);
            $this->tui->requestRender();

            return;
        }
        if ($mayBecomeProject) {
            $this->switcher->openPath($folder);

            return;
        }
        $names = \scandir($folder);
        // Hidden files are the folder's housekeeping — `.DS_Store` is binary
        // and first in any sorted listing — not what someone dropping it meant.
        $files = \array_values(\array_filter(
            false === $names ? [] : $names,
            static fn (string $name): bool => !\str_starts_with($name, '.') && \is_file($folder.'/'.$name),
        ));
        foreach (\array_slice($files, offset: 0, length: self::MAX_FOLDER_FILES) as $name) {
            $this->opener->openFile($folder.'/'.$name, OpenFocus::Editor);
        }
        if (\count($files) > self::MAX_FOLDER_FILES) {
            $this->notifier->flash($this->translator->trans('Opened %shown% of %count% files', ['%shown%' => self::MAX_FOLDER_FILES, '%count%' => \count($files)]));
        }
    }
}
