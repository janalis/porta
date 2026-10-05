<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Support\AnsiText;
use Porta\Ui\Density;
use Porta\Ui\Role;
use Porta\Ui\ScreenDensity;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Porta\Widget\Render\RowBleed;
use Porta\Widget\Render\Rule;
use Porta\Widget\Support\StylesDefault;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The row across the workspace's edge: under the menu bar, above the status
 * bar. It holds the bar's half ({@see RowBleed}) — `▀` in the menu bar's
 * shade, `▄` in the status bar's — so each bar reads half a row taller and
 * the panes start where its shade stops. A theme whose bars have no shade
 * (monochrome) gets a `─` rule instead, so the bars still read as a frame's
 * top and bottom. A row of chrome, so it is drawn only where the screen is
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

    /**
     * What the last render drew for — the density, and the bar's half and the
     * rule in the theme then in force — so a flip or a theme switch repaints:
     * the edge rows are no chrome root, which the switch's sweep clears.
     */
    private ?string $drawn = null;

    /** Whether the last render drew the bar's half, not the rule. */
    private bool $barHalf = false;

    public function __construct(
        private readonly ScreenDensity $density,
        private readonly WorkspaceEdge $edge,
        ?Styles $styles = null,
    ) {
        $this->initStyles($styles);
    }

    #[\Override]
    public function beforeRender(): void
    {
        if (null !== $this->drawn && $this->drawn !== $this->drawnFor()) {
            $this->invalidate();
        }
    }

    #[\Override]
    public function render(RenderContext $context): array
    {
        $this->drawn = $this->drawnFor();
        $this->barHalf = false;
        if (Density::Compact === $this->density->current()) {
            return [];
        }
        $columns = $context->getColumns();
        $bar = $this->styles->of(Role::HeaderBar);
        $half = WorkspaceEdge::Top === $this->edge ? RowBleed::under($columns, $bar) : RowBleed::over($columns, $bar);
        $this->barHalf = $half !== AnsiText::blank($columns);

        return [$this->barHalf ? $half : Rule::horizontal($columns, $this->styles)];
    }

    private function drawnFor(): string
    {
        return $this->density->current()->name.RowBleed::under(1, $this->styles->of(Role::HeaderBar)).Rule::horizontal(1, $this->styles);
    }

    /** Whether the row as last drawn holds its bar's half — the half a pointer there is over. */
    public function holdsBarHalf(): bool
    {
        return $this->barHalf;
    }
}
