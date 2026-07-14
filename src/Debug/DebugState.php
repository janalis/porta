<?php

declare(strict_types=1);

namespace Porta\Debug;

/** The debug session lifecycle, as shown in the panel header and status bar. */
enum DebugState
{
    case Idle;
    case Starting;
    case Running;
    case Paused;
    case Terminated;
}
