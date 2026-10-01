<?php

declare(strict_types=1);

namespace Porta\Filesystem;

/**
 * What a server says about a file without sending it: its size and when it
 * last changed, in whole seconds — the resolution every transport can give
 * (FTP's MDTM is seconds, and `find -printf %T@` is truncated to match).
 *
 * Two stamps that differ mean the file changed; two that agree are trusted to
 * mean it did not, which is the same bet the local reconciler makes with
 * `filemtime()` and `filesize()`.
 */
final readonly class RemoteStamp
{
    public function __construct(
        public int $size,
        public int $mtime,
    ) {}

    public function equals(self $other): bool
    {
        return $this->size === $other->size && $this->mtime === $other->mtime;
    }
}
