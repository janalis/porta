<?php

declare(strict_types=1);

namespace Porta\Ui\Theme;

use Porta\Ui\Role;
use Symfony\Component\Tui\Style\Style;

/**
 * The `--no-ansi` theme: every role resolves to the plain {@see Style}
 * (whose apply() is the identity), so the IDE renders without any SGR color
 * while text, layout and structural ANSI stay intact. Selection is the one
 * state that must survive without color, so the selected row maps to reverse
 * video (dimmed while its pane is unfocused) — and the active tab of a strip
 * gets the same reverse treatment, or it would be indistinguishable.
 */
final class MonochromeTheme
{
    public static function definition(): ThemeDefinition
    {
        return new ThemeDefinition(
            rules: [
                Role::SelectedRow->selector() => new Style(reverse: true),
                Role::SelectedRowUnfocused->selector() => new Style(dim: true, reverse: true),
                Role::TabActiveBg->selector() => new Style(reverse: true),
                // Selection is reverse; hover is underline — a distinct mark, so
                // the tint-only hover the widgets build still reads with no
                // colour (where these roles would otherwise resolve to plain).
                // Selection wins where a row is both: render precedence puts the
                // reverse-video selection ahead of the underline.
                Role::RowHighlightBg->selector() => new Style(underline: true),
                Role::BarHoverBg->selector() => new Style(underline: true),
                // The :focus variant merges over the base rule, so it must
                // cancel the unfocused dim explicitly.
                '.list::selected' => new Style(dim: true, reverse: true),
                '.list::selected:focus' => new Style(dim: false, reverse: true),
            ],
            underlines: [],
            hasColor: false,
        );
    }
}
