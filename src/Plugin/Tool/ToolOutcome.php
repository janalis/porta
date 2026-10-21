<?php

declare(strict_types=1);

namespace Porta\Plugin\Tool;

/** How an install or update of one tool ended. */
enum ToolOutcome
{
    case Installed;

    /** Replaced by a newer version. */
    case Updated;

    /** Asked to update, and already the newest: nothing was downloaded. */
    case UpToDate;

    case Failed;
}
