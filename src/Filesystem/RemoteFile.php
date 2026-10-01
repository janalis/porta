<?php

declare(strict_types=1);

namespace Porta\Filesystem;

/** A file's content as fetched, with the stamp it had when it was read. */
final readonly class RemoteFile
{
    public function __construct(
        public string $bytes,
        public RemoteStamp $stamp,
    ) {}
}
