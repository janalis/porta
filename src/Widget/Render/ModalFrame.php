<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Overlay\Region\CloseButton;
use Porta\Overlay\Region\WindowZone;
use Porta\Support\AnsiText;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The one place a floating modal's frame is drawn: a box-glyph title bar —
 * `╭ Title ─── Esc ☒╮` — and the side rails + bottom row that wrap the
 * content. Shared by the Welcome modal and the centered pickers so every
 * floating surface speaks the same visual language: accent-coloured border
 * ({@see Role::TabAccent}, the image pane's focus-frame precedent), bold
 * title, faint dismiss hint, and the platform's close button (the tab
 * strips' hover face, {@see TabCloseGlyph}) whose hit zone {@see \Porta\Overlay\Region\ModalBox::closeHit}
 * mirrors.
 *
 * Every returned row is exactly the requested width in visible columns — the
 * compositor invariant; a row that misses it blows out its host pane's line.
 */
final class ModalFrame
{
    /**
     * The full top row of a $width-column box: the title and the close
     * button, set into the border. The title yields to the fixed chrome so
     * the button stays put at {@see \Porta\Overlay\Region\ModalBox::CLOSE_OFFSET}
     * from its edge. $hot is the handle under the pointer: the button lights,
     * a top corner heavies. The bar is the platform's window's: on macOS a
     * red `⊗` leads it and the title sits in the middle — `╭⊗─── Title ───╮`
     * — elsewhere the title leads and a `☒` ends it — `╭─ Title ────☒╮` — as
     * on Windows. The rule runs unbroken into both corners and up to the
     * button: a space beside either reads as a break in the border. Esc is
     * not spelled out: every window closes on it.
     */
    public static function titleBar(string $title, int $width, Styles $styles, ?WindowZone $hot = null, CloseButton $closeButton = CloseButton::Standard): string
    {
        $accent = $styles->of(Role::TabAccent);
        $close = self::closeGlyph($closeButton, WindowZone::Close === $hot, $styles);
        $leads = $closeButton->leads();

        // The fixed columns: the corners, the button, a space each side of
        // the title, and one stroke of rule — after the button where it
        // leads, out of the corner where the title does. The stroke is the
        // first column a too-narrow box gives up: the button never is.
        $stroke = $width >= 6;
        $room = $width - ($stroke ? 6 : 5);
        // A title too long to fit gives up one more column, so the rule still
        // reaches the corner (or the button) after it.
        $label = AnsiText::truncate($title, \max(0, AnsiUtils::visibleWidth($title) > $room ? $room - 1 : $room));
        $labelWidth = AnsiUtils::visibleWidth($label);
        $fill = \max(0, $room - $labelWidth);
        // Where the button leads, the title sits in the middle: the rule
        // before it takes what centring asks of the fill, and leaves the rule
        // after it at least one stroke into the corner when there is one.
        $rule = $fill + ($stroke ? 1 : 0);
        $before = $leads ? \max(\min($rule, 1), \min($rule - 1, \intdiv($width - $labelWidth, num2: 2) - 3)) : (int) $stroke;
        $after = $rule - $before;

        $bar =
            self::edge($hot, WindowZone::TopLeft, Glyphs::MODAL_TOP_LEFT, Glyphs::MODAL_GRAB_TOP_LEFT, $styles)
            .($leads ? $close : '')
            .$accent->apply(\str_repeat(Glyphs::FRAME_HORIZONTAL, \max(0, $before)))
            .' '
            .$styles->of(Role::Bright)->withBold()->apply($label)
            .' '
            .$accent->apply(\str_repeat(Glyphs::FRAME_HORIZONTAL, \max(0, $after)))
            .($leads ? '' : $close)
            .self::edge($hot, WindowZone::TopRight, Glyphs::MODAL_TOP_RIGHT, Glyphs::MODAL_GRAB_TOP_RIGHT, $styles);

        // By construction the bar is exactly $width columns; the truncate is
        // the degenerate-width backstop (a box too narrow for its chrome) that
        // keeps the compositor invariant unconditional.
        return AnsiText::truncate($bar, \max(0, $width), pad: true);
    }

    /**
     * The chrome a frame adds around content of W columns × H rows: one rail
     * and one padding column each side (width = W + CHROME_COLS), and — below
     * the title bar — a padding row, the content, a padding row and the bottom
     * border (rows = H + CHROME_ROWS, of which CHROME_ROWS_TOP sit above the
     * content). Every consumer derives its geometry from these three numbers.
     */
    public const int CHROME_COLS = 4;
    public const int CHROME_ROWS = 4;
    public const int CHROME_ROWS_TOP = 2;

    /** The tee row {@see wrapSections()} draws between two sections. */
    public const int SECTION_ROWS = 1;

    /**
     * The rails, padding and bottom border around already-rendered content
     * rows — {@see wrapSections()} with a single section.
     *
     * @param list<string> $content
     *
     * @return list<string>
     */
    public static function wrap(array $content, int $innerWidth, Styles $styles, ?WindowZone $hot = null, ?ListOverflow $overflow = null): array
    {
        return self::wrapSections([$content], $innerWidth, $styles, $hot, $overflow);
    }

    /**
     * The frame body below the title bar: a padding row, each section's rows
     * (`│ ` + row + ` │`) with a `├──┤` tee row between sections, a padding
     * row, then the bottom border. Each content row is padded/clipped to
     * $innerWidth once, here — the single place the invariant is enforced.
     * Total row width: $innerWidth + CHROME_COLS.
     *
     * $legends[$i] names the section after the $i-th tee, set into it:
     * `├─ Files ───┤`, a group's heading drawn as the line that parts it.
     * $hot is the resize handle under the pointer (or being dragged): that
     * rail, the bottom border or that corner draws heavy.
     *
     * @param list<list<string>> $sections
     * @param list<string>       $legends
     *
     * @return list<string>
     */
    public static function wrapSections(array $sections, int $innerWidth, Styles $styles, ?WindowZone $hot = null, ?ListOverflow $overflow = null, array $legends = []): array
    {
        $accent = $styles->of(Role::TabAccent);
        $left = self::edge($hot, WindowZone::Left, Glyphs::FRAME_VERTICAL, Glyphs::MODAL_GRAB_VERTICAL, $styles);
        $right = self::edge($hot, WindowZone::Right, Glyphs::FRAME_VERTICAL, Glyphs::MODAL_GRAB_VERTICAL, $styles);
        $pad = $left.AnsiText::blank($innerWidth + 2).$right;
        // A clipped list says so in the padding it already has: ▲ above, ▼
        // below, centred and faint, the tab strip's and settings nav's cue.
        $cue = static fn (?string $glyph): string => null !== $glyph ? $left.' '.EdgeIndicator::row($glyph, $innerWidth, $styles).' '.$right : $pad;

        $rows = [$cue($overflow->above ?? false ? Glyphs::SCROLL_UP : null)];
        foreach ($sections as $i => $section) {
            if ($i > 0) {
                // At least its two tees, like every edge of the frame: a degenerate
                // width shuts the box rather than opening it.
                $rows[] = Rule::horizontal(\max(2, $innerWidth + 4), $styles, $legends[$i - 1] ?? '', Role::TabAccent);
            }
            foreach ($section as $line) {
                $rows[] = $left.' '.AnsiText::truncate($line, \max(0, $innerWidth), pad: true).' '.$right;
            }
        }
        $rows[] = $cue($overflow->below ?? false ? Glyphs::SCROLL_DOWN : null);
        // Every edge but the title bar is a resize handle: thin at rest
        // (identical to a fixed box — the hover reveals it, the
        // SeparatorWidget convention), heavy and bright while the pointer is
        // on it or a drag is running.
        $bottom = WindowZone::Bottom === $hot ? $styles->of(Role::Bright)->withBold() : $accent;
        $rule = WindowZone::Bottom === $hot ? Glyphs::MODAL_GRAB_HORIZONTAL : Glyphs::FRAME_HORIZONTAL;
        // Where the reader is in a clipped list — `3/40` — set into the bottom
        // border, as a trailing hint sits in a field's.
        $position = null !== $overflow && $overflow->clipped() ? $overflow->position() : '';
        $corner = self::edge($hot, WindowZone::BottomRight, Glyphs::MODAL_BOTTOM_RIGHT, Glyphs::MODAL_RESIZE_GRAB, $styles);
        $rows[] = WindowZone::BottomLeft === $hot
            ? self::edge($hot, WindowZone::BottomLeft, '', Glyphs::MODAL_GRAB_BOTTOM_LEFT, $styles).BorderHint::bottom('', $corner, $rule, $innerWidth + 2, $bottom, $position, $styles)
            : BorderHint::bottom(Glyphs::MODAL_BOTTOM_LEFT, $corner, $rule, $innerWidth + 2, $bottom, $position, $styles);

        return $rows;
    }

    /**
     * The close button at rest and under the pointer, each the platform's:
     * macOS's red at rest, elsewhere faint. Under the pointer both darken to
     * the theme's deep red — no fill behind it, so the frame keeps its one
     * surface. A theme whose deep red is its red (high contrast) and the
     * monochrome theme, which has none, darken by the dim attribute instead:
     * the hover always reads.
     */
    // @mago-expect lint:no-boolean-flag-parameter -- the bool IS the domain here: under the pointer or not.
    private static function closeGlyph(CloseButton $button, bool $hovered, Styles $styles): string
    {
        $glyph = $button->leads() ? Glyphs::WINDOW_CLOSE_MAC : Glyphs::WINDOW_CLOSE;
        $rest = $button->leads() ? $styles->of(Role::DiagError) : $styles->of(Role::Gutter);
        if (!$hovered) {
            return $rest->apply($glyph);
        }
        $dark = $styles->of(Role::DiagErrorDim);
        $distinct = $styles->hasColor() && $dark->getColor()?->toForegroundCode() !== $rest->getColor()?->toForegroundCode();

        return ($distinct ? $dark : $rest->withDim())->apply($glyph);
    }

    /** One frame cell that is also a handle: $rest in the accent, or $grab heavy and bright while $zone is hot. */
    private static function edge(?WindowZone $hot, WindowZone $zone, string $rest, string $grab, Styles $styles): string
    {
        return $zone === $hot ? $styles->of(Role::Bright)->withBold()->apply($grab) : $styles->of(Role::TabAccent)->apply($rest);
    }
}
