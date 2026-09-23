<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Ui\Theme\DarkTheme;
use Porta\Ui\Theme\StyleSheetThemer;
use Porta\Ui\Theme\ThemeDefinition;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\TailwindStylesheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Attaches a widget to a throwaway Tui whose renderer stylesheet carries the
 * dark theme, so `::element` rules (e.g. the `.list::selected` selection
 * shade) resolve in unit tests exactly as in the booted IDE — a detached
 * widget would fall back to the vendor DefaultStyleSheet and render its
 * selection unstyled. Another theme — the monochrome one, to prove a state
 * reads without colour — is the second argument.
 */
final class AttachedStyles
{
    public static function attach(AbstractWidget $widget, ?ThemeDefinition $theme = null): void
    {
        $renderer = new Renderer(new TailwindStylesheet());
        new StyleSheetThemer($renderer)->apply($theme ?? DarkTheme::definition());
        $tui = new Tui(terminal: new VirtualTerminal(columns: 120, rows: 40), keybindings: new Keybindings([]), renderer: $renderer);
        $tui->add($widget);
    }
}
