<?php

declare(strict_types=1);

namespace Porta\Widget\Settings;

use Porta\I18n\BaseDirection;
use Porta\I18n\Bidi;
use Porta\Support\AnsiText;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\FieldBox;
use Porta\Widget\Render\FieldState;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * One plugin's tile, the App Store's: its icon, its title and badges, a line
 * saying what it is, who made it, and its button at the right end.
 *
 * Roomy, the tile is {@see FieldBox::ROWS} tall: a boxed icon and a boxed
 * button either side of three lines. Compact, two rows: the icon and the
 * one-row button beside the title, what it is and who made it on one line
 * under it.
 */
final readonly class StoreTile
{
    /** The narrowest a tile is drawn: a grid with less room per column has one. */
    public const int MIN_WIDTH = 40;

    /** The roomy icon's box, in cells: `┌────┐` over three rows. */
    public const array ICON = [6, 3];

    /** The compact icon's box: `[Re]`, a picture two rows tall. */
    public const array COMPACT_ICON = [4, 2];

    /** The narrowest button, so short captions still read as one. */
    private const int MIN_BUTTON_WIDTH = 9;

    public function __construct(
        private Styles $styles,
        private BaseDirection $direction = BaseDirection::Ltr,
    ) {}

    /**
     * $cell held in place in a mirrored row: right-to-left marks either side
     * keep the neutral glyphs of a box or a gap from joining the Latin text
     * beside them, so a tile's columns mirror whole instead of tearing.
     */
    public static function cell(string $cell, BaseDirection $direction): string
    {
        return BaseDirection::Rtl === $direction ? Bidi::RTL_MARK.$cell.Bidi::RTL_MARK : $cell;
    }

    /** $cell held in place, its Latin edges pinned too: a value already laid out to its width. */
    public static function pinned(string $cell, BaseDirection $direction): string
    {
        return self::cell(BaseDirection::Rtl === $direction ? Bidi::pinLatinEdges($cell) : $cell, $direction);
    }

    /** $text truncated to $room and padded to it, its Latin edges pinned in a mirrored row. */
    public static function text(string $text, int $room, BaseDirection $direction): string
    {
        $text = AnsiText::truncate($text, \max(0, $room));

        return self::pinned(AnsiText::padRight($text, \max(0, $room)), $direction);
    }

    /**
     * How many rows a tile takes at control height $rows.
     *
     * @return 2|3
     */
    public static function height(int $rows): int
    {
        return FieldBox::ROWS === $rows ? FieldBox::ROWS : 2;
    }

    /**
     * The tile, $width wide, and where its button was drawn in it. A card
     * with a picture draws it in place of the initials, as tall as it is.
     *
     * @param int $rows the screen's control height: {@see FieldBox::ROWS}, or 1
     *
     * @return array{lines: list<string>, button: array{int, int, int, int}} the button as row, column, width, height
     */
    public function render(StoreCard $card, int $width, int $rows, bool $buttonHovered): array
    {
        $boxed = FieldBox::ROWS === $rows;
        $buttonWidth = \max(self::MIN_BUTTON_WIDTH, FieldBox::controlWidth($card->caption));
        $button = FieldBox::button($card->caption, $card->pressable ? FieldState::Idle : FieldState::Disabled, $buttonHovered, $buttonWidth, $boxed ? FieldBox::ROWS : 1, $this->styles);
        $title = $this->styles->of(Role::Bright)->apply($card->title).('' === $card->badges ? '' : '  '.$card->badges);
        $dim = $this->styles->of(Role::Dim);
        $buttonCol = \max(0, $width - $buttonWidth - 1);
        $icon = $card->icon ?? $this->initials($card, $boxed);
        $iconWidth = AnsiUtils::visibleWidth($icon[0] ?? '');
        $text = $boxed ? [$title, $card->subtitle, $dim->apply($card->facts)] : [$title, $dim->apply(\implode(' · ', \array_filter([$card->subtitle, $card->facts])))];
        $room = \max(0, $buttonCol - $iconWidth - 2);
        $lines = [];
        for ($i = 0, $height = \max(\count($text), \count($icon)); $i < $height; ++$i) {
            $cell = \array_key_exists($i, $icon) ? self::cell($icon[$i], $this->direction) : AnsiText::blank($iconWidth);
            $tail = \array_key_exists($i, $button) ? ' '.self::cell($button[$i], $this->direction) : '';
            // Compact, what it is and who made it run under the button, to the tile's end.
            $line = '' === $tail && !$boxed && 1 === $i ? $width - $iconWidth - 2 : $room;
            $lines[] = $this->line($cell.' '.self::text($text[$i] ?? '', $line, $this->direction).$tail, $width);
        }

        return ['lines' => $lines, 'button' => [0, $buttonCol, $boxed ? $buttonWidth : AnsiUtils::visibleWidth($button[0]), \count($button)]];
    }

    /**
     * The initials' box: `┌────┐` over `│ Re │` roomy, `[Re]` compact.
     *
     * @return list<string>
     */
    // @mago-expect lint:no-boolean-flag-parameter -- the box is drawn for the screen's control height, which is all the flag is.
    private function initials(StoreCard $card, bool $boxed): array
    {
        $accent = $this->styles->of(Role::TabAccent);
        $initials = AnsiText::padRight($card->initials(), 2);

        return (
            $boxed
                ? [$accent->apply('┌────┐'), $accent->apply('│ '.$initials.' │'), $accent->apply('└────┘')]
                : [$accent->apply('['.$initials.']')]
        );
    }

    /** A tile's row, $width wide: the column the button leaves at its end is padding, held in a mirrored row. */
    private function line(string $line, int $width): string
    {
        return self::cell(AnsiText::truncate($line, $width, pad: true), $this->direction);
    }
}
