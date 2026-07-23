<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Porta\Widget\Support\FillsPaneTrait;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * The one-column draggable divider between a sidebar and its pane: a thin rule
 * at rest, heavy under the pointer or a drag.
 *
 * The affordance is the glyph, not the shade. Every boundary here is
 * resizable, and all of them used to render blank space — undiscoverable, and
 * a hover tint alone would not have fixed it: the monochrome theme resolves
 * the hover roles to nothing, so `--no-ansi` would be back to invisible.
 * Thin-vs-heavy still reads there.
 *
 * Being a real widget also lets it receive floating-menu overlay spans, so a
 * context menu spilling across the boundary paints solidly over this column
 * instead of leaving a hole.
 */
final class SeparatorWidget extends AbstractWidget implements MenuOverlayHost, VerticallyExpandableInterface
{
    use FillsPaneTrait;
    use MenuOverlayHostTrait;

    /** True while the pointer is on this column, or dragging it. */
    private bool $hovered = false;

    private readonly Styles $styles;

    public function __construct(?Styles $styles = null)
    {
        $this->styles = $styles ?? Styles::dark();
    }

    /** The pointer is on the divider (or dragging it); returns whether it changed. */
    public function hover(): bool
    {
        if ($this->hovered) {
            return false;
        }
        $this->hovered = true;
        $this->invalidate();

        return true;
    }

    /** The pointer left the divider; returns whether it changed. */
    public function clearHover(): bool
    {
        if (!$this->hovered) {
            return false;
        }
        $this->hovered = false;
        $this->invalidate();

        return true;
    }

    public function render(RenderContext $context): array
    {
        $rows = max(1, $context->getRows() > 0 ? $context->getRows() : 1);
        // A drag renders as a hover deliberately: the panes are already moving,
        // so a third state would say nothing the resize itself does not.
        // The hover face carries the accent — the modal resize-corner's
        // hot-affordance convention, so every grabbable edge speaks it.
        $glyph = $this->hovered
            ? $this->styles->merge(Role::BarHoverBg, Role::TabAccent)->apply(Glyphs::PANE_DIVIDER_ACTIVE)
            : $this->styles->of(Role::Placeholder)->apply(Glyphs::PANE_DIVIDER);
        $lines = array_fill(0, $rows, value: $glyph);

        return $this->withMenuOverlay($lines, 1);
    }
}
