<?php

declare(strict_types=1);

namespace Porta\Ui;

/** The bottom dock's tool tabs, in their fixed strip order. */
enum DockTab
{
    case Terminal;
    case Results;
    case Debug;
}
