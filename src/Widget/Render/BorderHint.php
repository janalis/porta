<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Style\Style;

/**
 * The one place a hint is set into a bottom border: `╰──── Alt+9 log ─╯` — a
 * field's trailing hint, a clipped modal list's `3/40`, a toast's key to the
 * log. Faint, like the title bar's `Esc`, with a stroke on each side of it so
 * it reads as part of the border; a hint that cannot keep both strokes is left
 * out whole, since a cut hint names half a key.
 */
final class BorderHint
{
    /**
     * The bottom border: $left, $run columns of $rule with the hint set near
     * the right end, then $corner — already styled, as a resizable modal's grab
     * is not the border's face. Exactly $run + 2 columns.
     */
    public static function bottom(string $left, string $corner, string $rule, int $run, Style $border, string $hint, Styles $styles): string
    {
        $run = \max(0, $run);
        $hintWidth = AnsiUtils::visibleWidth($hint);
        if ('' === $hint || $hintWidth > ($run - 4)) {
            return $border->apply($left.\str_repeat($rule, $run)).$corner;
        }

        $before = \max(1, $run - 3 - $hintWidth); // the guard above proves this, max() proves it to the analyzer

        return $border->apply($left.\str_repeat($rule, $before)).' '.$styles->of(Role::Faint)->apply($hint).' '.$border->apply($rule).$corner;
    }
}
