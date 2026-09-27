<?php

declare(strict_types=1);

namespace Porta\Overlay\State;

use Porta\I18n\Translator;
use Porta\Search\TokenScope;
use Porta\Ui\ClickZones;
use Porta\Ui\ShortcutFormatter;
use Porta\Ui\Tooltip;

/**
 * What Find in Files' option tags say under the pointer. They are the most
 * cryptic cells in the IDE — `Pc`, and a token scope drawn as `·"`, `i"` or
 * `x"` — so each is spelled out, with the key that flips it; the scope tag
 * says which of its three states it is in, since the glyph alone does not.
 *
 * Out of the modal because the modal sits on the control-flow ceiling
 * (`kan-defect`), and a table of answers is not its logic.
 */
final class FindModalTooltip
{
    /**
     * The tag at inner column $innerCol of the options band, as a tooltip on
     * the screen: the band starts at column $left and spans $rows rows from
     * $top. Null off every tag.
     *
     * @param array<string, list<string>> $keys the modal's key table
     */
    public static function of(ClickZones $zones, int $innerCol, int $top, int $rows, int $left, TokenScope $scope, array $keys, Translator $translator): ?Tooltip
    {
        $name = $zones->at($innerCol);
        $span = null === $name ? null : $zones->span($name);
        if (null === $name || null === $span) {
            return null;
        }
        [$text, $action] = match ($name) {
            'regex' => [$translator->trans('Regular expression'), 'toggle_regex'],
            'case' => [$translator->trans('Match case'), 'toggle_case'],
            'word' => [$translator->trans('Whole word'), 'toggle_word'],
            'preserve' => [$translator->trans('Preserve case when replacing'), 'toggle_preserve'],
            default => [
                match ($scope) {
                    TokenScope::Any => $translator->trans('Match anywhere — comments and strings too'),
                    TokenScope::InCommentsStrings => $translator->trans('Match only inside comments and strings'),
                    TokenScope::ExceptCommentsStrings => $translator->trans('Match everywhere except comments and strings'),
                },
                'toggle_tokens',
            ],
        };
        $key = ShortcutFormatter::format($keys[$action][0] ?? '');

        return new Tooltip('find-modal:'.$name, $text."\t".$key, $top, $left + $span[0], $left + $span[1], $rows);
    }
}
