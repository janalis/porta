<?php

declare(strict_types=1);

namespace Porta\Workbench;

use Porta\Container\AsIdeService;
use Porta\Event\ProjectOpenRequested;
use Porta\History\RecentProjectsStore;
use Porta\I18n\Translator;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Open a different project: pick one this IDE has opened before, or type a
 * path.
 *
 * Until now the only way to change project was to quit and relaunch with a
 * different argument, which is a strange thing for an IDE to ask — and it is
 * asked most often by the people least able to answer it, since the path you
 * want is usually one you have opened before and no longer remember.
 *
 * The pick does not reopen anything itself. A project root is fixed when
 * {@see \Porta\Ide} builds its container, so every service here belongs to the
 * project being LEFT; the switcher's job ends at naming the destination, and
 * {@see ProjectOpenRequested} carries it to the one object that can act.
 */
#[AsIdeService]
final readonly class ProjectSwitcher
{
    /** The list entry that asks for a path instead of offering one. */
    private const string TYPE_A_PATH = "\0type-a-path";

    public function __construct(
        private RecentProjectsStore $recent,
        private CodeEditorWidget $editor,
        private Notifier $notifier,
        private Translator $translator,
        private EventDispatcherInterface $events,
        private string $rootDir,
    ) {}

    /**
     * The switcher: recent projects, then the way to name one that is not
     * listed. A first run has nothing to list, so it goes straight to the
     * prompt rather than opening a menu with one entry in it.
     */
    public function open(): void
    {
        $items = [];
        foreach ($this->recent->all() as $root) {
            // The project you are IN is not somewhere to switch to, and one
            // that has been moved or deleted is an entry that answers a
            // keystroke with an error — the recent-files list drops those for
            // the same reason.
            if ($root === $this->rootDir || !is_dir($root)) {
                continue;
            }

            $items[] = ['label' => basename($root), 'hint' => self::tilde($root), 'value' => $root];
        }
        if ([] === $items) {
            $this->prompt();

            return;
        }

        $items[] = ['label' => $this->translator->trans('Open a path…'), 'hint' => '', 'value' => self::TYPE_A_PATH];
        $this->editor->openListOverlay($this->translator->trans('Open Project'), $items, function (string $root): void {
            if (self::TYPE_A_PATH === $root) {
                $this->prompt();

                return;
            }
            $this->requestOpen($root);
        });
    }

    /** Ask for a path, and open it if it is one. */
    private function prompt(): void
    {
        $this->editor->openPrompt($this->translator->trans('Open project (path)'), '', function (string $path): void {
            $path = trim($path);
            if ('' !== $path) {
                $this->requestOpen($path);
            }
        });
    }

    /**
     * Refuse here rather than at the far end. The reopen tears this session
     * down first, so a path that turns out not to be a directory would leave
     * the user with no IDE at all and a message they never saw.
     */
    private function requestOpen(string $path): void
    {
        $expanded = self::expand($path);
        $root = ($real = realpath($expanded)) ? $real : $expanded;
        if (!is_dir($root)) {
            $this->notifier->flash($this->translator->trans('Not a directory: %path%', ['%path%' => $path]));

            return;
        }
        // Both sides canonicalised before comparing. A launch resolves its root
        // through realpath (WorkspaceResolver), but nothing GUARANTEES the one
        // this service was handed is canonical — and on macOS a temp path is
        // `/var/...` while its realpath is `/private/var/...`, so a raw
        // comparison would call the project you are in a different one.
        if ($root === (($mine = realpath($this->rootDir)) ? $mine : $this->rootDir)) {
            $this->notifier->flash($this->translator->trans('Already open'));

            return;
        }

        $this->events->dispatch(new ProjectOpenRequested($root));
    }

    /** `~` is what a person types and what the hints show; nothing else expands it here. */
    private static function expand(string $path): string
    {
        $home = \Porta\Config\PortaHome::base();
        if (null === $home || '~' !== ($path[0] ?? '')) {
            return $path;
        }

        return $home.substr($path, offset: 1);
    }

    /** The inverse, for display: a home-relative path is shorter and reads as a place. */
    private static function tilde(string $path): string
    {
        $home = \Porta\Config\PortaHome::base();

        return null !== $home && str_starts_with($path, $home.'/') ? '~'.substr($path, \strlen($home)) : $path;
    }
}
