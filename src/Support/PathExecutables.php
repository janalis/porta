<?php

declare(strict_types=1);

namespace Porta\Support;

use Symfony\Component\Process\ExecutableFinder;

/**
 * A program on the PATH, as Symfony's ExecutableFinder finds it — except under
 * WSL, where the PATH also carries every folder of Windows' own PATH, on the
 * Windows drives (`/mnt/c/Program Files/…`). Each stat there crosses WSL's
 * bridge to the Windows disk, and a program that is NOT installed is looked
 * for in every one of them: the desktop application's startup tool check
 * blocked the event loop for over five seconds that way, swallowing the keys
 * typed meanwhile (`.porta/freeze.log` named ExecutableFinder::find('gh')).
 *
 * Skipping those folders loses nothing a lookup here could find: on Linux it
 * asks for the bare name (`gh`), and Windows' programs are `gh.exe`. Neither
 * does it fall back, as ExecutableFinder does, to a shell's `command -v`, which
 * would search the same folders. The PATH itself is left whole — a terminal in
 * Porta still runs `code .` or `explorer.exe` through it.
 */
final readonly class PathExecutables
{
    /**
     * @param string|null $wslPath the PATH under WSL, or null elsewhere
     * @param string $drives where WSL mounts the Windows drives, see {@see driveRoot()}
     */
    public function __construct(
        private ?string $wslPath,
        private string $drives = '/mnt/',
    ) {}

    /** This process's: WSL sets WSL_DISTRO_NAME in everything it starts. */
    public static function here(): self
    {
        // Read once: git asks for its binary at every spawn, and wsl.conf is read at WSL's own start only.
        static $drives = null;
        $path = \getenv('PATH');
        $path = false === $path ? '' : $path;
        $wsl = false !== \getenv('WSL_DISTRO_NAME');
        $drives ??= $wsl ? self::driveRoot('/etc/wsl.conf') : null;

        return new self($wsl ? $path : null, $drives ?? '/mnt/');
    }

    /**
     * Where WSL mounts the Windows drives, with a trailing slash: `/mnt/`, or the
     * `root` of `[automount]` in $wslConf (`root = /` puts them at `/c/`). Read
     * as WSL reads it: comments after `#` or `;`, names in any case, and a root
     * that is not an absolute path ignored.
     */
    public static function driveRoot(string $wslConf): string
    {
        $lines = \is_file($wslConf) ? \file($wslConf, flags: \FILE_IGNORE_NEW_LINES) : false;
        $section = '';
        $root = '';
        foreach (\is_array($lines) ? $lines : [] as $line) {
            $line = \trim((string) \preg_replace('/(^|\s)[#;].*$/', replacement: '', subject: $line));
            $section = 1 === \preg_match('/^\[\s*(.+?)\s*\]$/', $line, $header) ? \strtolower($header[1]) : $section;
            if ('automount' === $section && 1 === \preg_match('/^root\s*=\s*(.*)$/i', $line, $setting)) {
                $root = \trim($setting[1], characters: " \t\"'");
            }
        }

        return \str_starts_with($root, '/') ? \rtrim($root, characters: '/').'/' : '/mnt/';
    }

    /** The path of the program $name, or null when it is nowhere on the PATH. */
    public function find(string $name): ?string
    {
        return null === $this->wslPath ? new ExecutableFinder()->find($name) : $this->onLinuxPath($this->wslPath, $name);
    }

    /**
     * The first program $name in the PATH's folders that are not on a Windows
     * drive; an entry may name the program itself, as ExecutableFinder allows.
     * An empty entry is skipped, where ExecutableFinder reads the current
     * directory: Porta runs in the project, and a stray `:` would run an
     * untrusted project's own `./git` for git.
     */
    private function onLinuxPath(string $path, string $name): ?string
    {
        $found = null;
        foreach (\array_filter(\explode(\PATH_SEPARATOR, $path), static fn (string $entry): bool => '' !== $entry) as $dir) {
            $found ??= $this->onWindowsDrive($dir) ? null : $this->programIn($dir, $name);
        }

        return $found;
    }

    private function programIn(string $dir, string $name): ?string
    {
        $file = \rtrim($dir, characters: '/').'/'.$name;
        if (\is_file($file) && \is_executable($file)) {
            return $file;
        }

        return !\is_dir($dir) && \basename($dir) === $name && \is_executable($dir) ? $dir : null;
    }

    private function onWindowsDrive(string $dir): bool
    {
        return 1 === \preg_match('#^'.\preg_quote($this->drives, delimiter: '#').'[a-z](/|$)#', $dir);
    }
}
