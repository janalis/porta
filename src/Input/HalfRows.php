<?php

declare(strict_types=1);

namespace Porta\Input;

use Porta\Container\AsIdeService;
use Porta\Layout\WorkspaceLayout;
use Porta\Overlay\Presenter\RuleHalves;
use Porta\Overlay\Presenter\ViewWindowPresenter;
use Porta\Overlay\Region\DropdownRegion;
use Porta\Overlay\Region\FloatingRegions;
use Porta\Ui\BidiTui;
use Porta\Ui\Density;
use Porta\Ui\ScreenDensity;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\Settings\SettingsNavWidget;
use Porta\Widget\Support\TabBarPosition;
use Porta\Widget\TabBarWidget;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * A half row is the item's it is drawn for. On a roomy screen a list's blank
 * gap holds the lower half of the entry above it and the upper half of the
 * one below ({@see \Porta\Widget\Render\RowBleed}), and the rule or padding
 * beside a strip's or the settings nav's outer row holds that row's other
 * half ({@see RuleHalves}). So a pointer in a gap's upper half is over the
 * entry above, in its lower half over the one below, and in the lower half of
 * the rule over the tab strip over the strip. The row under the menu bar and
 * the one over the status bar hold that bar's half
 * ({@see \Porta\Widget\EdgeRuleWidget}): a pointer there is over the bar.
 *
 * Resolved here, once, by moving the event's row to the item's before any
 * router sees it: every hover and click path stays as it was. Only a pixel
 * report knows its half ({@see PixelPointer}); a cell report, and every report
 * on a compact screen, where no row is split, pass through unchanged — a gap
 * is then no item, as it always was.
 */
#[AsIdeService]
final readonly class HalfRows
{
    public function __construct(
        private PixelPointer $pointer,
        private RuleHalves $halves,
        private Renderer $renderer,
        private FileTreeWidget $tree,
        private TabBarWidget $tabBar,
        private ViewRegistry $views,
        private FloatingRegions $floating,
        private ScreenDensity $density,
        private BidiTui $bidi,
        private ViewWindowPresenter $window,
        private WorkspaceLayout $layout,
    ) {}

    /** $mouse in cells, on the row of the item its half belongs to. */
    public function resolve(MouseEvent $mouse): MouseEvent
    {
        $mouse = $this->pointer->cells($mouse);
        if (null === $mouse->lowerHalf || Density::Roomy !== $this->density->current() || $this->floatsOver($mouse)) {
            return $mouse;
        }
        $col = $this->bidi->logicalColumn($mouse->row, $mouse->col);
        $step = $mouse->lowerHalf ? 1 : -1;
        foreach ($this->lists() as [$widget, $shows]) {
            $rect = $this->renderer->getWidgetRect($widget);
            if (null === $rect || !$rect->contains($mouse->row, $col)) {
                continue;
            }
            $row = $mouse->row - $rect->row;

            // A gap: the half belongs to the entry on its side of the gap.
            return !$shows($row, $col - $rect->col) && $shows($row + $step, $col - $rect->col) ? $mouse->withRow($mouse->row + $step) : $mouse;
        }

        return $this->intoEdge($mouse, $col, $step);
    }

    /**
     * Whether a floating surface other than the open view's window — a menu,
     * a toast, a tooltip, a picker — is under the pointer: the half rows
     * behind it are not what the pointer is over, and the surface's own rows
     * are whole. A menu over the tree's gaps lost every item on one.
     */
    private function floatsOver(MouseEvent $mouse): bool
    {
        // The window's regions are minted afresh on each ask: compared by where they stand.
        $where = static fn (DropdownRegion $region): string => "{$region->top}:{$region->col}:{$region->width}:{$region->count}";
        $window = \array_map($where, $this->window->regions());
        $floating = $this->floating->regions();

        return \array_any(RuleHalves::surfaces($floating, $mouse->row, $mouse->col), static fn (int $key): bool => !\in_array($where($floating[$key]), $window, strict: true));
    }

    /**
     * A half in the rule or padding beside a strip's or the nav's outer row:
     * that row's. A horizontal strip's other side is its own baseline, so
     * only the side away from the editor counts there.
     */
    private function intoEdge(MouseEvent $mouse, int $col, int $step): MouseEvent
    {
        $floating = $this->floating->regions();
        foreach ($this->halves->edgeWidgets() as $widget) {
            $rect = $this->renderer->getWidgetRect($widget);
            if (null === $rect || $col < $rect->col || $col >= ($rect->col + $rect->columns) || $this->inward($widget, $step)) {
                continue;
            }
            $edge = 1 === $step ? $rect->row : $rect->row + $rect->rows - 1;
            if ($mouse->row !== ($edge - $step)) {
                continue;
            }
            if (RuleHalves::sameSurface(RuleHalves::surfaces($floating, $mouse->row, $mouse->col), RuleHalves::surfaces($floating, $edge, $mouse->col), $floating, $rect)) {
                return $mouse->withRow($edge);
            }
        }

        return $this->intoHead($mouse, $col, $step);
    }

    /**
     * A half a head bar holds — the navigation bar, a page's header: the
     * lower half of the row over it where that row is another bar's half
     * (as {@see RuleHalves} draws it only there), and the upper half of its
     * own row under it. Both are the bar's first row.
     */
    private function intoHead(MouseEvent $mouse, int $col, int $step): MouseEvent
    {
        $floating = $this->floating->regions();
        $heads = [];
        foreach ($this->halves->headWidgets() as [$widget]) {
            $rect = $this->renderer->getWidgetRect($widget);
            if (null !== $rect && $rect->rows > 0) {
                $heads[] = $rect;
            }
        }
        $halves = $this->halves->barRows();
        foreach ($heads as $rect) {
            if ($col < $rect->col || $col >= ($rect->col + $rect->columns) || $mouse->row !== ($rect->row - $step) || 1 === $step && !\in_array($mouse->row, $halves, strict: true)) {
                continue;
            }
            if (RuleHalves::sameSurface(RuleHalves::surfaces($floating, $mouse->row, $mouse->col), RuleHalves::surfaces($floating, $rect->row, $mouse->col), $floating, $rect)) {
                return $mouse->withRow($rect->row);
            }
        }

        return $this->intoBar($mouse, $step);
    }

    /**
     * A half the menu bar or the status bar holds in the row beside it: the
     * upper half of the row under the menu bar, the lower half of the one
     * over the status bar — that bar's.
     */
    private function intoBar(MouseEvent $mouse, int $step): MouseEvent
    {
        foreach ([[$this->layout->topRule, -1], [$this->layout->bottomRule, 1]] as [$rule, $toward]) {
            $rect = $this->renderer->getWidgetRect($rule);
            if ($step === $toward && $mouse->row === $rect?->row && $rule->holdsBarHalf()) {
                return $mouse->withRow($mouse->row + $step);
            }
        }

        return $mouse;
    }

    /**
     * Whether a step of $step reaches $widget from its editor side: the tab
     * strip behind an open view, or a horizontal strip from its baseline's.
     */
    private function inward(AbstractWidget $widget, int $step): bool
    {
        if ($widget !== $this->tabBar) {
            return false;
        }

        return match ($this->tabBar->position()) {
            TabBarPosition::Top => $this->views->isAnyOpen() || 1 !== $step,
            TabBarPosition::Bottom => $this->views->isAnyOpen() || -1 !== $step,
            default => $this->views->isAnyOpen(),
        };
    }

    /**
     * The lists with gaps, each with what says a row of it shows an entry:
     * the tree and a side tab strip unless a view is open over them, and the
     * open view's settings nav.
     *
     * @return list<array{AbstractWidget, \Closure(int, int): bool}>
     */
    private function lists(): array
    {
        $lists = [];
        if (!$this->views->isAnyOpen()) {
            $tree = $this->tree;
            $lists[] = [$tree, static fn (int $row, int $col): bool => null !== $tree->nodeAtRow($row)];
        }
        if (!$this->views->isAnyOpen() && $this->tabBar->position()->isSide()) {
            $tabs = $this->tabBar;
            $lists[] = [$tabs, static fn (int $row, int $col): bool => null !== $tabs->tabIndexAt($row, $col)];
        }
        foreach ($this->halves->edgeWidgets() as $widget) {
            if (!$widget instanceof SettingsNavWidget) {
                continue;
            }
            $lists[] = [$widget, static fn (int $row, int $col): bool => $widget->hasEntryAt($row)];
        }

        return $lists;
    }
}
