<?php

declare(strict_types=1);

namespace Porta\Ui;

/**
 * How much chrome the terminal can afford, decided in one place from its
 * height: Roomy gives the tab strips their folder tabs and every form control
 * its box, Compact takes them back to one row each — below
 * {@see COMPACT_BELOW} rows, every row given to chrome is a line of code lost.
 *
 * The terminal's rows, never a pane's: a pane's height depends on where the
 * user dragged the dock, and two surfaces on one screen that disagree on the
 * rule read as a bug. {@see ScreenDensity} carries it to the widgets.
 */
enum Density
{
    case Roomy;
    case Compact;

    /** Below this many terminal rows, the chrome gives its rows back. */
    public const int COMPACT_BELOW = 30;

    public static function of(int $terminalRows): self
    {
        return $terminalRows >= self::COMPACT_BELOW ? self::Roomy : self::Compact;
    }
}
