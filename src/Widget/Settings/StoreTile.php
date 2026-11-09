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

    /** The roomy icon's columns: `┌────┐`. */
    private const int ICON_WIDTH = 6;

    /** The compact icon's columns: `[Re]`. */
    private const int COMPACT_ICON_WIDTH = 4;

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
     * The tile, $width wide, and where its button was drawn in it.
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
        $accent = $this->styles->of(Role::TabAccent);
        $initials = AnsiText::padRight($card->initials(), 2);
        $title = $this->styles->of(Role::Bright)->apply($card->title).('' === $card->badges ? '' : '  '.$card->badges);
        $dim = $this->styles->of(Role::Dim);
        $buttonCol = \max(0, $width - $buttonWidth - 1);
        if ($boxed) {
            $icon = [$accent->apply('┌────┐'), $accent->apply('│ '.$initials.' │'), $accent->apply('└────┘')];
            $text = [$title, $card->subtitle, $dim->apply($card->facts)];
            $room = \max(0, $buttonCol - self::ICON_WIDTH - 2);
            $lines = [];
            foreach ($text as $i => $line) {
                $lines[] = $this->line(self::cell($icon[$i], $this->direction).' '.self::text($line, $room, $this->direction).' '.self::cell($button[$i], $this->direction), $width);
            }

            return ['lines' => $lines, 'button' => [0, $buttonCol, $buttonWidth, FieldBox::ROWS]];
        }
        $room = \max(0, $buttonCol - self::COMPACT_ICON_WIDTH - 2);
        $about = \implode(' · ', \array_filter([$card->subtitle, $card->facts]));
        $lines = [
            $this->line(self::cell($accent->apply('['.$initials.']'), $this->direction).' '.self::text($title, $room, $this->direction).' '.self::cell($button[0], $this->direction), $width),
            $this->line(AnsiText::blank(self::COMPACT_ICON_WIDTH + 1).self::text($dim->apply($about), $width - self::COMPACT_ICON_WIDTH - 2, $this->direction), $width),
        ];

        return ['lines' => $lines, 'button' => [0, $buttonCol, AnsiUtils::visibleWidth($button[0]), 1]];
    }

    /** A tile's row, $width wide: the column the button leaves at its end is padding, held in a mirrored row. */
    private function line(string $line, int $width): string
    {
        return self::cell(AnsiText::truncate($line, $width, pad: true), $this->direction);
    }
}
