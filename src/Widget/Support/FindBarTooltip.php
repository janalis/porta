<?php

declare(strict_types=1);

namespace Porta\Widget\Support;

use Porta\I18n\Translator;
use Porta\Ui\ClickZones;
use Porta\Ui\ShortcutFormatter;
use Porta\Ui\Tooltip;
use Symfony\Component\Tui\Input\Keybindings;

/**
 * What the find bar's toggles and buttons say under the pointer: what each
 * does and the key that does it too — `.*`, `\b`, `Aa` and `∀` are glyphs,
 * and a glyph is a guess. The words are the find bar's own: the editor's
 * `toggle_case` action means something else entirely.
 *
 * Out of the widget because the widget sits on the control-flow ceiling
 * (`kan-defect`), and a table of eight answers is not the widget's logic.
 */
final class FindBarTooltip
{
    /**
     * The tooltip of the control named $name, over the $rows rows from $top
     * — null for no control, or one the last render did not draw.
     */
    public static function of(?string $name, ClickZones $zones, int $top, int $rows, Keybindings $keys, Translator $translator): ?Tooltip
    {
        $span = null === $name ? null : $zones->span($name);
        if (null === $name || null === $span) {
            return null;
        }
        [$text, $action] = match ($name) {
            'regex' => [$translator->trans('Regular expression'), 'toggle_regex'],
            'word' => [$translator->trans('Whole word'), 'toggle_word'],
            'case' => [$translator->trans('Match case'), 'toggle_case'],
            'prev' => [$translator->trans('Previous match'), 'find_prev'],
            'next' => [$translator->trans('Next match'), 'find_next'],
            'select_all_matches' => [$translator->trans('Select all matches'), 'select_all_matches'],
            default => [$translator->trans('Close'), 'close'],
        };
        $key = ShortcutFormatter::format($keys->getBindings($action)[0] ?? '');

        return new Tooltip('find:'.$name, $text."\t".$key, $top, $span[0], $span[1], $rows);
    }
}
