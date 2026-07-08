<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * A one-column vertical divider between the sidebar and the editor pane. It looks
 * like blank space, but being a real widget it can receive floating-menu overlay
 * spans — so a context menu that spills across the sidebar boundary paints solidly
 * over this column instead of leaving a hole.
 */
final class SeparatorWidget extends AbstractWidget implements MenuOverlayHost, VerticallyExpandableInterface
{
    use FillsPaneTrait;
    use MenuOverlayHostTrait;

    public function render(RenderContext $context): array
    {
        $rows = max(1, $context->getRows() > 0 ? $context->getRows() : 1);
        $lines = array_fill(0, $rows, ' ');

        return $this->withMenuOverlay($lines, 1);
    }
}
