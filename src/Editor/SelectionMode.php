<?php

declare(strict_types=1);

namespace Porta\Editor;

/** Whether moving the caret keeps the current selection (extends it) or clears it. */
enum SelectionMode
{
    case Keep;
    case Clear;
}
