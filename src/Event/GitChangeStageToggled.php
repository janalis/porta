<?php

declare(strict_types=1);

namespace Porta\Event;

use Porta\Git\ChangeEntry;

/** An entry's checkbox was toggled: stage it, or unstage it. */
final readonly class GitChangeStageToggled
{
    public function __construct(
        public ChangeEntry $entry,
    ) {}
}
