<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Support\AnsiText;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Sgr;
use Porta\Ui\Theme\Styles;
use Porta\Widget\MatchRowDecorator;
use Porta\Widget\Support\OptionTagState;
use Porta\Widget\Support\RowSelection;

/**
 * The Find-in-Files modal's leaf rows: one option tag, one file header, one
 * match line, the empty-state filler. Pure and static — each takes what it
 * paints plus the palette, and reads nothing else.
 *
 * Only the leaves live here. The modal's *layout* methods stay in
 * {@see \Porta\Overlay\State\FindInFilesModal} because they read some forty of
 * its fields (window, selection, drag geometry, scope, options, preview state):
 * moving those would need either a forty-field parameter object or a
 * back-reference to the modal, which would relocate the code while making the
 * coupling worse. Contrast {@see PickerLayout}, whose layouts *were* extractable
 * because they already took their state as parameters.
 *
 * @phpstan-import-type ResultRow from \Porta\Support\ResultRowShape
 */
final class FindModalRows
{
    /**
     * One option toggle. Each tag self-closes; the row's Toolbar background
     * re-applies itself after the close, so no explicit re-open is needed
     * between tags. An enabled tag is bracketed (like every toggle) so it reads
     * without color; all three states stay glyph-width + 2 for the click zones.
     */
    public static function optionTag(string $glyph, OptionTagState $state, Styles $styles): string
    {
        return match ($state) {
            OptionTagState::On => $styles->of(Role::SelectedRow)->apply('['.$glyph.']'),
            // Lit already, so the pointer underlines rather than re-tints —
            // the one mark that reads over the selection, in colour or not.
            OptionTagState::OnHovered => $styles
                ->of(Role::SelectedRow)
                ->withUnderline()
                ->apply('['.$glyph.']'),
            OptionTagState::Hovered => $styles->of(Role::RowHighlightBg)->apply(' '.$glyph.' '),
            OptionTagState::Off => $styles->of(Role::Faint)->apply(' '.$glyph.' '),
        };
    }

    /** A file's grouping header: path plus its match count. */
    public static function fileHeader(string $path, int $count, int $width, Styles $styles): string
    {
        $text = ' '.$styles->of(Role::TabAccent)->apply($path).$styles->of(Role::Dim)->apply('  ('.$count.')');

        return $styles->of(Role::PopupRow)->apply(AnsiText::truncate($text, $width, pad: true));
    }

    /**
     * One match line: `    <line>  <text>`, with the matched ranges highlighted
     * over the row's own background.
     *
     * @param ResultRow $result
     */
    public static function match(array $result, RowSelection $selected, int $width, Styles $styles): string
    {
        $bg = $styles->of(ModalRowStyle::role($selected))->getAnsiRestore();
        $label = (string) ($result['line'] + 1);
        $prefixWidth = 4 + \mb_strwidth($label) + 2;
        $text = '    '.$styles->of(Role::TabAccent)->apply($label).'  '.$result['text'];

        $searchStyle = $styles->of(Role::SearchBg);

        return MatchRowDecorator::highlight($text, $width, $bg, $prefixWidth, $searchStyle->getAnsiRestore(), Sgr::resetFor($searchStyle), $result['ranges'] ?? []);
    }

    /**
     * The filler shown instead of results: the prompt before anything is typed,
     * "no results" after. The '' ellipsis is deliberate — a fixed short filler
     * row is never worth a truncation mark.
     */
    public static function empty(string $message, int $width, Styles $styles): string
    {
        return $styles->merge(Role::PopupRow, Role::CompletionHint)->apply(AnsiText::truncate(' '.$message, $width, pad: true, ellipsis: ''));
    }

    /**
     * A match row in replace-preview mode: a checkbox, the line number, and
     * either `old → new` or, for an excluded row — a null $replacement, since
     * the modal's preview closure is never invoked for one — the original line
     * and $excludedNote, the translated `(excluded)`.
     *
     * @param ResultRow $result
     */
    public static function previewMatch(array $result, RowSelection $selected, ?string $replacement, string $excludedNote, int $width, Styles $styles): string
    {
        $bg = $styles->of(ModalRowStyle::role($selected))->getAnsiRestore();
        $label = (string) ($result['line'] + 1);
        $old = $result['text'];
        $checkbox = '  '.FieldBox::checkbox(null !== $replacement).' ';
        $prefixWidth = \mb_strwidth($checkbox) + \mb_strwidth($label) + 2;
        $head = $checkbox.$styles->of(Role::TabAccent)->apply($label).'  ';

        $text = null === $replacement
            ? $head.$old.$styles->of(Role::Dim)->apply('   '.$excludedNote)
            : $head.$old.'  '.Glyphs::REPLACE_ARROW.'  '.$styles->of(Role::Added)->apply($replacement);

        $searchStyle = $styles->of(Role::SearchBg);

        return MatchRowDecorator::highlight($text, $width, $bg, $prefixWidth, $searchStyle->getAnsiRestore(), Sgr::resetFor($searchStyle), $result['ranges'] ?? []);
    }
}
