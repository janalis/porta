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

    /**
     * The dark-grey row-highlight background shared by popup/menu rows, hovered
     * rows and the current editor line. Callers append their own foreground.
     */
    public const string POPUP_ROW_BG = "\x1b[48;5;236m";

    /** Popup/menu row at rest: the shared background with a light grey foreground. */
    public const string POPUP_ROW = self::POPUP_ROW_BG."\x1b[38;5;250m";
}
