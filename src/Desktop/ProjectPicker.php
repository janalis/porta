<?php

declare(strict_types=1);

namespace Porta\Desktop;

use Porta\Config\ConfigFiles;
use Porta\Config\IdeConfig;
use Porta\Config\PortaHome;
use Porta\Filesystem\ProjectLocation;
use Porta\Filesystem\RemoteSpec;
use Porta\Highlight\ColorMode;
use Porta\History\RecentProjectsStore;
use Porta\I18n\SystemLocale;
use Porta\I18n\Translator;
use Porta\Settings\SettingsProvenance;
use Porta\Support\ControlChars;
use Porta\Ui\NonBlockingTerminal;
use Porta\Ui\Theme\Styles;
use Porta\Ui\Theme\Themes;
use Porta\Widget\Desktop\ProjectPickerWidget;
use Porta\Widget\Support\PickerRow;
use Porta\Widget\Support\PickerRowKind;
use Porta\Workbench\RecentProjectChoices;
use Revolt\EventLoop;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Terminal\Terminal;
use Symfony\Component\Tui\Terminal\TerminalInterface;
use Symfony\Component\Tui\Tui;

/**
 * What an icon click opens. A terminal launch has a working directory that
 * means something; a desktop launch has the home folder, which is no project.
 *
 * Runs before the IDE boots, because booting is what makes a folder a project:
 * it would land in the recent list and ask to be trusted.
 *
 * One field does it all, as a launcher's does: a name looks among the recent
 * projects and the ones found on this computer, a path (`~`, `/`, `.`) lists
 * the folders it could go on to name, and a server's location is opened as
 * the switcher opens one. The rows are decided here; {@see ProjectPickerWidget}
 * draws them.
 */
final class ProjectPicker
{
    private const string REMOTE = "\0remote";

    private const string CHOOSE_FOLDER = "\0choose-folder";

    private ?string $picked = null;

    private ?Tui $tui = null;

    /**
     * @param \Closure(list<string>): list<array{label: string, hint: string, value: string}> $found the projects on this computer, less those given
     */
    public function __construct(
        private readonly RecentProjectChoices $choices,
        private readonly TerminalInterface $terminal = new NonBlockingTerminal(new Terminal()),
        private readonly Translator $translator = new Translator('en'),
        private readonly ?\Closure $found = null,
        private readonly ?Styles $styles = null,
        private readonly string $version = '',
        private readonly DesktopSession $session = new DesktopSession(active: false, token: ''),
    ) {}

    /** @param TerminalInterface|null $terminal null for the process's own */
    public static function forUser(string $version = '', ?TerminalInterface $terminal = null): self
    {
        $cwd = (string) \getcwd();
        $settings = IdeConfig::load($cwd)->settings;
        $session = DesktopSession::current();
        // The system's mode, as the window already shows it, unless a theme was
        // chosen: the rule the IDE follows once the window can tell it.
        $theme =
            null !== $session->appearance && null === new SettingsProvenance(ConfigFiles::for($cwd))->originOf(['settings', 'theme'])
                ? $session->appearance
                : (string) ($settings['theme'] ?? 'dark');
        $language = (string) ($settings['language'] ?? 'auto');
        $system = SystemLocale::fromEnvironment(\getenv(), \extension_loaded('intl') ? \Locale::getDefault() : '');

        return new self(
            new RecentProjectChoices(new RecentProjectsStore()),
            terminal: $terminal ?? new NonBlockingTerminal(new Terminal()),
            translator: new Translator($language, systemLocale: $system),
            found: new ProjectDiscovery()->found(...),
            styles: Themes::createSwitchable(ColorMode::Ansi, $theme),
            version: $version,
            session: $session,
        );
    }

    /** @return string|null the folder to open; null when the user left without choosing */
    public function pick(): ?string
    {
        $this->tui = new Tui(terminal: $this->terminal);
        $widget = new ProjectPickerWidget(
            $this->translator,
            $this->version,
            rowsFor: $this->rowsFor(...),
            onSubmit: $this->submit(...),
            onTab: $this->complete(...),
            onCancel: fn () => $this->finish(null),
            footer: [
                $this->translator->trans('↑↓ choose · Tab complete · Enter open · Esc quit'),
                ...($this->session->active ? [$this->translator->trans('or drop a folder on this window')] : []),
            ],
            styles: $this->styles,
        );
        $this->tui->add($widget);
        $this->tui->setFocus($widget);
        if ($this->session->active) {
            // Ahead of the field: a frame is never text.
            $this->tui->addListener($this->onInput(...), priority: 2200);
            // Once the loop runs, which is once the terminal is the picker's.
            EventLoop::defer(fn () => $this->send('picking'));
        }
        $this->tui->run();

        return $this->picked;
    }

    /** @return list<PickerRow> */
    private function rowsFor(string $typed): array
    {
        $remote = FolderCompletion::isPath($typed) ? null : RemoteSpec::parse($typed);

        return match (true) {
            FolderCompletion::isPath($typed) => $this->folderRows($typed),
            null !== $remote => [new PickerRow(PickerRowKind::Remote, ControlChars::line($typed), $this->translator->trans('Remote project'), $typed)],
            \str_contains($typed, '://') => [PickerRow::note($this->translator->trans('user@host:/path, or an ssh://, sftp://, ftp:// or ftps:// URL'))],
            default => $this->projectRows($typed),
        };
    }

    /**
     * The recent projects, then the ones found on this computer, those whose
     * name holds $typed; with nothing typed, the ways to name one that is not
     * listed below them.
     *
     * @return list<PickerRow>
     */
    private function projectRows(string $typed): array
    {
        $recent = $this->choices->except();
        $found = null === $this->found ? [] : ($this->found)(\array_column($recent, 'value'));
        $rows = [...$this->section($this->translator->trans('Recent'), $recent, $typed), ...$this->section($this->translator->trans('Found on this computer'), $found, $typed)];
        if ('' !== $typed) {
            return [] === $rows ? [PickerRow::note($this->translator->trans('No project is called that: start a path with ~ or /'))] : $rows;
        }
        if ([] === $rows) {
            $rows[] = PickerRow::note($this->translator->trans('Type the path of a folder to open, starting with ~ or /'));
        }

        return [
            ...$rows,
            PickerRow::heading(''),
            ...(
                $this->session->active
                    ? [new PickerRow(PickerRowKind::Action, $this->translator->trans('Choose a folder…'), $this->translator->trans('in the system’s dialog'), self::CHOOSE_FOLDER)]
                    : []
            ),
            new PickerRow(PickerRowKind::Action, $this->translator->trans('Open remote…'), $this->translator->trans('over SSH, SFTP or FTP'), self::REMOTE),
        ];
    }

    /**
     * $title's rule and the $items whose name holds $typed, any case; nothing
     * when none does, so an empty section never shows.
     *
     * @param list<array{label: string, hint: string, value: string}> $items
     *
     * @return list<PickerRow>
     */
    private function section(string $title, array $items, string $typed): array
    {
        $rows = [];
        foreach ($items as $item) {
            if ('' !== $typed && false === \mb_stripos($item['label'], $typed)) {
                continue;
            }
            $rows[] = new PickerRow(PickerRowKind::Project, $item['label'], $item['hint'], $item['value']);
        }

        return [] === $rows ? [] : [PickerRow::heading($title), ...$rows];
    }

    /**
     * The folder $typed names when it is one, then the folders it could go
     * on to name.
     *
     * @return list<PickerRow>
     */
    private function folderRows(string $typed): array
    {
        $rows = [];
        $real = \realpath(PortaHome::expand($typed));
        if (false !== $real && \is_dir($real)) {
            $rows[] = new PickerRow(PickerRowKind::Project, ControlChars::line(PortaHome::tilde($real)), $this->translator->trans('Open this folder'), $real);
        }
        foreach (FolderCompletion::candidates($typed) as $candidate) {
            $rows[] = new PickerRow(PickerRowKind::Folder, $candidate['name'].'/', $candidate['isProject'] ? $this->translator->trans('PHP project') : '', $candidate['path']);
        }

        return $rows;
    }

    /** Tab: a path completes; a project's name becomes its path, to go on from. */
    private function complete(?PickerRow $row, string $typed): string
    {
        return match (true) {
            FolderCompletion::isPath($typed) => FolderCompletion::complete($typed, PickerRowKind::Folder === $row?->kind ? $row->value : null),
            PickerRowKind::Project === $row?->kind => PortaHome::tilde($row->value).'/',
            default => $typed,
        };
    }

    private function submit(ProjectPickerWidget $widget, ?PickerRow $row, string $typed): void
    {
        match ($row?->kind) {
            PickerRowKind::Project => $this->finish($row->value),
            PickerRowKind::Folder => $this->finish((string) \realpath(PortaHome::expand($row->value))),
            PickerRowKind::Remote => $this->openRemote($widget, $row->value),
            PickerRowKind::Action => self::CHOOSE_FOLDER === $row->value ? $this->send('chooseFolder') : $widget->setText('ssh://'),
            // A name that matched nothing already says so in the list.
            default => FolderCompletion::isPath($typed) ? $widget->refuse($this->translator->trans('Not a folder: %path%', ['%path%' => ControlChars::line($typed)])) : null,
        };
    }

    /**
     * A server's project opens from the local folder that mirrors it, made
     * here as the switcher makes it; the IDE that boots on it connects. With
     * no home there is nowhere to make it.
     */
    private function openRemote(ProjectPickerWidget $widget, string $location): void
    {
        $spec = RemoteSpec::parse($location);
        $home = PortaHome::base();
        if (null === $spec || null === $home) {
            $widget->refuse($this->translator->trans('Not a folder: %path%', ['%path%' => ControlChars::line($location)]));

            return;
        }
        $this->finish(ProjectLocation::prepare($spec, $home));
    }

    /**
     * What the window says while the picker is up: a folder chosen in its
     * dialog, dropped on it, or opened with Porta while this window was the
     * one waiting for a project. Every frame is swallowed, the window's or not.
     */
    private function onInput(InputEvent $event): void
    {
        if (!DesktopFrames::isFrame($event->getData())) {
            return;
        }
        $event->stopPropagation();
        $request = new DesktopFrames($this->session)->request($event->getData());
        $path = null === $request ? null : DesktopFrames::openPath($request);
        if (null !== $path && \file_exists($path)) {
            $this->finish($path);
        }
    }

    private function send(string $message): void
    {
        $this->terminal->write(new DesktopFrames($this->session)->frame($message));
    }

    private function finish(?string $folder): void
    {
        $this->picked = $folder;
        $this->tui?->stop();
    }
}
