<?php

declare(strict_types=1);

namespace Porta\Filesystem;

/** One name in a server directory's listing, as {@see RemoteTransport::list()} answers it. */
final readonly class RemoteEntry
{
    public function __construct(
        public string $name,
        public bool $isDir,
        public RemoteStamp $stamp = new RemoteStamp(0, 0),
    ) {}
}
