<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;

/**
 * Severity → styling for diagnostics, shared by the gutter, the in-editor
 * squiggle underlines, the inline end-of-line message and the right-margin error
 * stripe so they all read consistently. LSP severities: 1=error, 2=warning,
 * 3=info, 4=hint; anything else is treated as a hint. The concrete colors live
 * in the theme ({@see Styles}); this maps severity to the semantic {@see Role}.
 */
final class DiagnosticStyle
{
    /**
     * The severity glyph shown in the gutter marker column and as the inline
     * message prefix, so severity reads without color (matches the status bar's
     * ✗/▲ problem counts). All glyphs are one display column wide.
     */
    public static function glyph(int $severity): string
    {
        return match (self::normalize($severity)) {
            1 => '✗',
            2 => '▲',
            3 => '◆',
            default => '·',
        };
    }

    /**
     * The severity marker drawn in the right stripe: block density mirrors
     * severity so the stripe stays readable without color.
     */
    public static function mark(int $severity): string
    {
        return match (self::normalize($severity)) {
            1 => '█',
            2 => '▓',
            3 => '▒',
            default => '░',
        };
    }

    /**
     * The worst (lowest-numbered) severity present; 4 (hint) when the list is empty.
     *
     * @param list<array{severity?: int, ...}> $diags
     */
    public static function worst(array $diags): int
    {
        $worst = 4;
        foreach ($diags as $diag) {
            $worst = min($worst, self::normalize($diag['severity'] ?? 1));
        }

        return $worst;
    }

    /** Clamp any severity int to the known 1..4 range (unknown → hint). */
    public static function normalize(int $severity): int
    {
        return $severity >= 1 && $severity <= 4 ? $severity : 4;
    }

    /** The foreground role for a severity (gutter line number, stripe mark). */
    public static function role(int $severity): Role
    {
        return match (self::normalize($severity)) {
            1 => Role::DiagError,
            2 => Role::DiagWarn,
            3 => Role::DiagInfo,
            default => Role::DiagHint,
        };
    }

    /** The dimmer foreground role for a severity (inline end-of-line message). */
    public static function dimRole(int $severity): Role
    {
        return match (self::normalize($severity)) {
            1 => Role::DiagErrorDim,
            2 => Role::DiagWarnDim,
            3 => Role::DiagInfoDim,
            default => Role::DiagHintDim,
        };
    }

    /**
     * Open a severity-colored underline whose style also encodes the severity
     * (curly/dashed/dotted/straight), so severities stay distinguishable without
     * color. Terminals that support neither escape degrade gracefully: `4:n`
     * falls back to a plain underline and the `58;5` underline color is simply
     * ignored (the text's own color is used).
     */
    public static function underlineOpen(int $severity, Styles $styles): string
    {
        [$style, $role] = match (self::normalize($severity)) {
            1 => ['4:3', Role::DiagErrorUnderline],
            2 => ['4:5', Role::DiagWarnUnderline],
            3 => ['4:4', Role::DiagInfoUnderline],
            default => ['4:1', Role::DiagHintUnderline],
        };

        return "\x1b[{$style}m".$styles->underline($role);
    }

    /** Close an underline opened by {@see underlineOpen}: reset its color and style. */
    public static function underlineClose(): string
    {
        return "\x1b[59m\x1b[24m";
    }
}
