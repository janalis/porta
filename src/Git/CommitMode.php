<?php

declare(strict_types=1);

namespace Porta\Git;

/** Whether a commit starts fresh or amends (rewrites) the current HEAD. */
enum CommitMode
{
    case Fresh;
    case Amend;
}
