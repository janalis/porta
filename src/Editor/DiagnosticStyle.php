<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Lsp\DiagnosticTag;
use Porta\Ui\Role;
use Porta\Ui\Sgr;
use Porta\Ui\Theme\Styles;

/**
 * Severity → styling for diagnostics, shared by the gutter, the in-editor
 * squiggle underlines, the inline end-of-line message and the right-margin error
 * stripe so they all read consistently. The per-severity glyphs and roles live
 * on {@see DiagnosticSeverity}; what is left here combines severity with a
 * palette ({@see Styles}) or with diagnostic tags.
 */
final class DiagnosticStyle
{
    /**
     * The worst severity present; a hint when the list is empty. A diagnostic
     * carrying no severity counts as an error, the LSP default.
     *
     * @param list<array{severity?: DiagnosticSeverity, ...}> $diags
     */
    public static function worst(array $diags): DiagnosticSeverity
    {
        $worst = DiagnosticSeverity::Hint;
        foreach ($diags as $diag) {
            $worst = $worst->worse($diag['severity'] ?? DiagnosticSeverity::Error);
        }

        return $worst;
    }

    /**
     * Open a severity-colored underline whose style also encodes the severity
     * (curly/dashed/dotted/straight), so severities stay distinguishable without
     * color. Terminals that support neither escape degrade gracefully: `4:n`
     * falls back to a plain underline and the `58;5` underline color is simply
     * ignored (the text's own color is used).
     */
    public static function underlineOpen(DiagnosticSeverity $severity, Styles $styles): string
    {
        [$style, $role] = match ($severity) {
            DiagnosticSeverity::Error => ['4:3', Role::DiagErrorUnderline],
            DiagnosticSeverity::Warning => ['4:5', Role::DiagWarnUnderline],
            DiagnosticSeverity::Information => ['4:4', Role::DiagInfoUnderline],
            DiagnosticSeverity::Hint => ['4:1', Role::DiagHintUnderline],
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

        return \implode('', $open);
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

        return \implode('', $close);
    }
}
