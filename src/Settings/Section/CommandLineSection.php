<?php

declare(strict_types=1);

namespace Porta\Settings\Section;

use Porta\I18n\Translator;
use Porta\Install\CommandLink;
use Porta\Install\CommandLinkStatus;
use Porta\Install\GitToolSetup;
use Porta\Install\GitToolStatus;
use Porta\Install\Installation;
use Porta\Install\InstallMethod;
use Porta\Install\PortaBinary;
use Porta\Settings\SettingsPage;
use Porta\Settings\SettingsRow;
use Porta\Settings\SettingsRowKind;
use Porta\Settings\SettingsSection;
use Porta\Support\AsyncProcess;
use Porta\Workbench\Notifier;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The Command line page: Porta outside the IDE — the `porta` command on PATH,
 * Porta as git's difftool and mergetool, and updating Porta itself the way
 * it was installed. Each one is installed, updated and removed from here; what the page
 * shows is read afresh every time its rows are built, so a change made from a
 * shell meanwhile is what the page says.
 *
 * None of it is project configuration — the link lives in `~/.local/bin`, the
 * tools in the GLOBAL git config — so the page has no scope row. The first two
 * are a stat and one `git config` read, done in line; the update talks to
 * GitHub, so it runs `porta --self-update` as a child process off the loop.
 * A removal takes two presses, like a plugin's.
 */
#[AsTaggedItem(priority: 450)]
final class CommandLineSection implements SettingsSection
{
    public const string ID = 'commandLine';

    private const string LINK = 'cli:link';
    private const string UNLINK = 'cli:unlink';
    private const string GIT = 'cli:git';
    private const string UNGIT = 'cli:ungit';
    private const string UPDATE = 'cli:update';

    private const string UPDATE_SLOT = 'self-update';

    /** Seconds an update may take: `brew upgrade` refreshes its taps first, and may upgrade PHP along. */
    private const float UPDATE_TIMEOUT = 900.0;

    /** The removal key waiting for its second press. */
    private ?string $armed = null;

    private ?AsyncProcess $update = null;

    /**
     * @param string|null $binary the binary installs point at; null asks {@see PortaBinary}
     * @param Installation|null $installation how this Porta was installed, which the update follows; null reads it
     *                                        off {@see PortaBinary}
     */
    public function __construct(
        private readonly Translator $translator,
        private readonly Notifier $notifier,
        private readonly ?string $binary = null,
        private readonly ?Installation $installation = null,
    ) {}

    /** @return list<SettingsPage> */
    #[\Override]
    public function pages(): array
    {
        return [new SettingsPage(self::ID, $this->translator->trans('Command line'))];
    }

    #[\Override]
    public function handles(string $section): bool
    {
        return self::ID === $section;
    }

    /** @return list<SettingsRow> */
    #[\Override]
    public function rows(string $section): array
    {
        return [...$this->linkRows(), ...$this->gitRows(), $this->updateRow()];
    }

    #[\Override]
    public function change(string $section, string $key, bool|int|string $value): bool
    {
        $armed = $this->armed;
        $this->armed = null;
        match ($key) {
            self::LINK => $this->report($this->link()->link(), $this->translator->trans('porta is now a command: %path%', ['%path%' => self::shown($this->link()->target())])),
            self::GIT => $this->report($this->git()->apply(), $this->translator->trans('porta is now git\'s difftool and mergetool')),
            self::UNLINK, self::UNGIT => $armed === $key ? $this->remove($key) : $this->arm($key),
            self::UPDATE => $this->selfUpdate(),
            default => null,
        };

        return true;
    }

    /** Nothing here has a default to go back to: every row is a button. */
    #[\Override]
    public function reset(string $section, string $key): void {}

    /** @return list<SettingsRow> */
    private function linkRows(): array
    {
        $link = $this->link();
        $status = $link->status();
        $path = self::shown($link->target());
        $hint = match ($status) {
            CommandLinkStatus::Absent => $this->translator->trans('Links %path% to this Porta', ['%path%' => $path]),
            CommandLinkStatus::Current => $this->translator->trans('%path% runs this Porta', ['%path%' => $path]),
            CommandLinkStatus::Stale => $this->translator->trans('%path% runs another Porta: %other%', ['%path%' => $path, '%other%' => self::shown($link->pointsAt())]),
            CommandLinkStatus::Foreign => $this->translator->trans('%path% was installed another way; update or remove it that way', ['%path%' => $path]),
        };
        if (!$link->onPath()) {
            $hint .= ' — '.$this->translator->trans('%dir% is not on your PATH', ['%dir%' => \dirname($path)]);
        }

        return $this->statusRows(
            fieldset: $this->translator->trans('The porta command'),
            label: $this->translator->trans('porta in a terminal'),
            hint: $hint,
            action: match ($status) {
                CommandLinkStatus::Absent => $this->translator->trans('Install'),
                CommandLinkStatus::Stale => $this->translator->trans('Update'),
                default => null,
            },
            inert: CommandLinkStatus::Foreign === $status ? $this->translator->trans('Installed') : $this->translator->trans('Up to date'),
            key: self::LINK,
            removal: CommandLinkStatus::Current === $status || CommandLinkStatus::Stale === $status ? $this->translator->trans('Remove the porta command') : null,
            removeKey: self::UNLINK,
        );
    }

    /** @return list<SettingsRow> */
    private function gitRows(): array
    {
        $status = $this->git()->status();

        return $this->statusRows(
            fieldset: $this->translator->trans('git difftool and mergetool'),
            label: $this->translator->trans('porta as git\'s diff and merge tool'),
            hint: match ($status) {
                GitToolStatus::Unavailable => $this->translator->trans('git is not installed'),
                GitToolStatus::Absent => $this->translator->trans('Sets diff.tool and merge.tool in your global git config'),
                GitToolStatus::Current => $this->translator->trans('git difftool and git mergetool open this Porta'),
                GitToolStatus::Stale => $this->translator->trans('Your global git config runs another Porta, or only part of the setup is there'),
            },
            action: match ($status) {
                GitToolStatus::Absent => $this->translator->trans('Set up'),
                GitToolStatus::Stale => $this->translator->trans('Update'),
                default => null,
            },
            inert: GitToolStatus::Unavailable === $status ? $this->translator->trans('Unavailable') : $this->translator->trans('Up to date'),
            key: self::GIT,
            removal: GitToolStatus::Current === $status || GitToolStatus::Stale === $status ? $this->translator->trans('Remove porta from git') : null,
            removeKey: self::UNGIT,
        );
    }

    /**
     * A status row — a button when there is something to do, else the state
     * ($inert) as an inert one — and, while there is something to remove
     * ($removal, its label), its Remove.
     *
     * @return list<SettingsRow>
     */
    private function statusRows(string $fieldset, string $label, string $hint, ?string $action, string $inert, string $key, ?string $removal, string $removeKey): array
    {
        $rows = [new SettingsRow(
            key: $key,
            label: $label,
            kind: SettingsRowKind::Action,
            value: $action ?? $inert,
            restart: false,
            hint: $hint,
            fieldset: $fieldset,
            disabled: null === $action,
        )];
        if (null !== $removal) {
            $rows[] = new SettingsRow(
                key: $removeKey,
                label: $removal,
                kind: SettingsRowKind::Action,
                value: $this->translator->trans('Remove'),
                restart: false,
                // The armed half of the two-press confirm reads as danger.
                danger: $this->armed === $removeKey,
                fieldset: $fieldset,
            );
        }

        return $rows;
    }

    private function updateRow(): SettingsRow
    {
        $installation = $this->installation();
        $command = ['%command%' => $installation->commandLine()];

        return new SettingsRow(
            key: self::UPDATE,
            label: $this->translator->trans('Porta itself'),
            kind: SettingsRowKind::Action,
            value: $this->translator->trans('Update'),
            restart: false,
            hint: match ($installation->method) {
                InstallMethod::Phar => $this->translator->trans('Replaces %path% with the latest release', ['%path%' => self::shown($installation->path)]),
                InstallMethod::Homebrew, InstallMethod::ComposerGlobal => $this->translator->trans('Runs %command%', $command),
                // Said from where it is run, without the path: the page is about this Porta.
                InstallMethod::ComposerProject => $this->translator->trans('Update it from a shell: %command%', ['%command%' => 'composer update '.Installation::PACKAGE]),
                InstallMethod::Checkout => $this->translator->trans('Update it from a shell: %command%', ['%command%' => 'git pull --ff-only && composer install']),
            },
            fieldset: $this->translator->trans('Updates'),
            disabled: !$installation->method->updatesItself(),
        );
    }

    private function arm(string $key): void
    {
        $this->armed = $key;
        $this->notifier->flash($this->translator->trans('Press Enter again to remove it'));
    }

    private function remove(string $key): void
    {
        self::UNLINK === $key
            ? $this->report($this->link()->unlink(), $this->translator->trans('The porta command is removed'))
            : $this->report($this->git()->remove(), $this->translator->trans('porta is no longer git\'s difftool and mergetool'));
    }

    private function report(?string $error, string $done): void
    {
        null === $error ? $this->notifier->success($done, 5.0) : $this->notifier->error($this->translator->trans('Could not do it: %why%', ['%why%' => $error]));
    }

    /**
     * `porta --self-update`, as a child process: it talks to GitHub, and may
     * run brew or Composer, none of which the loop can wait on. Whether it
     * changed anything is read off the install itself, not its output; the
     * output is what it said.
     */
    private function selfUpdate(): void
    {
        $installation = $this->installation();
        match (true) {
            !$installation->method->updatesItself() => null, // the row is inert then
            null !== $this->update => $this->notifier->warn($this->translator->trans('An update is already running')),
            default => $this->startSelfUpdate($installation),
        };
    }

    private function startSelfUpdate(Installation $installation): void
    {
        $binary = $this->binary ?? PortaBinary::path();
        $before = $installation->fingerprint();
        $this->update = new AsyncProcess([\PHP_BINARY, $binary, '--self-update', '--no-ansi'], \dirname($binary), captureStderr: true, timeout: self::UPDATE_TIMEOUT);
        $started = $this->update->start(function (int $code, string $out) use ($installation, $before): void {
            $said = self::said($out."\n".(string) $this->update?->errorOutput());
            $this->update = null;
            match (true) {
                0 !== $code => $this->notifier->error($said, 8.0, self::UPDATE_SLOT),
                $installation->fingerprint() !== $before => $this->notifier->success($said.' '.$this->translator->trans('Restart Porta to use it.'), 8.0, self::UPDATE_SLOT),
                default => $this->notifier->success($said, 5.0, self::UPDATE_SLOT),
            };
        });
        if (!$started) {
            // proc_open failure is environmental (fd exhaustion): PHP_BINARY is there
            // @codeCoverageIgnoreStart
            $this->update = null;
            $this->notifier->error($this->translator->trans('Could not run the update'));

            return;

            // @codeCoverageIgnoreEnd
        }
        // Up for as long as the update runs: its outcome replaces it.
        $this->notifier->flash($this->translator->trans('Looking for a newer Porta…'), self::UPDATE_TIMEOUT, self::UPDATE_SLOT);
    }

    /** What a console command said, on one line, without the box an error is drawn in. */
    private static function said(string $output): string
    {
        return \trim((string) \preg_replace('/\s+/', replacement: ' ', subject: \str_replace('[ERROR]', replace: '', subject: $output)));
    }

    /** $path as a hint shows it: under the home directory, from `~`. */
    private static function shown(string $path): string
    {
        $home = \rtrim((string) \getenv('HOME'), characters: '/');

        return '' !== $home && \str_starts_with($path, $home.'/') ? '~'.\substr($path, \strlen($home)) : $path;
    }

    private function installation(): Installation
    {
        return $this->installation ?? Installation::detect(PortaBinary::phar(), PortaBinary::packageDir(), \getenv());
    }

    private function link(): CommandLink
    {
        return new CommandLink($this->binary ?? PortaBinary::path(), (string) \getenv('PATH'), (string) \getenv('HOME'));
    }

    private function git(): GitToolSetup
    {
        return new GitToolSetup($this->binary ?? PortaBinary::path(), self::runGit(...));
    }

    /**
     * `git <args>`, in line: a config read or write takes milliseconds.
     *
     * @param list<string> $args
     *
     * @return array{int, string}
     */
    private static function runGit(array $args): array
    {
        $git = new ExecutableFinder()->find('git');
        if (null === $git) {
            return [127, ''];
        }
        $process = new Process([$git, ...$args]);
        $process->run();

        return [(int) $process->getExitCode(), $process->getOutput()];
    }
}
