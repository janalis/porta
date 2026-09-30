<?php

declare(strict_types=1);

namespace Porta\Editor;

/** Where focus lands after a file opens: the editor area, or wherever it was. */
enum OpenFocus
{
    case Editor;
    case Keep;

    /**
     * Not even the TAB comes forward: the file joins the tab bar behind the
     * one the user is looking at — what an agent's edit to a file the user is
     * not on wants. Text files only; a file a viewer claims, or one too large
     * to edit, is left unopened. With nothing active yet, it opens as Keep.
     */
    case Background;
}
