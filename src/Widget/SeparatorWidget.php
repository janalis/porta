<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Widget\Render\MenuOverlay;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * A one-column vertical divider between the sidebar and the editor pane. It looks
 * like blank space, but being a real widget it can receive floating-menu overlay
 * spans — so a context menu that spills across the sidebar boundary paints solidly
 * over this column instead of leaving a hole.
 */
final class SeparatorWidget extends AbstractWidget implements VerticallyExpandableInterface
{
    /** @var list<array{row: int, col: int, width: int, segment: string}> floating menu spans to overlay */
    private array $menuOverlay = [];

    /**
     * @param list<array{row: int, col: int, width: int, segment: string}> $spans
     */
    public function setMenuOverlay(array $spans): void
    {
        $this->menuOverlay = $spans;
        $this->invalidate();
    }

    public function isVerticallyExpanded(): bool
    {
        return true;
    }

    public function expandVertically(bool $expand): static
    {
        return $this; // always fills its pane height
    }

    public function render(RenderContext $context): array
    {
        $rows = max(1, $context->getRows() > 0 ? $context->getRows() : 1);
        $lines = array_fill(0, $rows, ' ');

        return MenuOverlay::apply($lines, $this->menuOverlay, 1);
    }
}
