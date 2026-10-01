<?php

declare(strict_types=1);

namespace Porta\Filesystem;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * The open project's filesystem: the server's, through its cache, when the
 * project is remote, and this machine's otherwise. Decided once, at boot —
 * a project does not move.
 */
#[AsAlias(ProjectFilesystem::class)]
final readonly class ProjectFiles implements ProjectFilesystem
{
    private ProjectFilesystem $files;

    public function __construct(ProjectLocation $location, RemoteFilesystem $remote, LocalFilesystem $local)
    {
        $this->files = $location->isRemote() ? $remote : $local;
    }

    #[\Override]
    public function available(string $path, \Closure $then): bool
    {
        return $this->files->available($path, $then);
    }

    #[\Override]
    public function listed(string $dir): bool
    {
        return $this->files->listed($dir);
    }

    #[\Override]
    public function dumpFile(string $path, string $content): void
    {
        $this->files->dumpFile($path, $content);
    }

    #[\Override]
    public function mkdir(string $path): void
    {
        $this->files->mkdir($path);
    }

    #[\Override]
    public function rename(string $from, string $to, bool $overwrite = false): void
    {
        $this->files->rename($from, $to, $overwrite);
    }

    #[\Override]
    public function remove(string $path): void
    {
        $this->files->remove($path);
    }

    #[\Override]
    public function copy(string $source, string $target): void
    {
        $this->files->copy($source, $target);
    }

    #[\Override]
    public function pending(): int
    {
        return $this->files->pending();
    }
}
