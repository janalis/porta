<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Filesystem\LocalFilesystem;
use Porta\Filesystem\ProjectFilesystem;

/**
 * A project's files as a remote one would be asked: every verb is recorded
 * — `write a.php`, `rename a.php b.php`, `remove b.php`, `available a.php` —
 * and done on the local disk, so a test can see that a change went through
 * the PROJECT's files (what reaches a server) rather than straight to disk.
 * {@see $notFetched} names files whose content is not here yet, as a remote
 * placeholder's is not: available() answers false for them, and writing one
 * fails the test.
 */
final class RecordingProjectFiles implements ProjectFilesystem
{
    /** @var list<string> */
    public array $did = [];

    /** @var list<string> basenames whose content is not here yet */
    public array $notFetched = [];

    private readonly LocalFilesystem $local;

    public function __construct()
    {
        $this->local = new LocalFilesystem();
    }

    #[\Override]
    public function available(string $path, \Closure $then, ?\Closure $failed = null): bool
    {
        $this->did[] = 'available '.\basename($path);

        return !\in_array(\basename($path), $this->notFetched, strict: true);
    }

    #[\Override]
    public function listed(string $dir): bool
    {
        return true;
    }

    #[\Override]
    public function dumpFile(string $path, string $content): void
    {
        if (\in_array(\basename($path), $this->notFetched, strict: true)) {
            throw new \LogicException(\basename($path).' written from its placeholder');
        }
        $this->did[] = 'write '.\basename($path);
        $this->local->dumpFile($path, $content);
    }

    #[\Override]
    public function mkdir(string $path): void
    {
        $this->local->mkdir($path);
    }

    #[\Override]
    public function rename(string $from, string $to, bool $overwrite = false): void
    {
        $this->did[] = 'rename '.\basename($from).' '.\basename($to);
        $this->local->rename($from, $to, $overwrite);
    }

    #[\Override]
    public function remove(string $path): void
    {
        $this->did[] = 'remove '.\basename($path);
        $this->local->remove($path);
    }

    #[\Override]
    public function copy(string $source, string $target): void
    {
        $this->local->copy($source, $target);
    }

    #[\Override]
    public function pending(): int
    {
        return 0;
    }
}
