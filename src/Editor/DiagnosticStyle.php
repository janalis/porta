<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Ui\Palette;
use Porta\Ui\Role;

/**
 * Severity → styling for diagnostics, shared by the gutter, the in-editor
 * squiggle underlines, the inline end-of-line message and the right-margin error
 * stripe so they all read consistently. LSP severities: 1=error, 2=warning,
 * 3=info, 4=hint; anything else is treated as a hint. The concrete colors live
 * in the {@see Palette}; this maps severity to the semantic {@see Role}.
 */
final class DiagnosticStyle
{
    /** The severity marker drawn in the right stripe — a plain block, colored per severity. */
    public const string MARK = '▉';

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
     * Open a curly, severity-colored underline. Terminals that support neither
     * escape degrade gracefully: `4:3` falls back to a plain underline and the
     * `58;5` underline color is simply ignored (the text's own color is used).
     */
    public static function underlineOpen(int $severity, Palette $palette): string
    {
        $role = match (self::normalize($severity)) {
            1 => Role::DiagErrorUnderline,
            2 => Role::DiagWarnUnderline,
            3 => Role::DiagInfoUnderline,
            default => Role::DiagHintUnderline,
        };

        return "\x1b[4:3m".$palette->of($role);
    }

    /** Close an underline opened by {@see underlineOpen}: reset its color and style. */
    public static function underlineClose(): string
    {
        return "\x1b[59m\x1b[24m";
    }
}
