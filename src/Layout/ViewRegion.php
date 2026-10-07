<?php

declare(strict_types=1);

namespace Porta\Layout;

use Porta\Widget\SeparatorWidget;
use Symfony\Component\Tui\Style\Padding;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * One view's content: a sidebar, a drag separator and a content pane, inside
 * the horizontal area its window holds while the view is open
 * ({@see \Porta\Workbench\View\ViewWindow}).
 *
 * Both sides are containers, which is what makes the shape general. The two
 * views core shipped were mirror images of each other — git had a container
 * sidebar it refilled per mode and a widget for content, settings had a widget
 * sidebar and a container it refilled per section — so neither could describe
 * the other. Here each view adds its fixed half once and refills the other.
 *
 * Minted at runtime by {@see ViewRegions}, never compiled. A container built
 * before any plugin loads cannot hand a plugin-owned view its slots.
 */
final class ViewRegion
{
    /**
     * The sidebar as ONE resizable unit.
     *
     * A region's sidebar is a container of stacked widgets that each render
     * their own width, so a drag on the separator has to fan the new width out
     * to all of them. Only the view knows which widgets those are — core sees
     * a container — so the view supplies the unit and the layout controller
     * resizes whatever it was given.
     */
    private ?ResizableSidebar $panes = null;

    private bool $flushTop = false;

    public function __construct(
        public readonly string $id,
        private readonly ContainerWidget $area,
        private readonly ContainerWidget $sidebar,
        private readonly SeparatorWidget $separator,
        private readonly ContainerWidget $content,
    ) {}

    /** The view declares how its sidebar resizes; without this the column is fixed. */
    public function setSidebarPanes(ResizableSidebar $panes): void
    {
        $this->panes = $panes;
    }

    public function sidebarPanes(): ?ResizableSidebar
    {
        return $this->panes;
    }

    /**
     * The content pane takes the window's top padding row, flush under the
     * title bar, while the sidebar and the separator keep it as their inset —
     * a pane whose first row is a toolbar reads as the window's own bar.
     *
     * A region-level opt-in because the padding is the frame's, drawn by core:
     * the pane cannot reach it from inside its own rect. Git's diff pane is
     * the one caller.
     */
    public function flushContentTop(): void
    {
        $this->flushTop = true;
        foreach ([$this->sidebar, $this->separator] as $inset) {
            $inset->setStyle(($inset->getStyle() ?? new Style())->withPadding(new Padding(1, 0, 0, 0)));
        }
    }

    /** Whether the window renders this region from its top padding row ({@see flushContentTop()}). */
    public function contentFlushTop(): bool
    {
        return $this->flushTop;
    }

    /** The horizontal area the view window holds; the view never fills this itself. */
    public function area(): ContainerWidget
    {
        return $this->area;
    }

    /** The left column. */
    public function sidebar(): ContainerWidget
    {
        return $this->sidebar;
    }

    /** The drag handle between the two, hit-tested by the pointer router. */
    public function separator(): SeparatorWidget
    {
        return $this->separator;
    }

    /** The right pane. */
    public function content(): ContainerWidget
    {
        return $this->content;
    }

    /**
     * The area's children in canonical LTR order; {@see PaneLayout} mirrors
     * them for an RTL locale.
     *
     * @return list<AbstractWidget>
     */
    public function panes(): array
    {
        return [$this->sidebar, $this->separator, $this->content];
    }
}
