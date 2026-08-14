<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Support\AnsiText;

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

    /** Whether this pane renders faint beneath the modal spans (the Welcome modal's backdrop). */
    private bool $modalBackdrop = false;

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

    // @mago-expect lint:no-boolean-flag-parameter -- the bool IS the domain here, not a hidden second responsibility.
    public function setModalBackdrop(bool $on): void
    {
        if ($on === $this->modalBackdrop) {
            return;
        }
        $this->modalBackdrop = $on;
        $this->invalidate();
    }

    /** @var list<array{row: int, col: int, width: int, segment: string}> floating toast spans, above the modal, under the menus */
    private array $toastOverlay = [];

    /**
     * @param list<array{row: int, col: int, width: int, segment: string}> $spans
     */
    public function setToastOverlay(array $spans): void
    {
        $this->toastOverlay = $spans;
        $this->invalidate();
    }

    /**
     * Composite the floating layers: the backdrop dim first (the pane's own
     * rows go faint), then the modal spans bright over it, then the toasts
     * (readable over a dimmed modal), then the menu dropdowns brightest — an
     * open menu always renders above everything.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    protected function withMenuOverlay(array $lines, int $width): array
    {
        if ($this->modalBackdrop) {
            $lines = array_map(AnsiText::dim(...), $lines);
        }
        $lines = MenuOverlay::apply(MenuOverlay::apply($lines, $this->modalOverlay, $width), $this->toastOverlay, $width);

        return MenuOverlay::apply($lines, $this->menuOverlay, $width);
    }
}
