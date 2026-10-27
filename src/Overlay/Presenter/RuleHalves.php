<?php

declare(strict_types=1);

namespace Porta\Overlay\Presenter;

use Porta\Layout\PreviewSplit;
use Porta\Layout\ViewRegions;
use Porta\Layout\WorkspaceLayout;
use Porta\Overlay\Region\DropdownRegion;
use Porta\Overlay\Region\FloatingRegions;
use Porta\Support\AnsiText;
use Porta\Ui\BidiTui;
use Porta\Ui\Density;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\ScreenDensity;
use Porta\Ui\Theme\Styles;
use Porta\Widget\BreadcrumbsWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\MarkdownPageWidget;
use Porta\Widget\Render\CrumbBar;
use Porta\Widget\Render\MenuOverlay;
use Porta\Widget\Render\RowBleed;
use Porta\Widget\TabBarWidget;
use Porta\Workbench\EditorArea;
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
 * `┬` over it, `┴` under it. Where the edge row holds a bar's half instead of
 * a rule ({@see \Porta\Widget\EdgeRuleWidget}), the cell keeps the bar's
 * half and takes the shaded row's as the other: the bar's colour behind it.
 *
 * Only the rows that touch a workspace rule are looked at: the first and last
 * rows of the editor's tab strip, of the file tree, and of each view's first
 * sidebar widget (the settings nav, git's mode tabs), and the first row of a
 * head bar ({@see headWidgets()}), and only on a roomy screen, against a `─`
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
        private FileTreeWidget $tree,
        private BreadcrumbsWidget $crumbs,
        private EditorArea $area,
        private PreviewSplit $split,
        private WorkspaceLayout $layout,
    ) {}

    /**
     * The widgets whose edge rows meet a rule or a bar's half: the editor's
     * tab strip, the file tree while no view is open over it, and the open
     * view's first sidebar widget. Only the open one: a closed view's widgets
     * keep the rect they last had, over what is drawn now.
     *
     * @return list<AbstractWidget>
     */
    public function edgeWidgets(): array
    {
        $id = $this->views->activeId();
        if (null === $id) {
            return [$this->tabBar, $this->tree];
        }
        $first = $this->regions->find($id)?->sidebar()->all()[0] ?? null;

        return null === $first ? [$this->tabBar] : [$this->tabBar, $first];
    }

    /**
     * The bars that reach only UP into the row over them, their own row under
     * them holding their lower half already: the editor's navigation bar, and
     * a markdown page's header when that page is the pane on screen. Their
     * last row is content, which has no half to give. Each reaches up in its
     * bar's shade, not cell by cell: a lit toggle on the bar is no taller.
     *
     * @return list<array{AbstractWidget, Role}>
     */
    public function headWidgets(): array
    {
        if (null !== $this->views->activeId()) {
            return []; // under a view's window: nothing of the editor's reaches into it
        }
        $pane = $this->area->visiblePane();
        $crumbs = [$this->crumbs, CrumbBar::SHADE];

        return $pane instanceof MarkdownPageWidget ? [$crumbs, [$pane, Role::HeaderBar]] : [$crumbs];
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
        $floating = $this->floating->regions();
        $logical = $this->tui->logicalColumn(...);
        $rule = $this->styles->of(Role::Rule);
        $heads = [];
        foreach ($this->headWidgets() as [$widget, $shade]) {
            foreach ($this->rects([$widget]) as $rect) {
                $heads[] = [$rect, $this->styles->of($shade)];
            }
        }
        $bar = $this->styles->of(Role::HeaderBar);
        $spans = self::spans($lines, $this->rects($this->edgeWidgets()), $floating, $logical, $rule, $bar, $heads, $this->barRows());
        // The tab strip opens a pane divider beside a shaded tab.
        $strip = $this->renderer->getWidgetRect($this->tabBar);
        if (null !== $strip) {
            \array_push($spans, ...self::openings($lines, $strip, $floating, $logical, $rule, $bar));
        }
        // The split's divider stands on the shade of the page header beside it.
        $page = $this->area->visiblePane();
        $header = $page instanceof MarkdownPageWidget ? $this->renderer->getWidgetRect($page) : null;
        $divider = 2 === \count($this->area->members()) ? $this->renderer->getWidgetRect($this->split->separator) : null;
        if (null !== $header && null !== $divider) {
            \array_push($spans, ...self::dividerOnShade($lines, $header, $divider, $floating, $logical, $rule));
        }

        return [] === $spans ? $lines : MenuOverlay::apply($lines, $spans, AnsiUtils::visibleWidth($lines[0]));
    }

    /**
     * The rows a head bar may reach up into, each holding a bar's half: the
     * menu bar's edge row, and each head's own row under it.
     *
     * @return list<int>
     */
    public function barRows(): array
    {
        $top = $this->renderer->getWidgetRect($this->layout->topRule);
        $rows = null !== $top && $this->layout->topRule->holdsBarHalf() ? [$top->row] : [];
        foreach ($this->rects(\array_map(static fn (array $head): AbstractWidget => $head[0], $this->headWidgets())) as $rect) {
            $rows[] = $rect->row + 1;
        }

        return $rows;
    }

    /**
     * The laid-out rects of $widgets that have any.
     *
     * @param list<AbstractWidget> $widgets
     *
     * @return list<WidgetRect>
     */
    private function rects(array $widgets): array
    {
        $rects = [];
        foreach ($widgets as $widget) {
            $rect = $this->renderer->getWidgetRect($widget);
            if (null !== $rect && $rect->rows > 0 && $rect->columns > 0) {
                $rects[] = $rect;
            }
        }

        return $rects;
    }

    /**
     * The split's divider beside a page header's row keeps its `│` on the
     * header's shade, so the header reaches the line instead of stopping a
     * column short of it. Only the header's row: the rows over and under it
     * hold the header's halves, which a `│` cell cannot.
     *
     * @param list<string>            $lines
     * @param WidgetRect              $header  the page, whose first row is the header
     * @param WidgetRect              $divider the split's separator
     * @param list<DropdownRegion>    $floating
     * @param \Closure(int, int): int $logical a drawn column on a row → the column the widgets laid out
     *
     * @return list<array{row: int, col: int, width: int, segment: string}>
     */
    public static function dividerOnShade(array $lines, WidgetRect $header, WidgetRect $divider, array $floating, \Closure $logical, Style $rule): array
    {
        $row = $header->row;
        $beside = match (true) {
            $divider->col === ($header->col - 1) => $header->col,
            $divider->col === ($header->col + $header->columns) => $header->col + $header->columns - 1,
            default => null,
        };
        if (null === $beside || !\array_key_exists($row, $lines) || $row < $divider->row || $row >= ($divider->row + $divider->rows)) {
            return [];
        }

        return self::dividerBeside($lines[$row], $row, $divider->col, $beside, $floating, $logical, $rule);
    }

    /**
     * {@see dividerOnShade} on the header's row, once the divider is known to
     * stand beside the header there: the divider's cell redrawn on the
     * shade of the header's cell at $beside — or nothing, when either is not
     * drawn, a floating surface covers it, or the cell is not the rule's `│`.
     *
     * @param list<DropdownRegion>    $floating
     * @param \Closure(int, int): int $logical
     *
     * @return list<array{row: int, col: int, width: int, segment: string}>
     */
    private static function dividerBeside(string $text, int $row, int $dividerCol, int $beside, array $floating, \Closure $logical, Style $rule): array
    {
        $drawn = static fn (int $laid): ?int => \array_find(\range(start: 0, end: AnsiUtils::visibleWidth($text) - 1), static fn (int $col): bool => $logical($row, $col) === $laid);
        $at = $drawn($dividerCol);
        $shade = $drawn($beside);
        if (null === $at || null === $shade || [] !== self::surfaces($floating, $row, $at)) {
            return [];
        }
        $line = AnsiText::columnsFrom($text, $at, 1);
        $tint = self::state(self::lead($line))['fg'];
        $bg = self::state(self::lead(AnsiText::columnsFrom($text, $shade, 1)))['bg'];

        return (
            Glyphs::PANE_DIVIDER !== AnsiUtils::stripAnsiCodes($line) || null === $tint || $tint !== self::state(self::lead($rule->apply(Glyphs::PANE_DIVIDER)))['fg'] || null === $bg
                ? []
                : [['row' => $row, 'col' => $at, 'width' => 1, 'segment' => "\x1b[{$tint};".self::asBackground($bg).'m'.Glyphs::PANE_DIVIDER."\x1b[0m"]]
        );
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
     * @param ?Style                  $bar     the bars' style, whose half an edge row may hold instead of a rule
     *
     * @return list<array{row: int, col: int, width: int, segment: string}>
     */
    public static function openings(array $lines, WidgetRect $strip, array $floating, \Closure $logical, Style $rule, ?Style $bar = null): array
    {
        $tint = self::state(self::lead($rule->apply(Glyphs::FRAME_HORIZONTAL)))['fg'];
        $menuTint = self::barTint($bar);
        // A rule, its joins, or the divider itself running on past the strip.
        $joins = [Glyphs::FRAME_HORIZONTAL, Glyphs::PANE_DIVIDER, Glyphs::RULE_DOWN, Glyphs::RULE_UP, Glyphs::MODAL_TEE_LEFT, Glyphs::MODAL_TEE_RIGHT, Glyphs::RULE_CROSS];
        $glyph = static fn (int $row, int $col): string => \array_key_exists($row, $lines) ? AnsiUtils::stripAnsiCodes(AnsiText::columnsFrom($lines[$row], $col, 1)) : '';
        $halves = [Glyphs::HALF_UPPER, Glyphs::HALF_LOWER, Glyphs::HALF_BOTH];
        $spans = [];
        /** @var array<string, array{row: int, col: int, above?: string, below?: string, bar?: string}> $beside the cells over and under an opened one, the tab cells whose halves they take, and the bar's colour where it holds a bar's half */
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
                    || (null === $state['bg'] && !$state['reverse'])
                    || [] !== self::surfaces($floating, $row, $side)
                ) {
                    continue;
                }
                $spans[] = ['row' => $row, 'col' => $side, 'width' => 1, 'segment' => self::lead($cell)." \x1b[0m"];
                foreach ([[$row - 1, 'below'], [$row + 1, 'above']] as [$at, $whose]) {
                    $join = AnsiText::columnsFrom($lines[$at] ?? '', $side, 1);
                    $barTint = null === $menuTint ? null : self::barHalf($join, $menuTint);
                    $onBar = null !== $barTint;
                    if (!$onBar && (!\in_array($glyph($at, $side), $joins, strict: true) || $tint !== self::state(self::lead($join))['fg'])) {
                        continue;
                    }
                    $beside["{$at}:{$side}"] ??= ['row' => $at, 'col' => $side];
                    $beside["{$at}:{$side}"][$whose] = $cell;
                    if ($onBar) {
                        $beside["{$at}:{$side}"]['bar'] = $barTint;
                    }
                }
            }
        }
        foreach ($beside as $cell) {
            $segment = match (true) {
                !\array_key_exists('bar', $cell) => self::between($cell['above'] ?? null, $cell['below'] ?? null),
                \array_key_exists('below', $cell) => self::onBar($cell['below'], Glyphs::HALF_LOWER, $cell['bar']),
                default => self::onBar($cell['above'] ?? '', Glyphs::HALF_UPPER, $cell['bar']),
            };
            if (null !== $segment) {
                $spans[] = ['row' => $cell['row'], 'col' => $cell['col'], 'width' => 1, 'segment' => $segment];
            }
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
     * @param ?Style                     $bar      the bars' style, whose half an edge row may hold instead of a rule
     * @param list<array{WidgetRect, Style}> $heads the bars whose TOP row alone reaches out, each in its shade, into a bar's half only
     * @param list<int>                  $barRows  the rows a head may find a bar's half in, of any bar's colour
     *
     * @return list<array{row: int, col: int, width: int, segment: string}>
     */
    public static function spans(array $lines, array $rects, array $floating, \Closure $logical, Style $rule, ?Style $bar = null, array $heads = [], array $barRows = []): array
    {
        $menuTint = self::barTint($bar);
        $surface = static fn (int $row, int $col): array => self::surfaces($floating, $row, $col);
        $tint = self::state(self::lead($rule->apply(Glyphs::FRAME_HORIZONTAL)))['fg'];
        $top = static fn (WidgetRect $rect, ?Style $shade = null): array => [$rect, $rect->row, $rect->row - 1, Glyphs::HALF_LOWER, Glyphs::RULE_DOWN, $shade];
        $edges = \array_map(static fn (array $head): array => $top(...$head), $heads);
        foreach ($rects as $rect) {
            $edges[] = $top($rect);
            $edges[] = [$rect, $rect->row + $rect->rows - 1, $rect->row + $rect->rows, Glyphs::HALF_UPPER, Glyphs::RULE_UP, null];
        }
        // Each row's cells read once: sliced a cell at a time, a full-width
        // edge cost ~40ms a frame, every slice walking the row from its start.
        $cells = [];
        foreach ($edges as [, $edge, $line]) {
            foreach (\array_intersect_key($lines, [$edge => true, $line => true]) as $row => $text) {
                $cells[$row] ??= self::cells($text);
            }
        }
        $spans = [];
        foreach ($edges as [$rect, $edge, $line, $half, $join, $shade]) {
            if (!\array_key_exists($edge, $lines) || !\array_key_exists($line, $lines)) {
                continue;
            }
            for ($col = 0, $columns = AnsiUtils::visibleWidth($lines[$edge]); $col < $columns; ++$col) {
                $laid = $logical($edge, $col);
                if ($laid < $rect->col || $laid >= ($rect->col + $rect->columns)) {
                    continue;
                }
                // A rule, or a blank on nothing: the padding over a view
                // in a window, the room under a short list — or a bar's half.
                // Any other glyph joins nothing, so it is turned away before
                // the surfaces are asked and the codes read, cell by cell.
                $beside = $cells[$line][$col] ?? '';
                $glyph = AnsiUtils::stripAnsiCodes($beside);
                if (
                    !\in_array($glyph, [Glyphs::FRAME_HORIZONTAL, ' ', Glyphs::HALF_UPPER, Glyphs::HALF_LOWER], strict: true)
                    || !self::sameSurface($surface($line, $col), $surface($edge, $col), $floating, $rect)
                ) {
                    continue;
                }
                $state = self::state(self::lead($beside));
                $isRule = Glyphs::FRAME_HORIZONTAL === $glyph && $tint === $state['fg'];
                // A head's row over it holds a bar's half only where a bar draws one.
                $barTint = match (true) {
                    null !== $shade => \in_array($line, $barRows, strict: true) ? self::barHalf($beside) : null,
                    null !== $menuTint => self::barHalf($beside, $menuTint),
                    default => null,
                };
                $onBar = null !== $barTint;
                if (!$isRule && !$onBar && (' ' !== $glyph || null !== $state['bg'] || $state['reverse'])) {
                    continue;
                }
                // A head bar meets only another bar's half: a strip's baseline over it stays the strip's.
                if (null !== $shade && !$onBar) {
                    continue;
                }
                $cell = $shade?->apply(' ') ?? $cells[$edge][$col] ?? '';
                $segment = match (true) {
                    $onBar => self::onBar($cell, $half, $barTint), // a strip's unshaded `│` ends at the bar
                    Glyphs::FRAME_VERTICAL !== AnsiUtils::stripAnsiCodes($cell) => self::half($cell, $half),
                    $isRule => $rule->apply($join),
                    default => null, // a line meeting a blank joins nothing
                };
                if (null !== $segment) {
                    $spans[] = ['row' => $line, 'col' => $col, 'width' => 1, 'segment' => $segment];
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

    /**
     * $cell's shade as $half's colour on the bar's ($bar, a colour as
     * {@see state()} reads one): the bar's half, and the shaded row's beside
     * it in the same cell. Null for a cell with no shade — it keeps the bar's.
     */
    private static function onBar(string $cell, string $half, string $bar): ?string
    {
        $shade = self::state(self::lead($cell))['bg'];

        return null === $shade ? null : "\x1b[{$shade};".self::asBackground($bar)."m{$half}\x1b[0m";
    }

    /** The colour of a bar's half as {@see state()} reads it, or null for a bar with no shade. */
    private static function barTint(?Style $bar): ?string
    {
        return null === $bar ? null : self::state(self::lead(RowBleed::under(1, $bar)))['fg'];
    }

    /**
     * The colour of the bar's half $cell holds — `▀` or `▄` on nothing, in
     * $tint when one is asked for (the menu and status bars', where a strip's
     * own halves stand beside it), in any where the row is known to be a
     * bar's — as {@see state()} reads it, or null for none.
     */
    private static function barHalf(string $cell, ?string $tint = null): ?string
    {
        $state = self::state(self::lead($cell));
        $isHalf = null === $state['bg'] && !$state['reverse'] && \in_array(AnsiUtils::stripAnsiCodes($cell), [Glyphs::HALF_UPPER, Glyphs::HALF_LOWER], strict: true);

        return $isHalf && (null === $tint || $tint === $state['fg']) ? $state['fg'] : null;
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
     * Every column of $line as {@see AnsiText::columnsFrom()} would slice it
     * one at a time, for what this pass reads of a cell — its glyph and the
     * SGR state over it ({@see state()}) — in one walk of the row: the state
     * as one canonical run of codes, and a wide glyph, which no one-column
     * slice holds, as the state with no glyph and then a bare blank.
     *
     * @return list<string>
     */
    public static function cells(string $line): array
    {
        $cells = [];
        $state = self::PLAIN;
        foreach (AnsiUtils::walkCells($line) as $token) {
            if (0 === $token['width']) {
                $state = self::fold($state, $token['text']);
                continue;
            }
            if (2 === $token['width']) {
                // No glyph in one column: the state alone, then a bare blank.
                $cells[] = self::codes($state);
                $cells[] = ' ';
                continue;
            }
            $cells[] = self::codes($state).$token['text'];
        }

        return $cells;
    }

    /**
     * $state as the codes that set it, which {@see state()} — reading from
     * nothing set — reads back as $state.
     *
     * @param array{fg: ?string, bg: ?string, reverse: bool, dim: bool} $state
     */
    private static function codes(array $state): string
    {
        return (
            (null === $state['fg'] ? '' : "\x1b[{$state['fg']}m")
            .(null === $state['bg'] ? '' : "\x1b[".self::asBackground($state['bg']).'m')
            .($state['reverse'] ? "\x1b[7m" : '')
            .($state['dim'] ? "\x1b[2m" : '')
        );
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
        return self::fold(self::PLAIN, $sgr);
    }

    /**
     * $state with $sgr's codes applied over it, as {@see state()} reads them.
     *
     * @param array{fg: ?string, bg: ?string, reverse: bool, dim: bool} $state
     *
     * @return array{fg: ?string, bg: ?string, reverse: bool, dim: bool}
     */
    private static function fold(array $state, string $sgr): array
    {
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
