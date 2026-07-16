<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Support\AnsiText;
use Porta\Ui\CaretVisibility;
use Porta\Ui\Role;
use Porta\Ui\TextField;
use Porta\Ui\Theme\Styles;

/**
 * Renders a labelled single-line input row for the search surfaces — the
 * find-in-files modal and the results tool window: ` label: <field>  hint`,
 * scrolled to keep the caret visible and filled to $width over the $bg row
 * background (an empty hint contributes nothing). Shared so the two widgets
 * keep one field-row look.
 */
final class SearchFieldRenderer
{
    public static function row(string $label, TextField $field, CaretVisibility $caret, string $hint, int $width, Role $bg, Styles $styles): string
    {
        $line = ' '.$label.': '.$field->render(cursor: $caret);
        if ('' !== $hint) {
            $line .= $styles->of(Role::Dim)->apply($hint);
        }

        return $styles->of($bg)->apply(AnsiText::scrollLineToCaret($line, $width));
    }
}
