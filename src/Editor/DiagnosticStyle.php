<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Lsp\DiagnosticTag;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Sgr;
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
            1 => Glyphs::SEVERITY_ERROR,
            2 => Glyphs::SEVERITY_WARNING,
            3 => Glyphs::SEVERITY_INFO,
            default => Glyphs::SEVERITY_HINT,
        };
    }

    /**
     * The severity marker drawn in the right stripe: block density mirrors
     * severity so the stripe stays readable without color.
     */
    public static function mark(int $severity): string
    {
        return match (self::normalize($severity)) {
            1 => Glyphs::STRIPE_ERROR,
            2 => Glyphs::STRIPE_WARNING,
            3 => Glyphs::STRIPE_INFO,
            default => Glyphs::STRIPE_HINT,
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

    /**
     * What a diagnostic's TAGS do to the text they cover: unused code goes
     * faint, deprecated code is struck through.
     *
     * Severity cannot say either. phpactor reports an unused import at
     * severity 2 — the same severity as a warning about code that runs — so
     * without this the two read identically and the only way to tell them
     * apart is to read the message. Faint and struck-through are what a
     * terminal has instead of PhpStorm's grey and its line.
     *
     * Unknown tags are skipped rather than refused: a server may send one from
     * a newer spec than this, and an unrecognised tag means "draw it normally".
     *
     * The close cancels intensity, which also cancels BOLD — the same trade the
     * bracket-match span makes. It matters only for a bold token that is inside
     * a tagged range and whose bold continues past it, which a symbol name
     * followed by a semicolon is not.
     *
     * @param list<int> $tags LSP DiagnosticTag values
     */
    public static function tagOpen(array $tags): string
    {
        // Collected rather than concatenated: appending to a string that is
        // still empty reads the same as assigning to it, so the first branch
        // would have no way to prove it appends — and appending is the whole
        // contract when a symbol carries both tags.
        $open = [];
        if (\in_array(DiagnosticTag::Unnecessary->value, $tags, strict: true)) {
            $open[] = Sgr::FAINT;
        }
        if (\in_array(DiagnosticTag::Deprecated->value, $tags, strict: true)) {
            $open[] = Sgr::STRIKETHROUGH;
        }

        return implode('', $open);
    }

    /**
     * Cancel exactly what {@see tagOpen} set, in the reverse order — a span
     * that closes an attribute it never opened bleeds it off the rest of the
     * line.
     *
     * @param list<int> $tags
     */
    public static function tagClose(array $tags): string
    {
        $close = [];
        if (\in_array(DiagnosticTag::Deprecated->value, $tags, strict: true)) {
            $close[] = Sgr::NO_STRIKETHROUGH;
        }
        if (\in_array(DiagnosticTag::Unnecessary->value, $tags, strict: true)) {
            $close[] = Sgr::NORMAL_INTENSITY;
        }

        return implode('', $close);
    }
}
