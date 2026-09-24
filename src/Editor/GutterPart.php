<?php

declare(strict_types=1);

namespace Porta\Editor;

/** Which of the gutter's clickable columns a mark sits in. */
enum GutterPart
{
    /** The version-control change strip, the gutter's first column. */
    case Change;
    /** The marker column beside the number: breakpoint, bookmark, diagnostic. */
    case Marker;
}
