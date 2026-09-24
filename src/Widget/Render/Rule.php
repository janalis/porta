<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Support\AnsiText;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The one place a separating line is drawn: `──────`, or a group's legend set
 * into it, `─ Editor ──────`. The run takes {@see Role::Rule} wherever it
 * runs — a modal's section tee, a settings group, a pane's baseline — so a
 * line reads as the same line on every surface; the legend is Bright.
 *
 * With $tees the ends are `├` `┤` in that role, joining the rule to the rails
 * of the frame it cuts across. The legend yields before the tees do: a tee cut
 * off leaves the frame open. Every row is exactly $width visible columns.
 */
final class Rule
{
    /** `─ ` before the legend and ` ` after it. */
    private const int LEGEND_CHROME = 3;

    public static function horizontal(int $width, Styles $styles, string $legend = '', ?Role $tees = null): string
    {
        $width = \max(0, $width);
        if (null === $tees || $width < 2) {
            return self::run($width, $styles, $legend);
        }
        $tee = $styles->of($tees);

        return $tee->apply(Glyphs::MODAL_TEE_LEFT).self::run($width - 2, $styles, $legend).$tee->apply(Glyphs::MODAL_TEE_RIGHT);
    }

    /** Exactly $width columns of rule, the legend after its first stroke while one more stroke still fits. */
    private static function run(int $width, Styles $styles, string $legend): string
    {
        $rule = $styles->of(Role::Rule);
        $text = '' === $legend ? '' : AnsiText::truncate($legend, \max(0, $width - self::LEGEND_CHROME - 1));
        if ('' === $text) {
            return $rule->apply(\str_repeat(Glyphs::FRAME_HORIZONTAL, \max(0, $width)));
        }

        return (
            $rule->apply(Glyphs::FRAME_HORIZONTAL.' ')
            .$styles->of(Role::Bright)->apply($text)
            .' '
            .$rule->apply(\str_repeat(Glyphs::FRAME_HORIZONTAL, \max(0, $width - self::LEGEND_CHROME - AnsiUtils::visibleWidth($text))))
        );
    }
}
