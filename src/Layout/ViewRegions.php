<?php

declare(strict_types=1);

namespace Porta\Layout;

use Porta\Container\AsIdeService;
use Porta\Ui\Theme\Styles;
use Porta\Widget\SeparatorWidget;
use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Style\Style;
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
#[AsIdeService]
final class ViewRegions
{
    /** @var array<string, ViewRegion> */
    private array $regions = [];

    public function __construct(
        private readonly Styles $styles,
    ) {}

    /** The region for $id, created on first ask. */
    public function mint(string $id): ViewRegion
    {
        return $this->regions[$id] ??= new ViewRegion(
            $id,
            $this->container($id.'-area', new Style(direction: Direction::Horizontal, gap: 0)),
            $this->container($id.'-left', new Style(flex: 0)),
            $this->separator($id.'-separator'),
            $this->container($id.'-content', new Style(flex: 1)),
        );
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
        return array_values($this->regions);
    }

    private function container(string $id, Style $style): ContainerWidget
    {
        $container = new ContainerWidget();
        $container->setId($id);
        $container->setStyle($style);
        $container->expandVertically(true);

        return $container;
    }

    private function separator(string $id): SeparatorWidget
    {
        $separator = new SeparatorWidget($this->styles);
        $separator->setId($id)->setStyle(new Style(flex: 0));

        return $separator;
    }
}
