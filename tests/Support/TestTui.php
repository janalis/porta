<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * The full three-argument Tui rig (virtual terminal + empty keybindings +
 * fresh renderer) the unit suites re-spelled per site. Terminal-only
 * constructions stay inline — they are already one argument.
 */
final class TestTui
{
    public static function create(int $columns = 100, int $rows = 24): Tui
    {
        return new Tui(
            terminal: new VirtualTerminal(columns: $columns, rows: $rows),
            keybindings: new Keybindings([]),
            renderer: new Renderer(new StyleSheet()),
        );
    }
}
