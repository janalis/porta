<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Ui\Density;
use Porta\Ui\ScreenDensity;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Porta\Widget\Render\Rule;
use Porta\Widget\Support\StylesDefault;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * A rule across the workspace's edge: under the menu bar, above the status
 * bar, so the bars read as a frame's top and bottom rather than as bands the
 * panes run into. A row of chrome, so it is drawn only where the screen is
 * roomy; on a compact one it takes no row at all.
 *
 * Its own widget, not a row inside the bars: the bars keep their one row for
 * hit-testing, and a floating menu composites over this row like over any
 * pane — the app menu's dropdown hangs from the bar across it.
 */
final class EdgeRuleWidget extends AbstractWidget implements MenuOverlayHost
{
    use MenuOverlayHostTrait;
    use StylesDefault;

    /** The density the last render drew for, so a flip repaints. */
    private ?Density $drawn = null;

    public function __construct(
        private readonly ScreenDensity $density,
        ?Styles $styles = null,
    ) {
        $this->initStyles($styles);
    }

    #[\Override]
    public function beforeRender(): void
    {
        if (null !== $this->drawn && $this->drawn !== $this->density->current()) {
            $this->invalidate();
        }
    }

    #[\Override]
    public function render(RenderContext $context): array
    {
        $this->drawn = $this->density->current();
        if (Density::Compact === $this->drawn) {
            return [];
        }
        $columns = $context->getColumns();

        return [Rule::horizontal($columns, $this->styles)];
    }
}
