<?php

declare(strict_types=1);

namespace Porta\Editor;

/** Whether the find bar opens as plain find or find-and-replace. */
enum FindMode
{
    case Find;
    case Replace;
}
