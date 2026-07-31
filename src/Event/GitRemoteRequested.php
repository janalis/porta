<?php

declare(strict_types=1);

namespace Porta\Event;

use Porta\Git\GitRemoteOperation;

/** A git pane asked for fetch, pull or push. */
final readonly class GitRemoteRequested
{
    public function __construct(
        public GitRemoteOperation $operation,
    ) {}
}
