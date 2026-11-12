<?php

declare(strict_types=1);

namespace Porta\Editor;

/** Disk reconciliation retains the saved baseline; recovery awaits an explicit save. */
enum ReloadMode
{
    case Disk;
    case Recovery;
}
