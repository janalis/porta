<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

/**
 * Canonical {@see MenuOverlayHost} implementation: store the spans, repaint,
 * and composite them as the render's final step via {@see withMenuOverlay}.
 * The compositing width must be the widget's own line width — padding to the
 * context's columns would blow out the reported width and starve neighbours.
 */
trait MenuOverlayHostTrait
{
    /** @var list<array{row: int, col: int, width: int, segment: string}> floating dropdown spans to overlay */
    private array $menuOverlay = [];

    /** @var list<array{row: int, col: int, width: int, segment: string}> floating modal spans, under the dropdowns */
    private array $modalOverlay = [];

    /** Satisfied by AbstractWidget: repaint after the spans change. */
    abstract public function invalidate(): void;

    /**
     * @param list<array{row: int, col: int, width: int, segment: string}> $spans
     */
    public function setMenuOverlay(array $spans): void
    {
        $this->menuOverlay = $spans;
        $this->invalidate();
    }

    /**
     * @param list<array{row: int, col: int, width: int, segment: string}> $spans
     */
    public function setModalOverlay(array $spans): void
    {
        $this->modalOverlay = $spans;
        $this->invalidate();
    }

    /**
     * Composite the floating layers: the modal first, then the menu dropdowns
     * over it, so an open menu always renders above the modal it may overlap.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    protected function withMenuOverlay(array $lines, int $width): array
    {
        return MenuOverlay::apply(MenuOverlay::apply($lines, $this->modalOverlay, $width), $this->menuOverlay, $width);
    }
}
