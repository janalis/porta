<?php

declare(strict_types=1);

namespace Porta\Editor;

/** Which side of the caret's line a newly opened line goes on. */
enum LinePlacement
{
    case Above;
    case Below;
}
