<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Support\AnsiText;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Style\Style;

/**
 * A strip's rows filled out to the pane's width: a roomy strip's baseline runs
 * on as a `─` past the last pill, its other rows pad with blanks on nothing —
 * the pills sit on the pane, as a tree's rows do; a compact strip's one row
 * pads over the toolbar shade it has always had. The dock pads its own, since
 * its tail is its resize grab and its tool's status.
 */
final class TabStripTail
{
    /**
     * @param array{lines: list<string>, footRow: ?int, used: int, ...} $strip
     *
     * @return list<string>
     */
    public static function pad(array $strip, int $columns, Styles $styles): array
    {
        $base = null === $strip['footRow'] ? $styles->of(Role::Toolbar) : new Style();
        $rows = [];
        foreach ($strip['lines'] as $row => $line) {
            $fill = $row === $strip['footRow'] ? Rule::horizontal($columns - $strip['used'], $styles) : '';
            $rows[] = $base->apply(AnsiText::padRight($line.$fill, $columns));
        }

        return $rows;
    }
}
