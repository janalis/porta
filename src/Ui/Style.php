<?php

declare(strict_types=1);

namespace Porta\Ui;

/**
 * Shared ANSI style sequences for the IDE chrome, so widgets agree on the
 * palette without repeating raw escape codes. Constants only — one-off
 * shades stay at their call site.
 */
final class Style
{
    /** Focused selected row: blue background, near-white foreground. */
    public const string SELECTED_ROW = "\x1b[48;5;31m\x1b[38;5;231m";

    /** Popup/menu row at rest: dark grey background, light grey foreground. */
    public const string POPUP_ROW = "\x1b[48;5;236m\x1b[38;5;250m";
}
