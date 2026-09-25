<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Support\AnsiText;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;

/**
 * A strip's rows filled out to the pane's width over the toolbar shade: folder
 * tabs' baseline runs on as a `─` past the last tab, the lid and label rows
 * and a compact strip pad with blanks. The dock pads its own, since its tail
 * is its resize grab and its tool's status.
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
        $rows = [];
        foreach ($strip['lines'] as $row => $line) {
            $fill = $row === $strip['footRow'] ? Rule::horizontal($columns - $strip['used'], $styles) : '';
            $rows[] = $styles->of(Role::Toolbar)->apply(AnsiText::padRight($line.$fill, $columns));
        }

        return $rows;
    }
}
