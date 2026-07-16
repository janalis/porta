<?php

declare(strict_types=1);

namespace Porta\Ui;

/**
 * Named SGR (Select Graphic Rendition) escape sequences — the handful of raw
 * `\x1b[…m` codes that recur across the renderer. Naming them here keeps the
 * intent legible at the call site and gives the "reset the pen" decision one
 * home, the same rationale the theme classes state for their color rules.
 *
 * Color roles do NOT belong here — those are theme-dependent and live in the
 * palette; this is only the theme-agnostic attribute vocabulary.
 */
final class Sgr
{
    /** Reset every attribute (color + bold/reverse/underline) to the default. */
    public const string RESET = "\x1b[0m";

    /** Reset the foreground color only, leaving other attributes intact. */
    public const string DEFAULT_FG = "\x1b[39m";

    /** Reset the background color only. */
    public const string DEFAULT_BG = "\x1b[49m";

    public const string BOLD = "\x1b[1m";

    /** Cancel bold/faint intensity (back to normal weight). */
    public const string NORMAL_INTENSITY = "\x1b[22m";

    public const string REVERSE = "\x1b[7m";

    public const string UNDERLINE = "\x1b[4m";

    /**
     * The SGR sequence that sets the underline color to xterm-256 index $index
     * (`\x1b[58;5;<n>m`) — distinct from the foreground/background codes and not
     * exposed by symfony/tui's Color.
     */
    public static function underlineColor(int $index): string
    {
        return "\x1b[58;5;{$index}m";
    }
}
