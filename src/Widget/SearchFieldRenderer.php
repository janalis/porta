<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Support\AnsiText;
use Porta\Ui\CaretVisibility;
use Porta\Ui\Palette;
use Porta\Ui\Role;
use Porta\Ui\Sgr;
use Porta\Ui\TextField;

/**
 * Renders a labelled single-line input row for the search surfaces — the
 * find-in-files modal and the results tool window: ` label: <field>  hint`,
 * scrolled to keep the caret visible and filled to $width over the $bg row
 * background (an empty hint contributes nothing). Shared so the two widgets
 * keep one field-row look.
 */
final class SearchFieldRenderer
{
    public static function row(string $label, TextField $field, CaretVisibility $caret, string $hint, int $width, Role $bg, Palette $palette): string
    {
        $line = ' '.$label.': '.$field->render(cursor: $caret);
        if ('' !== $hint) {
            $line .= $palette->of(Role::Dim).$hint.Sgr::DEFAULT_FG;
        }

        return $palette->of($bg).AnsiText::scrollLineToCaret($line, $width).Sgr::RESET;
    }
}
