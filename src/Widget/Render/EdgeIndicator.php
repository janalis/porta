<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Support\AnsiText;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;

/**
 * The centred, faint ▲/▼ row a scrolling column list reserves at an edge it
 * has clipped — the vertical tab strip and the settings nav — and the target
 * a click on it scrolls by. Truncated, never padded past $width: a pane may be
 * narrower than the glyph's centring asks for.
 */
final class EdgeIndicator
{
    public static function row(string $glyph, int $width, Styles $styles): string
    {
        $left = \str_repeat(' ', times: \max(0, \intdiv($width, num2: 2) - 1));

        return $styles->of(Role::Faint)->apply(AnsiText::truncate($left.$glyph, $width, pad: true, ellipsis: ''));
    }
}
