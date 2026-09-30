<?php

declare(strict_types=1);

namespace Porta\Overlay\State;

use Porta\Container\AsIdeService;
use Porta\Widget\Render\MenuOverlay;
use Symfony\Component\Tui\Render\WidgetRect;

/**
 * The floating surfaces of a MIRRORED frame, on a canvas of their own.
 *
 * Left-to-right, a surface is composited into the panes it floats over, and
 * that is the whole story. Right-to-left it is not: the frame is reordered
 * after it is composed, and a surface is mirrored across the row while the
 * panes are reordered within their own spans. Composited into the panes, a
 * surface overwrote the pane text at its LOGICAL columns, and once it had been
 * moved to its mirrored ones that text was gone: an open File menu blanked the
 * left third of the editor. So a mirrored frame keeps its surfaces here
 * instead, in screen coordinates, and {@see \Porta\I18n\PaneReorder} stamps
 * them over panes that have lost nothing.
 *
 * The slots stack as the panes' do: modal, toast, tooltip, then the menus.
 * Each setter says whether anything changed, so the caller can ask for the
 * frame that a pane would have asked for by invalidating itself.
 */
#[AsIdeService]
final class FloatingLayer
{
    /** @var list<array{row: int, col: int, width: int, segment: string}> */
    private array $modal = [];

    /** @var list<array{row: int, col: int, width: int, segment: string}> */
    private array $toast = [];

    /** @var list<array{row: int, col: int, width: int, segment: string}> */
    private array $tooltip = [];

    /** @var list<array{row: int, col: int, width: int, segment: string}> */
    private array $menu = [];

    /** The canvas's rect for {@see \Porta\Overlay\Region\SpanSlicer}: the screen, at any size one can be. */
    public static function rect(): WidgetRect
    {
        return new WidgetRect(row: 0, col: 0, rows: \PHP_INT_MAX, columns: \PHP_INT_MAX);
    }

    /** @param list<array{row: int, col: int, width: int, segment: string}> $spans */
    public function setModal(array $spans): bool
    {
        [$changed, $this->modal] = [$spans !== $this->modal, $spans];

        return $changed;
    }

    /** @param list<array{row: int, col: int, width: int, segment: string}> $spans */
    public function setToast(array $spans): bool
    {
        [$changed, $this->toast] = [$spans !== $this->toast, $spans];

        return $changed;
    }

    /** @param list<array{row: int, col: int, width: int, segment: string}> $spans */
    public function setTooltip(array $spans): bool
    {
        [$changed, $this->tooltip] = [$spans !== $this->tooltip, $spans];

        return $changed;
    }

    /** @param list<array{row: int, col: int, width: int, segment: string}> $spans */
    public function setMenu(array $spans): bool
    {
        [$changed, $this->menu] = [$spans !== $this->menu, $spans];

        return $changed;
    }

    /**
     * The canvas for a $columns × $rows frame: blank wherever no surface is.
     * Only the cells a floating region claims are ever read from it.
     *
     * @return list<string>
     */
    public function rows(int $columns, int $rows): array
    {
        $lines = \array_fill(0, \max(0, $rows), \str_repeat(' ', \max(0, $columns)));
        foreach ([$this->modal, $this->toast, $this->tooltip, $this->menu] as $spans) {
            $lines = MenuOverlay::apply($lines, $spans, $columns);
        }

        return $lines;
    }
}
