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
 * video (dimmed while its pane is unfocused).
 */
final class MonochromeTheme
{
    public static function definition(): ThemeDefinition
    {
        return new ThemeDefinition(
            rules: [
                Role::SelectedRow->selector() => new Style(reverse: true),
                Role::SelectedRowUnfocused->selector() => new Style(dim: true, reverse: true),
            ],
            underlines: [],
            hasColor: false,
        );
    }
}
