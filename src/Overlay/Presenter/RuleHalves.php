<?php

declare(strict_types=1);

namespace Porta\Overlay\Presenter;

use Porta\Layout\ViewRegions;
use Porta\Overlay\Region\DropdownRegion;
use Porta\Overlay\Region\FloatingRegions;
use Porta\Support\AnsiText;
use Porta\Ui\BidiTui;
use Porta\Ui\Density;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\ScreenDensity;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\MenuOverlay;
use Porta\Widget\TabBarWidget;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Where a shaded row meets the rule beside it, the shade reaches halfway in:
 * a `─` over a selected or hovered cell becomes `▄` in that cell's background,
 * one under it `▀` — the half a row bleeds into a blank gap
 * ({@see \Porta\Widget\Render\RowBleed}), drawn into a line instead. A
 * blank cell on nothing takes it too: the padding over a view in a window. And
 * where the side tab strip's own `│` meets the rule, the rule turns into it:
 * `┬` over it, `┴` under it.
 *
 * Only the rows that touch a workspace rule are looked at: the first and last
 * rows of the editor's tab strip and of each view's first sidebar widget (the
 * settings nav, git's mode tabs), and only on a roomy screen, against a `─`
 * in the rules' own tint — a compact strip sits on the toolbar shade, which
 * is no pill, and a toast's border is no rule. The strips are two rows, not
 * three, because of it: their upper halves are this pass's to draw.
 *
 * A pass over the finished frame, after {@see FrameJoins}' own joins, for
 * FrameJoins' reason: the rule is another widget's, and neither knows where
 * the other stands before the frame is laid out. A half stays on the surface
 * its row is on — a strip under a toast reaches into nothing of the toast's,
 * a view in a window into nothing outside it ({@see sameSurface()}) — and on a mirrored screen each
 * laid-out column is found where the reorder drew it.
 */
final readonly class RuleHalves
{
    /** The SGR state at a line's start, and after a reset. */
    private const array PLAIN = ['fg' => null, 'bg' => null, 'reverse' => false, 'dim' => false];

    public function __construct(
        private BidiTui $tui,
        private Renderer $renderer,
        private TabBarWidget $tabBar,
        private ViewRegions $regions,
        private FloatingRegions $floating,
        private Styles $styles,
        private ScreenDensity $density,
        private ViewRegistry $views,
    ) {}

    /**
     * The widgets whose edge rows meet a rule: the editor's tab strip, and
     * the open view's first sidebar widget. Only the open one: a closed
     * view's widgets keep the rect they last had, over what is drawn now.
     *
     * @return list<AbstractWidget>
     */
    public function edgeWidgets(): array
    {
        $id = $this->views->activeId();
        $first = null === $id ? null : $this->regions->find($id)?->sidebar()->all()[0] ?? null;

        return null === $first ? [$this->tabBar] : [$this->tabBar, $first];
    }

    /**
     * The floating surfaces over a drawn cell, by key into $floating.
     *
     * @param list<DropdownRegion> $floating
     *
     * @return list<int>
     */
    public static function surfaces(array $floating, int $row, int $col): array
    {
        return \array_keys(\array_filter($floating, static fn (DropdownRegion $region): bool => $region->coversRow($row) && $col >= $region->col && $col < ($region->col + $region->width)));
    }

    /**
     * @param list<string> $lines the frame as painted
     *
     * @return list<string>
     */
    public function apply(array $lines): array
    {
        if ([] === $lines || Density::Roomy !== $this->density->current()) {
            return $lines;
        }
        $rects = [];
        foreach ($this->edgeWidgets() as $widget) {
            $rect = $this->renderer->getWidgetRect($widget);
            if (null !== $rect && $rect->rows > 0 && $rect->columns > 0) {
                $rects[] = $rect;
            }
        }
        $floating = $this->floating->regions();
        $spans = self::spans($lines, $rects, $floating, $this->tui->logicalColumn(...), $this->styles->of(Role::Rule));
        // The tab strip opens a pane divider beside a shaded tab.
        $strip = $this->renderer->getWidgetRect($this->tabBar);
        if (null !== $strip) {
            \array_push($spans, ...self::openings($lines, $strip, $floating, $this->tui->logicalColumn(...), $this->styles->of(Role::Rule)));
        }

        return [] === $spans ? $lines : MenuOverlay::apply($lines, $spans, AnsiUtils::visibleWidth($lines[0]));
    }

    /**
     * A pane divider beside a shaded tab opens — beside a horizontal strip's
     * first or last tab, beside any of a side strip's rows: the divider's
     * cell on the tab's row becomes a blank of the tab's own style,
     * and the rule or join over and under it the tab's halves — the tab and
     * the panes beside it read as one surface, as a side strip's open tab
     * does beside its rule.
     *
     * @param list<string>            $lines
     * @param list<DropdownRegion>    $floating
     * @param \Closure(int, int): int $logical a drawn column on a row → the column the widgets laid out
     *
     * @return list<array{row: int, col: int, width: int, segment: string}>
     */
    public static function openings(array $lines, WidgetRect $strip, array $floating, \Closure $logical, Style $rule): array
    {
        $tint = self::state(self::lead($rule->apply(Glyphs::FRAME_HORIZONTAL)))['fg'];
        // A rule, its joins, or the divider itself running on past the strip.
        $joins = [Glyphs::FRAME_HORIZONTAL, Glyphs::PANE_DIVIDER, Glyphs::RULE_DOWN, Glyphs::RULE_UP, Glyphs::MODAL_TEE_LEFT, Glyphs::MODAL_TEE_RIGHT, Glyphs::RULE_CROSS];
        $glyph = static fn (int $row, int $col): string => \array_key_exists($row, $lines) ? AnsiUtils::stripAnsiCodes(AnsiText::columnsFrom($lines[$row], $col, 1)) : '';
        $halves = [Glyphs::HALF_UPPER, Glyphs::HALF_LOWER, Glyphs::HALF_BOTH];
        $spans = [];
        /** @var array<string, array{row: int, col: int, above?: string, below?: string}> $beside the cells over and under an opened one, and the tab cells whose halves they take */
        $beside = [];
        for ($row = $strip->row; $row < ($strip->row + $strip->rows) && \array_key_exists($row, $lines); ++$row) {
            $drawn = \array_filter(
                \range(start: 0, end: AnsiUtils::visibleWidth($lines[$row]) - 1),
                static fn (int $col): bool => $logical($row, $col) >= $strip->col && $logical($row, $col) < ($strip->col + $strip->columns),
            );
            if ([] === $drawn) {
                continue;
            }
            foreach ([[\min($drawn) - 1, \min($drawn)], [\max($drawn) + 1, \max($drawn)]] as [$side, $end]) {
                // A tab's own row: a gap between two shaded tabs is shaded too, but is no tab.
                $cell = AnsiText::columnsFrom($lines[$row], $end, 1);
                $state = self::state(self::lead($cell));
                if (
                    Glyphs::PANE_DIVIDER !== $glyph($row, $side)
                    || \in_array($glyph($row, $end), $halves, strict: true)
                    || null === $state['bg'] && !$state['reverse']
                    || [] !== RuleHalves::surfaces($floating, $row, $side)
                ) {
                    continue;
                }
                $spans[] = ['row' => $row, 'col' => $side, 'width' => 1, 'segment' => self::lead($cell)." \x1b[0m"];
                foreach ([[$row - 1, 'below'], [$row + 1, 'above']] as [$at, $whose]) {
                    $join = AnsiText::columnsFrom($lines[$at] ?? '', $side, 1);
                    if (!\in_array($glyph($at, $side), $joins, strict: true) || $tint !== self::state(self::lead($join))['fg']) {
                        continue;
                    }
                    $beside["{$at}:{$side}"] ??= ['row' => $at, 'col' => $side];
                    $beside["{$at}:{$side}"][$whose] = $cell;
                }
            }
        }
        foreach ($beside as $cell) {
            $spans[] = ['row' => $cell['row'], 'col' => $cell['col'], 'width' => 1, 'segment' => self::between($cell['above'] ?? null, $cell['below'] ?? null)];
        }

        return $spans;
    }

    /**
     * The cells to redraw, read off the painted frame.
     *
     * @param list<string>               $lines    the frame as painted
     * @param list<WidgetRect>           $rects    the widgets whose edge rows meet a rule, as laid out
     * @param list<DropdownRegion>       $floating the surfaces over the panes
     * @param \Closure(int, int): int    $logical  a drawn column on a row → the column the widgets laid out
     * @param Style                      $rule     the rules' style: only a `─` in its tint is one
     *
     * @return list<array{row: int, col: int, width: int, segment: string}>
     */
    public static function spans(array $lines, array $rects, array $floating, \Closure $logical, Style $rule): array
    {
        $surface = static fn (int $row, int $col): array => self::surfaces($floating, $row, $col);
        $tint = self::state(self::lead($rule->apply(Glyphs::FRAME_HORIZONTAL)))['fg'];
        $spans = [];
        foreach ($rects as $rect) {
            $edges = [
                [$rect->row, $rect->row - 1, Glyphs::HALF_LOWER, Glyphs::RULE_DOWN],
                [$rect->row + $rect->rows - 1, $rect->row + $rect->rows, Glyphs::HALF_UPPER, Glyphs::RULE_UP],
            ];
            foreach ($edges as [$edge, $line, $half, $join]) {
                if (!\array_key_exists($edge, $lines) || !\array_key_exists($line, $lines)) {
                    continue;
                }
                for ($col = 0, $columns = AnsiUtils::visibleWidth($lines[$edge]); $col < $columns; ++$col) {
                    $laid = $logical($edge, $col);
                    if ($laid < $rect->col || $laid >= ($rect->col + $rect->columns) || !self::sameSurface($surface($line, $col), $surface($edge, $col), $floating, $rect)) {
                        continue;
                    }
                    // A rule, or a blank on nothing: the padding over a view
                    // in a window, the room under a short list.
                    $beside = AnsiText::columnsFrom($lines[$line], $col, 1);
                    $state = self::state(self::lead($beside));
                    $glyph = AnsiUtils::stripAnsiCodes($beside);
                    $isRule = Glyphs::FRAME_HORIZONTAL === $glyph && $tint === $state['fg'];
                    if (!$isRule && (' ' !== $glyph || null !== $state['bg'] || $state['reverse'])) {
                        continue;
                    }
                    $cell = AnsiText::columnsFrom($lines[$edge], $col, 1);
                    $segment = match (true) {
                        Glyphs::FRAME_VERTICAL !== AnsiUtils::stripAnsiCodes($cell) => self::half($cell, $half),
                        $isRule => $rule->apply($join),
                        default => null, // a line meeting a blank joins nothing
                    };
                    if (null !== $segment) {
                        $spans[] = ['row' => $line, 'col' => $col, 'width' => 1, 'segment' => $segment];
                    }
                }
            }
        }

        return $spans;
    }

    /**
     * The cell between the tab above it ($above, whose lower half it shows)
     * and the tab below ($below, its upper half): one of them, or both — the
     * upper's colour on the lower's, as the strip's own gap between two
     * shaded tabs draws it.
     */
    private static function between(?string $above, ?string $below): string
    {
        $upper = null === $above ? null : self::state(self::lead($above))['bg'];
        $lower = null === $below ? null : self::state(self::lead($below))['bg'];

        return match (true) {
            null !== $upper && null !== $lower => "\x1b[{$upper};".self::asBackground($lower).'m'.Glyphs::HALF_UPPER."\x1b[0m",
            null !== $below => (string) self::half($below, Glyphs::HALF_LOWER),
            default => (string) self::half((string) $above, Glyphs::HALF_UPPER),
        };
    }

    /** A colour read as a foreground ({@see state()}) as the background of the same colour. */
    private static function asBackground(string $colour): string
    {
        return \str_starts_with($colour, '38;') ? '48;'.\substr($colour, offset: 3) : (string) ((int) $colour + 10);
    }

    /**
     * Whether a half may cross from the edge row's cell to the one beside it:
     * every surface over the cell beside is over the edge cell too (a toast
     * over a window's padding is not the window), and the edge cell is under
     * nothing more but its own pane — the window registers one per widget it
     * holds, exactly where that widget stands.
     *
     * @param list<int>            $beside the surfaces over the cell beside, by key
     * @param list<int>            $edge   and over the edge row's cell
     * @param list<DropdownRegion> $floating
     */
    public static function sameSurface(array $beside, array $edge, array $floating, WidgetRect $rect): bool
    {
        $own = static fn (int $key): bool => (
            $floating[$key]->top === $rect->row
            && $floating[$key]->col === $rect->col
            && $floating[$key]->width === $rect->columns
            && $floating[$key]->count === $rect->rows
        );

        return [] === \array_diff($beside, $edge) && \array_all(\array_diff($edge, $beside), $own);
    }

    /**
     * $glyph in the shade the painted $cell shows, or null for a cell with
     * none: its background as the glyph's colour, or — reversed, as the
     * monochrome theme draws a selection — the colour it shows as one.
     */
    private static function half(string $cell, string $glyph): ?string
    {
        $state = self::state(self::lead($cell));
        $colour = $state['reverse'] ? $state['fg'] ?? '' : $state['bg'];

        return match (true) {
            null === $colour => null,
            $state['reverse'] => ('' === $colour ? '' : "\x1b[{$colour}m").($state['dim'] ? "\x1b[2m" : '').$glyph."\x1b[0m",
            default => "\x1b[{$colour}m{$glyph}\x1b[39m",
        };
    }

    /**
     * What $cell sets before its glyph: what the glyph is drawn in, not what
     * follows it. Every escape before the glyph counts, not only colours — a
     * zero-width marker (the editor's APC cursor mark) between two colour
     * codes must not hide the second, or a reset after it goes unread and a
     * shade from a cell before (the scrollbar's) reads as this one's.
     */
    private static function lead(string $cell): string
    {
        $lead = [];
        \preg_match('/^(?:\x1b\[[0-9;?]*[ -\/]*[@-~]|\x1b[_\]P][^\x07\x1b]*(?:\x07|\x1b\\\\))*/', $cell, $lead);

        return $lead[0] ?? '';
    }

    /**
     * The SGR state in force at the end of $sgr: the colours as foreground
     * parameters (a background read as the foreground of the same colour),
     * and whether it is reversed or dim.
     *
     * @return array{fg: ?string, bg: ?string, reverse: bool, dim: bool}
     */
    private static function state(string $sgr): array
    {
        $state = self::PLAIN;
        $sequences = [];
        \preg_match_all('/\x1b\[([0-9;]*)m/', $sgr, $sequences);
        foreach ($sequences[1] as $sequence) {
            $codes = '' === $sequence ? [0] : \array_map(\intval(...), \explode(';', $sequence));
            for ($i = 0, $n = \count($codes); $i < $n; ++$i) {
                $code = $codes[$i];
                // 38/48 carry their colour in the parameters after them.
                $extended = 38 === $code || 48 === $code ? \array_slice($codes, $i + 1, 5 === ($codes[$i + 1] ?? null) ? 2 : 4) : [];
                $i += \count($extended);
                $colour = \implode(';', [38, ...$extended]);
                $state = match (true) {
                    0 === $code => self::PLAIN,
                    2 === $code => ['dim' => true] + $state,
                    22 === $code => ['dim' => false] + $state,
                    7 === $code => ['reverse' => true] + $state,
                    27 === $code => ['reverse' => false] + $state,
                    38 === $code => ['fg' => $colour] + $state,
                    48 === $code => ['bg' => $colour] + $state,
                    39 === $code => ['fg' => null] + $state,
                    49 === $code => ['bg' => null] + $state,
                    $code >= 30 && $code <= 37, $code >= 90 && $code <= 97 => ['fg' => (string) $code] + $state,
                    $code >= 40 && $code <= 47, $code >= 100 && $code <= 107 => ['bg' => (string) ($code - 10)] + $state,
                    default => $state,
                };
            }
        }

        return $state;
    }
}
