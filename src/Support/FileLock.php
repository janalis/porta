<?php

declare(strict_types=1);

namespace Porta\Support;

/** A nonblocking lease over one installation's mutations, shared across processes. */
final class FileLock
{
    /** @var resource|null */
    private $handle;

    public function __construct(
        private readonly string $path,
    ) {}

    public function acquire(): bool
    {
        $eligible =
            null === $this->handle
            && !\is_link($this->path)
            && \is_dir(\dirname($this->path))
            && \is_writable(\dirname($this->path))
            && (!\file_exists($this->path) || (\is_file($this->path) && \is_writable($this->path)));
        $handle = $eligible ? \fopen($this->path, mode: 'cb') : false;
        $locked = \is_resource($handle) && \flock($handle, \LOCK_EX | \LOCK_NB);
        if ($locked) {
            $this->handle = $handle;
        }
        if (!$locked && \is_resource($handle)) {
            \fclose($handle);
        }

        return $locked;
    }

    public function release(): void
    {
        if (\is_resource($this->handle)) {
            \flock($this->handle, \LOCK_UN);
            \fclose($this->handle);
            $this->handle = null;
        }
    }
}
