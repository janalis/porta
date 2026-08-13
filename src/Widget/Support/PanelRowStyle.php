<?php

declare(strict_types=1);

namespace Porta\Widget\Support;

use Porta\Ui\Role;
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
 */
trait PanelRowStyle
{
    use StylesDefault;

    /** Shade a built row: the selection rule, else the hover tint, else the row as built. */
    private function shadeRow(string $text, bool $selected, bool $hovered): string
    {
        return match (true) {
            $selected => $this->applyElement('selected', $text),
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
}
