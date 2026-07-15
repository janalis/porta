<?php

declare(strict_types=1);

namespace Porta\Editor;

/** Where focus lands after a file opens: the editor area, or wherever it was. */
enum OpenFocus
{
    case Editor;
    case Keep;
}
