<?php

declare(strict_types=1);

namespace Porta\Filesystem;

use Symfony\Component\Filesystem\Filesystem;

/**
 * A project on this machine: everything is already here, and every verb is
 * Symfony's.
 */
final readonly class LocalFilesystem implements ProjectFilesystem
{
    public function __construct(
        private Filesystem $fs = new Filesystem(),
    ) {}

    #[\Override]
    public function available(string $path, \Closure $then, ?\Closure $failed = null): bool
    {
        return true;
    }

    #[\Override]
    public function listed(string $dir): bool
    {
        return true;
    }

    #[\Override]
    public function dumpFile(string $path, string $content): void
    {
        $this->fs->dumpFile($path, $content);
    }

    #[\Override]
    public function mkdir(string $path): void
    {
        $this->fs->mkdir($path);
    }

    #[\Override]
    public function rename(string $from, string $to, bool $overwrite = false): void
    {
        $this->fs->rename($from, $to, $overwrite);
    }

    #[\Override]
    public function remove(string $path): void
    {
        $this->fs->remove($path);
    }

    /**
     * `overwriteNewerFiles` for a file, because the caller has already decided
     * the target may go: a target NEWER than its source is exactly the one
     * that must still be replaced.
     */
    #[\Override]
    public function copy(string $source, string $target): void
    {
        \is_dir($source) ? $this->fs->mirror($source, $target) : $this->fs->copy($source, $target, overwriteNewerFiles: true);
    }

    #[\Override]
    public function pending(): int
    {
        return 0;
    }
}
