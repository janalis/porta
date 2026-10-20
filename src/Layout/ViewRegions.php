<?php

declare(strict_types=1);

namespace Porta\Layout;

use Porta\Container\AsEager;
use Porta\Container\AsIdeService;
use Porta\Ui\FrameHooks;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * Mints a {@see ViewRegion} on demand, and remembers it.
 *
 * These used to be nine compile-time artefacts: six `porta.widget.*` service
 * definitions built from {@see WorkspaceLayout} getters, reached through
 * `#[LayoutSlot]`. That works only for a view core knows about at compile time,
 * and the container is built and cached before any plugin PHP runs — so a
 * plugin-owned view could not be handed its slots under any naming. Minting is
 * the only route, and once the region is minted the nine artefacts have nothing
 * left to describe.
 *
 * Idempotent by id: the view that owns a region and the layout that arranges it
 * both ask for it, and neither should have to care which came first.
 */
#[AsEager]
#[AsIdeService]
final class ViewRegions
{
    /** @var array<string, ViewRegion> */
    private array $regions = [];

    public function __construct(
        private readonly Styles $styles,
        private readonly ?Renderer $renderer = null,
        private readonly ?Tui $tui = null,
        ?FrameHooks $frames = null,
    ) {
        // One hook for every split, read through the regions minted NOW: a
        // hook per split would outlive its region's forget(), and with it the
        // retracted view's whole widget graph, once per disable.
        $frames?->onBeforeFrame($this->fitSplits(...));
    }

    private function fitSplits(): void
    {
        foreach ($this->regions as $region) {
            foreach ($region->splits() as $split) {
                $split->fit();
            }
        }
    }

    /** The region for $id, created on first ask. */
    public function mint(string $id): ViewRegion
    {
        return $this->regions[$id] ??= new ViewRegion(
            $id,
            LayoutWidgets::container($id.'-area', new Style(direction: Direction::Horizontal, gap: 0)),
            LayoutWidgets::container($id.'-left', new Style(flex: 0)),
            LayoutWidgets::separator($this->styles, $id.'-separator'),
            LayoutWidgets::container($id.'-content', new Style(flex: 1)),
            $this->split(...),
            self::header($id),
        );
    }

    /** A region's split; {@see fitSplits()} fits it to its area before each frame, like the preview's. */
    private function split(string $id, NarrowSplit $whenNarrow, int $share, int $minSide): PaneSplit
    {
        return new PaneSplit($id, $this->styles, $whenNarrow, $this->renderer, $this->tui, $share, $minSide);
    }

    /** The region's header row: as tall as what it holds, never stretched to the window. */
    private static function header(string $id): ContainerWidget
    {
        $header = new ContainerWidget();
        $header->setId($id.'-header');
        $header->setStyle(new Style(flex: 0));

        return $header;
    }

    /**
     * Drop a retracted view's region. The next {@see mint()} of the same id
     * builds FRESH widgets — which is what makes disable→re-enable safe: a
     * reused region's containers would still hold the dead view's children.
     */
    public function forget(string $id): void
    {
        unset($this->regions[$id]);
    }

    /** The region for $id if one has been minted, else null — no minting side effect. */
    public function find(string $id): ?ViewRegion
    {
        return $this->regions[$id] ?? null;
    }

    /**
     * Every minted region, in mint order.
     *
     * @return list<ViewRegion>
     */
    public function all(): array
    {
        return \array_values($this->regions);
    }
}
