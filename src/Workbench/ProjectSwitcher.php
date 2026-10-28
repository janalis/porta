<?php

declare(strict_types=1);

namespace Porta\Workbench;

use Porta\Config\ActionCatalog;
use Porta\Config\IdeConfig;
use Porta\Config\PortaHome;
use Porta\Config\WorkspaceTrust;
use Porta\Container\AsIdeService;
use Porta\Event\ProjectOpenRequested;
use Porta\Filesystem\ProjectLocation;
use Porta\Filesystem\RemoteSpec;
use Porta\History\RecentProjectsStore;
use Porta\I18n\Translator;
use Porta\Widget\CodeEditorWidget;
use Porta\Workbench\Menu\MenuItem;
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
        private UnsavedChangesGuard $guard,
        private WorkspaceTrust $trust,
        private IdeConfig $config,
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
            if ($root === $this->rootDir || !\is_dir($root)) {
                continue;
            }

            // A remote project's root is its cache, a hash deep in ~/.porta:
            // what a person knows it by is the server it mirrors.
            $remote = ProjectLocation::forRoot($root)->remote;
            $items[] = null === $remote
                ? ['label' => \basename($root), 'hint' => PortaHome::tilde($root), 'value' => $root]
                : ['label' => $remote->name(), 'hint' => $remote->label(), 'value' => $root];
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
            $this->openPath($root);
        });
    }

    /**
     * The right-click menu of the tree's project name: every folder beside
     * this project — the current one ticked and greyed — then the switcher, so
     * the menu is never a dead end. A pick is {@see openPath()}'s, unsaved
     * edits, trust and all: this project closes, that one opens.
     *
     * Every folder, not only those that look like projects: a fresh checkout
     * has nothing to recognise it by yet. A remote project's parent is the
     * cache, where nothing beside it is a project.
     *
     * @return list<MenuItem>
     */
    public function siblingItems(): array
    {
        $root = ($real = \realpath($this->rootDir)) ? $real : $this->rootDir;
        $items = [];
        if (null === ProjectLocation::forRoot($this->rootDir)->remote) {
            $parent = \dirname($root);
            // @mago-expect lint:no-error-control-operator -- a parent that cannot be listed warns; the false return is what is answered below
            $names = @\scandir($parent);
            $folders = \array_filter(
                false === $names ? [\basename($root)] : $names,
                static fn (string $name): bool => !\str_starts_with($name, '.') && \is_dir($parent.'/'.$name),
            );
            \usort($folders, \strnatcasecmp(...));
            foreach ($folders as $name) {
                $path = $parent.'/'.$name;
                $items[] = $path === $root
                    ? new MenuItem($name, '', null, isEnabled: static fn (): bool => false, isChecked: static fn (): bool => true)
                    : new MenuItem($name, '', fn () => $this->openPath($path));
            }
            $items[] = MenuItem::separator();
        }
        $items[] = new MenuItem($this->translator->trans(ActionCatalog::label('ide_open_project')), '', $this->open(...));

        return $items;
    }

    /**
     * Ask where a server's project is — `user@host:/path`, or an `ssh://`,
     * `sftp://`, `ftp://` or `ftps://` URL — and open it.
     */
    public function openRemote(): void
    {
        $this->editor->openPrompt($this->translator->trans('Open remote project (user@host:/path or URL)'), '', function (string $location): void {
            $location = \trim($location);
            if ('' !== $location) {
                $this->openPath($location);
            }
        });
    }

    /** Ask for a path, and open it if it is one. */
    private function prompt(): void
    {
        $this->editor->openPrompt($this->translator->trans('Open project (path)'), '', function (string $path): void {
            $path = \trim($path);
            if ('' !== $path) {
                $this->openPath($path);
            }
        });
    }

    /**
     * Switch to the project at $path, as picking it here would — a folder
     * dropped in from outside asks for exactly that ({@see ExternalDrop}).
     *
     * Refuse here rather than at the far end. The reopen tears this session
     * down first, so a path that turns out not to be a directory would leave
     * the user with no IDE at all and a message they never saw.
     *
     * $repeat is the already-translated "do it again" clause the unsaved-
     * changes refusal ends on. Only the caller knows what the user did: a
     * plugin's worktree pick is not Ctrl+Shift+O. Null is this switcher's own.
     */
    public function openPath(string $path, ?string $repeat = null): void
    {
        // A URL is a server's before anything looks at it here: is_dir() on
        // `ftp://…` is PHP's own FTP client, connecting — the IDE froze on it.
        // A bare `host:path` is checked here first, and a local directory
        // wins a name both could be.
        $url = \str_contains($path, '://');
        $expanded = $url ? $path : PortaHome::expand($path);
        $real = $url ? false : \realpath($expanded);
        $root = false === $real ? $expanded : $real;
        $remote = !$url && \is_dir($root) ? null : RemoteSpec::parse($path);
        $home = PortaHome::base();
        if (null !== $remote && null !== $home) {
            $root = ProjectLocation::prepare($remote, $home);
        }
        $refusal = match (true) {
            (null === $remote && $url) || !\is_dir($root) => $this->translator->trans('Not a directory: %path%', ['%path%' => $path]),
            // Both sides canonicalised before comparing. A launch resolves its root
            // through realpath (WorkspaceResolver), but nothing GUARANTEES the one
            // this service was handed is canonical — and on macOS a temp path is
            // `/var/...` while its realpath is `/private/var/...`, so a raw
            // comparison would call the project you are in a different one.
            $root === (($mine = \realpath($this->rootDir)) ? $mine : $this->rootDir) => $this->translator->trans('Already open'),
            default => null,
        };
        if (null !== $refusal) {
            $this->notifier->flash($refusal);

            return;
        }
        // Switching ENDS this session, and the IDE has no autosave: the teardown
        // writes the session, the debug state and the bookmarks, never buffer
        // contents. So it needs the same confirm quitting has — and it belongs
        // HERE rather than beside the `stop()`, so nothing has begun tearing
        // down at the moment the user is told.
        if ($this->guard->confirmed('switch-project', $repeat ?? $this->translator->trans('Ctrl+Shift+O again to switch project, Ctrl+S to save'))) {
            $this->askTrustThenOpen($root);
        }
    }

    /**
     * Trust the project this session is in, and reopen it so its own plugins
     * and tools load — the way back from having opened it restricted. Judged
     * by what THIS session loaded, not by the answers file another window
     * may have changed since.
     */
    public function trustCurrent(): void
    {
        if ($this->config->trusted) {
            $this->notifier->flash($this->translator->trans('This project is trusted already'));

            return;
        }
        $this->answerAndReopen(fn (): bool => $this->trust->trust($this->rootDir));
    }

    /** Stop trusting the project this session is in, and reopen it restricted. */
    public function restrictCurrent(): void
    {
        if (!$this->config->trusted) {
            $this->notifier->flash($this->translator->trans('This project is restricted already'));

            return;
        }
        if ($this->trust->trustsFolderOf($this->rootDir)) {
            // An answer cannot outrank PORTA_TRUSTED_FOLDERS: reopening would
            // load it all again and say nothing.
            $this->notifier->flash($this->translator->trans('PORTA_TRUSTED_FOLDERS trusts this project: take its folder out to restrict it'));

            return;
        }
        $this->answerAndReopen(fn (): bool => $this->trust->restrict($this->rootDir));
    }

    /**
     * Opening a project is running it, once it is trusted: one that has never
     * been answered for is asked about first ({@see WorkspaceTrust::shouldAsk()}).
     */
    private function askTrustThenOpen(string $root): void
    {
        if (!$this->trust->shouldAsk($root)) {
            $this->events->dispatch(new ProjectOpenRequested($root));

            return;
        }
        $items = [
            ['label' => $this->translator->trans('Trust and Open'), 'hint' => $this->translator->trans('its own plugins and tools run'), 'value' => 'trusted'],
            ['label' => $this->translator->trans('Open Restricted'), 'hint' => $this->translator->trans('nothing of the project\'s own runs, nor a language server'), 'value' => 'restricted'],
        ];
        $this->editor->openListOverlay($this->translator->trans('Trust %project%?', ['%project%' => \basename($root)]), $items, function (string $answer) use ($root): void {
            $saved = 'trusted' === $answer ? $this->trust->trust($root) : $this->trust->restrict($root);
            if (!$saved) {
                $this->notifier->error($this->translator->trans('Could not save the answer in ~/.porta/trusted-projects.json'));

                return;
            }
            $this->events->dispatch(new ProjectOpenRequested($root));
        });
    }

    /**
     * Reopening ENDS this session, so it takes the confirm switching does —
     * asked BEFORE the answer is written, so a refusal leaves nothing changed.
     *
     * @param \Closure(): bool $answer
     */
    private function answerAndReopen(\Closure $answer): void
    {
        if (!$this->guard->confirmed('reopen-project', $this->translator->trans('Run it again to reopen the project, Ctrl+S to save'))) {
            return;
        }
        if (!$answer()) {
            $this->notifier->error($this->translator->trans('Could not save the answer in ~/.porta/trusted-projects.json'));

            return;
        }
        $this->events->dispatch(new ProjectOpenRequested($this->rootDir));
    }
}
