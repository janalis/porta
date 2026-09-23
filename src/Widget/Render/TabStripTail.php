<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Support\AnsiText;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;

/**
 * A strip's rows filled out to the pane's width over the toolbar shade: folder
 * tabs stand on a `─` baseline along their label row, the lid row and a
 * compact strip pad with blanks. The dock pads its own, since its tail is its
 * resize grab and its tool's status.
 */
final class TabStripTail
{
    /**
     * @param array{lines: list<string>, labelRow: int, used: int, ...} $strip
     *
     * @return list<string>
     */
    // @mago-expect lint:no-boolean-flag-parameter -- folder or compact IS the tail's shape, not a second responsibility.
    public static function pad(array $strip, int $columns, bool $folder, Styles $styles): array
    {
        $rows = [];
        foreach ($strip['lines'] as $row => $line) {
            $fill = $folder && $row === $strip['labelRow'] ? $styles->of(Role::Placeholder)->apply(\str_repeat(Glyphs::FRAME_HORIZONTAL, \max(0, $columns - $strip['used']))) : '';
            $rows[] = $styles->of(Role::Toolbar)->apply(AnsiText::padRight($line.$fill, $columns));
        }

        return $rows;
    }
}
