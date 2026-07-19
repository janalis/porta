<?php

declare(strict_types=1);

namespace Porta\Ui;

use Symfony\Component\Tui\Style\Style;

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

    /** Faint/dim intensity — the muted pen for inline hints like the Esc affordance. */
    public const string FAINT = "\x1b[2m";

    /** Cancel bold/faint intensity (back to normal weight). */
    public const string NORMAL_INTENSITY = "\x1b[22m";

    public const string REVERSE = "\x1b[7m";

    /** Cancel reverse video (leaving other attributes intact). */
    public const string NO_REVERSE = "\x1b[27m";

    public const string UNDERLINE = "\x1b[4m";

    /** Cancel underline (leaving other attributes intact). */
    public const string NO_UNDERLINE = "\x1b[24m";

    /**
     * The attribute-specific close that matches $style's own open
     * ({@see Style::getAnsiRestore()}): reset exactly what it set, so a span
     * never bleeds an attribute past its end. For a background-color style this
     * is {@see DEFAULT_BG} — identical to the hardcoded close the bg-color roles
     * always used — so colored themes are byte-unchanged; for the Monochrome
     * reverse/underline mappings it cancels those instead.
     */
    public static function resetFor(Style $style): string
    {
        $reset = '';
        if (null !== $style->getBackground()) {
            $reset .= self::DEFAULT_BG;
        }
        if (true === $style->getReverse()) {
            $reset .= self::NO_REVERSE;
        }
        if (true === $style->getUnderline()) {
            $reset .= self::NO_UNDERLINE;
        }
        if (true === $style->getBold()) {
            $reset .= self::NORMAL_INTENSITY;
        }

        return $reset;
    }

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
