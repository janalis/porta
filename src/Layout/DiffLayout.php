<?php

declare(strict_types=1);

namespace Porta\Layout;

/** Whether the git diff pane renders side-by-side or as a single unified column. */
enum DiffLayout
{
    case Split;
    case Unified;
}
