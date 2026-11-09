<?php

declare(strict_types=1);

namespace Porta\Install;

use Porta\Support\Files;

/**
 * How the running Porta was installed, and so how it is updated: what
 * `porta --self-update` and the Command line page act on.
 *
 * Read off paths alone, never by asking a tool — the page builds its rows on
 * the loop. A phar under Homebrew's `Cellar/porta/` is brew's: swapping it
 * behind brew's back would leave brew's record lying, and through the
 * `bin/porta` symlink the rename replaced the link itself with a file. Any
 * other phar is resolved through its links too, so the swap lands on the
 * file, not on a link to it.
 */
final readonly class Installation
{
    public const string PACKAGE = 'janalis/porta';

    private const string FORMULA = 'janalis/tap/porta';

    /**
     * @param string $path  what the update acts on: the phar's resolved file, or the folder Composer or git runs in
     * @param string $entry the phar as it was run, before links were followed — what a brew upgrade repoints
     */
    public function __construct(
        public InstallMethod $method,
        public string $path,
        private string $entry = '',
    ) {}

    /**
     * @param string                      $phar       the phar running, '' when not one ({@see PortaBinary::phar()})
     * @param string                      $packageDir the folder holding this package's `bin/porta` ({@see PortaBinary::packageDir()})
     * @param array<string, string|false> $env        the environment, for where Composer keeps its global install
     */
    public static function detect(string $phar, string $packageDir, array $env): self
    {
        $file = Files::canonical($phar);
        $package = Files::canonical($packageDir);
        $vendor = \dirname($package, levels: 2);
        $root = \dirname($vendor);

        return match (true) {
            '' !== $phar => new self(\str_contains($file, '/Cellar/porta/') ? InstallMethod::Homebrew : InstallMethod::Phar, $file, $phar),
            \str_ends_with($package, '/'.self::PACKAGE) && \is_file($vendor.'/composer/installed.php') => new self(
                \in_array($root, self::composerHomes($env), strict: true) ? InstallMethod::ComposerGlobal : InstallMethod::ComposerProject,
                $root,
            ),
            // A checkout — or a copy of one without its .git, for which pulling is still the way.
            default => new self(InstallMethod::Checkout, $package),
        };
    }

    /**
     * The commands that update this install, in order: run by `--self-update`
     * when {@see InstallMethod::updatesItself()}, else only shown. None for a
     * plain phar, which replaces itself.
     *
     * @return list<list<string>>
     */
    public function commands(): array
    {
        return match ($this->method) {
            InstallMethod::Phar => [],
            InstallMethod::Homebrew => [['brew', 'upgrade', self::FORMULA]],
            InstallMethod::ComposerGlobal => [['composer', 'global', 'update', self::PACKAGE, '--no-interaction']],
            InstallMethod::ComposerProject => [['composer', 'update', self::PACKAGE, '--working-dir='.$this->path]],
            InstallMethod::Checkout => [['git', '-C', $this->path, 'pull', '--ff-only'], ['composer', 'install', '--working-dir='.$this->path]],
        };
    }

    /** {@see commands()} as one line to paste into a shell. */
    public function commandLine(): string
    {
        return \implode(' && ', \array_map(
            static fn (array $command): string => \implode(' ', \array_map(
                static fn (string $arg): string => 1 === \preg_match('~^[\w/.,:=@%+-]+$~', $arg) ? $arg : \escapeshellarg($arg),
                $command,
            )),
            $this->commands(),
        ));
    }

    /**
     * A value that changes when an update lands — the Command line page tells
     * "updated, restart" from "already current" by it, not by what was said.
     */
    public function fingerprint(): string
    {
        return match ($this->method) {
            InstallMethod::Phar => \hash('xxh128', Files::read($this->path)),
            // brew installs each version in its own Cellar folder and repoints the link.
            InstallMethod::Homebrew => Files::canonical($this->entry),
            InstallMethod::ComposerGlobal, InstallMethod::ComposerProject => \hash('xxh128', Files::read($this->path.'/vendor/composer/installed.php')),
            // Never updated from here: its command is only shown.
            InstallMethod::Checkout => '',
        };
    }

    /**
     * Where Composer may keep its global install — its own rule: $COMPOSER_HOME
     * when set, else `~/.composer`, else the XDG config folder's `composer`.
     *
     * @param array<string, string|false> $env
     *
     * @return list<string>
     */
    private static function composerHomes(array $env): array
    {
        $set = static fn (string $name): string => \is_string($env[$name] ?? null) ? $env[$name] : '';
        $home = \rtrim($set('HOME'), characters: '/');
        $candidates = '' !== $set('COMPOSER_HOME')
            ? [$set('COMPOSER_HOME')]
            : [$home.'/.composer', ('' !== $set('XDG_CONFIG_HOME') ? $set('XDG_CONFIG_HOME') : $home.'/.config').'/composer'];

        return \array_map(Files::canonical(...), $candidates);
    }
}
