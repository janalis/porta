<?php

declare(strict_types=1);

namespace Porta\Container;

/** Whether language-server support is available or switched off for the boot. */
enum LspMode
{
    case Enabled;
    case Disabled;
}
