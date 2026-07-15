<?php

declare(strict_types=1);

namespace Porta\Config;

/** Whether a command is dispatched from a key binding by the input listener. */
enum KeyDispatch
{
    case Bound;
    case Unbound;
}
