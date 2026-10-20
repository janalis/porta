<?php

declare(strict_types=1);

namespace Porta\Overlay\Presenter;

use Porta\Container\AsEager;
use Porta\Layout\ViewRegion;
use Porta\Layout\ViewRegions;
use Porta\Layout\WorkspaceLayout;
use Porta\Overlay\Region\DropdownRegion;
use Porta\Overlay\Region\FloatingRegions;
use Porta\Support\AnsiText;
use Porta\Ui\BidiTui;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\MenuOverlay;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\Renderer;

/**
 * The joins between lines that separate widgets drew: where a sidebar's `│`
 * meets a `─`, the rule turns into it — `┬` above the divider's top, `┴`
 * below its end, `├` `┤` `┼` along it where a rule runs up to it from one side
 * or both (the settings groups' legends, the git history's Files heading).
 *
 * A pass over the finished frame rather than a widget asking its neighbour:
 * neither the edge rule nor the legend knows where the divider stands, and a
 * column pushed into them before the frame is one frame stale during a
 * sidebar drag. After the RTL reorder, so a mirrored divider is found where it
 * is drawn and its tee is not mirrored twice.
 *
 * Only an idle divider joins — the hovered `┃` is a grab, not a line — and no
 * cell under a floating surface (a dropdown, a modal, a toast) is touched. A
 * view's dividers are drawn IN a floating surface, its window: the window's
 * own regions are the surface they stand on, not one over them, so only what
 * floats over the window stops their joins.
 */
#[AsEager]
final readonly class FrameJoins
{
    public function __construct(
        BidiTui $tui,
        private Renderer $renderer,
        private WorkspaceLayout $layout,
        private ViewRegions $regions,
        private FloatingRegions $floating,
        private Styles $styles,
        RuleHalves $halves,
        private ?ViewWindowPresenter $window = null,
    ) {
        // The joins, then where a shaded row meets a rule ({@see RuleHalves}).
        $tui->setFrameJoin(fn (array $lines): array => $halves->apply($this->join($lines)));
    }

    /**
     * @param list<string> $lines the frame as painted
     *
     * @return list<string>
     */
    public function join(array $lines): array
    {
        $dividers = [];
        // The workspace's divider, then every view's, drawn in its window.
        $separators = [[$this->layout->separator], ...\array_map(static fn (ViewRegion $region): array => $region->separators(), $this->regions->all())];
        foreach ($separators as $surface => $group) {
            foreach ($group as $separator) {
                $rect = $this->renderer->getWidgetRect($separator);
                if (null !== $rect && $rect->rows > 0) {
                    $dividers[] = [$rect->row, $rect->col, $rect->rows, 0 !== $surface];
                }
            }
        }
        if ([] === $dividers || [] === $lines) {
            return $lines;
        }
        $rule = $this->styles->of(Role::Rule);

        $spans = [];
        foreach (self::junctions($lines, $dividers, $this->floating->regions(), $this->window?->regions() ?? []) as [$row, $col, $glyph]) {
            $spans[] = ['row' => $row, 'col' => $col, 'width' => 1, 'segment' => $rule->apply($glyph)];
        }

        return [] === $spans ? $lines : MenuOverlay::apply($lines, $spans, AnsiUtils::visibleWidth($lines[0]));
    }

    /**
     * Where the joins go, read off the painted cells.
     *
     * @param list<string>                                  $lines    the frame as painted
     * @param list<array{int, int, int}|array{int, int, int, bool}> $dividers each divider's [row, column, rows] as laid out, and whether it stands in the view window
     * @param list<DropdownRegion>                          $floating the surfaces over the panes
     * @param list<DropdownRegion>                          $window   the view window's own: the surface its dividers stand on
     *
     * @return list<array{int, int, string}> [row, column, glyph]
     */
    public static function junctions(array $lines, array $dividers, array $floating, array $window = []): array
    {
        // Over the window: every floating surface but the window's own.
        $overWindow = \array_values(\array_filter($floating, static fn (DropdownRegion $region): bool => !\array_any($window, static fn (DropdownRegion $own): bool => self::same($own, $region))));
        $columns = [] === $lines ? 0 : AnsiUtils::visibleWidth($lines[0]);
        // The plain glyph at a cell; nothing off the frame.
        $cell = static fn (int $row, int $col): string => $col < 0 || $col >= $columns || !\array_key_exists($row, $lines)
            ? ''
            : AnsiUtils::stripAnsiCodes(AnsiText::columnsFrom($lines[$row], $col, 1));
        $joins = [];
        foreach ($dividers as $divider) {
            [$top, $col, $rows] = $divider;
            $over = $divider[3] ?? false ? $overWindow : $floating;
            $covered = static fn (int $row, int $col): bool => (
                [] !== \array_filter($over, static fn (DropdownRegion $region): bool => $region->coversRow($row) && $col >= $region->col && $col < ($region->col + $region->width))
            );
            $rule = static fn (int $row, int $col): bool => Glyphs::FRAME_HORIZONTAL === $cell($row, $col) && !$covered($row, $col);
            $col = self::drawnAt($cell, $top, $col, $rows, $columns);
            if (null === $col) {
                continue;
            }
            if ($rule($top - 1, $col)) {
                $joins[] = [$top - 1, $col, Glyphs::RULE_DOWN];
            }
            if ($rule($top + $rows, $col)) {
                $joins[] = [$top + $rows, $col, Glyphs::RULE_UP];
            }
            for ($row = $top; $row < ($top + $rows); ++$row) {
                if (Glyphs::PANE_DIVIDER !== $cell($row, $col) || $covered($row, $col)) {
                    continue;
                }
                $glyph = match ([$rule($row, $col - 1), $rule($row, $col + 1)]) {
                    [true, true] => Glyphs::RULE_CROSS,
                    [true, false] => Glyphs::MODAL_TEE_RIGHT,
                    [false, true] => Glyphs::MODAL_TEE_LEFT,
                    default => null,
                };
                if (null !== $glyph) {
                    $joins[] = [$row, $col, $glyph];
                }
            }
        }

        return $joins;
    }

    /** The same rectangle: a window's regions are minted afresh on every ask. */
    private static function same(DropdownRegion $a, DropdownRegion $b): bool
    {
        return [$a->top, $a->col, $a->width, $a->count] === [$b->top, $b->col, $b->width, $b->count];
    }

    /**
     * The column the divider is drawn at: its laid-out one, or its mirror on a
     * right-to-left screen — whichever holds the idle `│`. Null when neither
     * does on any of its rows: it is being hovered or dragged, or covered.
     *
     * @param \Closure(int, int): string $cell
     */
    private static function drawnAt(\Closure $cell, int $top, int $col, int $rows, int $columns): ?int
    {
        for ($row = $top; $row < ($top + $rows); ++$row) {
            foreach ([$col, $columns - 1 - $col] as $candidate) {
                if (Glyphs::PANE_DIVIDER === $cell($row, $candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }
}
