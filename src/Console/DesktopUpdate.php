<?php

declare(strict_types=1);

namespace Porta\Console;

use Porta\Install\Installation;
use Symfony\Component\Filesystem\Filesystem;

/**
 * `porta --self-update` for the desktop application: the package it came in
 * decides how it is replaced.
 *
 * - A macOS disk image: the new bundle is copied out of the image, its
 *   signature checked, and swapped for the old one.
 * - A Linux tarball, installed in the user's own folders: the new folder
 *   swapped for the old one.
 * - A `.deb` or `.rpm`: the system's package manager installs it, through
 *   `pkexec`, which asks for the password the way the desktop does.
 * - The Windows installer: started on its own, quietly; it closes the
 *   application, installs, and opens it again.
 *
 * Every package is checked against `porta-desktop.sha256` before anything is
 * touched, and a swap that fails half-way puts the old one back.
 */
final readonly class DesktopUpdate
{
    private const string SUMS = 'porta-desktop.sha256';

    /**
     * @param \Closure(list<string>, ?string=): array{int, string} $run runs a command, returning its exit code and stdout
     */
    public function __construct(
        private SelfUpdate $update,
        private \Closure $run,
        private Filesystem $filesystem = new Filesystem(),
    ) {}

    /**
     * @throws \RuntimeException saying what went wrong; the installed application is untouched then
     */
    public function install(string $tag, Installation $installation): void
    {
        $runtime = \dirname($installation->path);
        $asset = $installation->desktopPackage();
        $scratch = \str_ends_with($asset, '.exe') ? \dirname($runtime).'/updates' : \sys_get_temp_dir().'/porta-update-'.\bin2hex(\random_bytes(6));
        $this->filesystem->mkdir($scratch, mode: 0o700);
        try {
            $this->update->fetch($tag, [$asset, self::SUMS], $scratch);
            $package = $scratch.'/'.$asset;
            if (\hash_file('sha256', $package) !== self::expected((string) \file_get_contents($scratch.'/'.self::SUMS), $asset)) {
                throw new \RuntimeException($asset.' does not match its checksum; nothing was changed.');
            }
            match (true) {
                \str_ends_with($asset, '.dmg') => $this->replaceBundle($package, \dirname($runtime, levels: 3), $scratch),
                \str_ends_with($asset, '.tar.gz') => $this->replaceFolder($package, \dirname($runtime), $scratch),
                \str_ends_with($asset, '.deb') => $this->runOrExplain(['pkexec', 'apt-get', 'install', '-y', $package], 'sudo apt install '.$package),
                \str_ends_with($asset, '.rpm') => $this->runOrExplain(['pkexec', 'dnf', 'install', '-y', $package], 'sudo dnf install '.$package),
                // Started apart: the installer closes this very application.
                default => $this->runOrExplain(['/bin/sh', '-c', '"$0" /VERYSILENT /SUPPRESSMSGBOXES /CLOSEAPPLICATIONS > /dev/null 2>&1 &', $package], $package),
            };
        } finally {
            if (!\str_ends_with($asset, '.exe')) {
                $this->filesystem->remove($scratch);
            }
        }
    }

    /** The digest `sha256sum` wrote for $asset, '' when it wrote none. */
    private static function expected(string $sums, string $asset): string
    {
        $match = [];

        return 1 === \preg_match('/^([0-9a-f]{64}) [ *]'.\preg_quote($asset, delimiter: '/').'$/m', $sums, $match) ? $match[1] : '';
    }

    private function replaceBundle(string $image, string $bundle, string $scratch): void
    {
        if (!\str_ends_with($bundle, '.app')) {
            throw new \RuntimeException('Porta is not inside an application bundle here; nothing was changed.');
        }
        $mounted = $scratch.'/image';
        $this->must(['hdiutil', 'attach', '-nobrowse', '-readonly', '-noautoopen', '-mountpoint', $mounted, $image], problem: 'the disk image would not open');
        try {
            $staged = \dirname($bundle).'/.'.\basename($bundle).'.new';
            $this->filesystem->remove($staged);
            try {
                $this->must(['ditto', $mounted.'/Porta.app', $staged], problem: 'the new application could not be copied');
                $this->must(['codesign', '--verify', '--deep', '--strict', $staged], problem: 'the new application is not intact');
                $this->swap($staged, $bundle);
            } finally {
                $this->filesystem->remove($staged);
            }
        } finally {
            ($this->run)(['hdiutil', 'detach', '-quiet', $mounted]);
        }
    }

    private function replaceFolder(string $tarball, string $folder, string $scratch): void
    {
        $this->must(['tar', '-xzf', $tarball, '-C', $scratch], problem: 'the package would not unpack');
        $unpacked = $scratch.'/lib/porta-desktop';
        if (!\is_file($unpacked.'/runtime/porta.phar')) {
            throw new \RuntimeException('the package does not hold the application; nothing was changed.');
        }
        $staged = \dirname($folder).'/.'.\basename($folder).'.new';
        $this->filesystem->remove($staged);
        $this->filesystem->mirror($unpacked, $staged);
        $this->swap($staged, $folder);
    }

    /** $staged takes $target's place; a failure puts $target back. */
    private function swap(string $staged, string $target): void
    {
        $old = \dirname($target).'/.'.\basename($target).'.old';
        $this->filesystem->remove($old);
        $this->filesystem->rename($target, $old);
        try {
            $this->filesystem->rename($staged, $target);
        } catch (\Throwable $e) {
            $this->filesystem->rename($old, $target);

            throw new \RuntimeException($target.' could not be replaced; nothing was changed.', previous: $e);
        }
        $this->filesystem->remove($old);
    }

    /** @param list<string> $command */
    private function must(array $command, string $problem): void
    {
        [$code] = ($this->run)($command);
        if (0 !== $code) {
            throw new \RuntimeException($problem.'; nothing was changed.');
        }
    }

    /** @param list<string> $command */
    private function runOrExplain(array $command, string $byHand): void
    {
        [$code] = ($this->run)($command);
        if (0 !== $code) {
            throw new \RuntimeException(\sprintf('the update did not install (exit %d). Install it yourself: %s', $code, $byHand));
        }
    }
}
