<?php

declare(strict_types=1);

namespace Porta\Editor;

/** Whether the caret moved this render (snap the view to it) or is parked. */
enum CaretMotion
{
    case Moved;
    case Parked;
}
