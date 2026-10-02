<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Support\AnsiText;
use Porta\Ui\Glyphs;

/**
 * A tree row's text before it is shaded: the selection bar's column, the
 * indent, the disclosure triangle — or its two blank columns on a leaf, so a
 * leaf's name lines up with a folder's — and the name.
 *
 * Shared by the file tree and the settings sidebar, which groups its pages
 * into folders the same way: one shape for "a folder you can open", spelled
 * once so the two cannot drift apart.
 */
final class TreeRow
{
    /** @param ?bool $expanded whether the folder is open; null for a leaf */
    public static function text(SelectionBar $bar, int $depth, ?bool $expanded, string $name): string
    {
        $disclosure = match ($expanded) {
            null => '  ',
            true => Glyphs::EXPANDED.' ',
            false => Glyphs::COLLAPSED.' ',
        };

        return $bar->value.AnsiText::blank($depth).$disclosure.$name;
    }
}
