<?php

declare(strict_types=1);

namespace Porta\Config;

/** Whether a command is listed in the Find Action palette. */
enum PaletteVisibility
{
    case Listed;
    case Hidden;
}
