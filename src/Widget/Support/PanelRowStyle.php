<?php

declare(strict_types=1);

namespace Porta\Widget\Support;

use Porta\Support\AnsiText;
use Porta\Ui\Role;
use Porta\Ui\Sgr;
use Porta\Widget\Render\SelectionBar;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Style\Style;

/**
 * The shading of a row in a focusable list panel — the panel counterpart of
 * {@see \Porta\Widget\Render\ModalRowStyle}: the theme's selection rule
 * (`.list::selected(:focus)`) wins, then the hover tint, then the panel's
 * resting shade. Two methods because the panels consume it two ways: the
 * settings and changes lists shade a finished row string, while the results
 * and debug panels thread the resolved {@see Style} through their span
 * builders.
 *
 * @require-extends \Symfony\Component\Tui\Widget\AbstractWidget
 * @require-implements \Symfony\Component\Tui\Widget\FocusableInterface
 */
trait PanelRowStyle
{
    use StylesDefault;

    /**
     * A built row with the {@see SelectionBar} in its first column — the space
     * every panel row starts with, so nothing after it moves. Before the
     * shading: the bar is a bare glyph the row's style then paints.
     */
    private function barred(string $text, bool $selected, bool $marked = false): string
    {
        return SelectionBar::of($selected, $this->isFocused(), $marked)->value.AnsiText::columnsFrom($text, 1, AnsiUtils::visibleWidth($text));
    }

    /**
     * Shade a built row: the selection rule, else the hover tint, else the row
     * as built.
     *
     * The selection's foreground is re-armed after every inner default-colour
     * reset. {@see Style::apply()} re-arms only a background, so a span tinted
     * inside the row — a field's rail, a dimmed detail — used to hand what
     * followed it the terminal's default colour instead of the selection's:
     * the value after a text field's rail lost its bright-on-blue.
     */
    private function shadeRow(string $text, bool $selected, bool $hovered): string
    {
        return match (true) {
            $selected => self::withForeground($this->resolveElement('selected'), $text),
            $hovered => $this->styles->of(Role::RowHighlightBg)->apply($text),
            default => $text,
        };
    }

    /** The row's resolved Style, for panels that build spans; the resting shade is the toolbar's. */
    private function rowStyle(bool $selected, bool $hovered): Style
    {
        return match (true) {
            $selected => $this->resolveElement('selected'),
            $hovered => $this->styles->of(Role::RowHighlightBg),
            default => $this->styles->of(Role::Toolbar),
        };
    }

    private static function withForeground(Style $style, string $text): string
    {
        $foreground = $style->getColor()?->toForegroundCode();

        return $style->apply(null === $foreground ? $text : \str_replace(Sgr::DEFAULT_FG, Sgr::DEFAULT_FG.$foreground, $text));
    }
}
