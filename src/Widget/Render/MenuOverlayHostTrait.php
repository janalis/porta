<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Support\AnsiText;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * Canonical {@see MenuOverlayHost} implementation: store the spans, repaint,
 * and composite them after the render, in {@see postRender()} — the vendor's
 * hook for exactly this, so no host's render() has to remember to.
 *
 * The compositing width must be the widget's own line width — padding to the
 * context's columns would blow out the reported width and starve neighbours.
 * That is the context's width for most hosts; one whose lines are narrower (a
 * resizable side panel, the one-column separator) says so in
 * {@see overlayWidth()}.
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
        // Identical spans must NOT invalidate (all three span setters): the
        // presenters re-deliver on every frame hook, so an unconditional
        // invalidate turns any visible overlay into a self-sustaining 60fps
        // repaint loop — invalidate → revision bump → next frame → re-deliver.
        if ($spans === $this->menuOverlay) {
            return;
        }
        $this->menuOverlay = $spans;
        $this->invalidate();
    }

    /**
     * @param list<array{row: int, col: int, width: int, segment: string}> $spans
     */
    public function setModalOverlay(array $spans): void
    {
        if ($spans === $this->modalOverlay) {
            return; // see setMenuOverlay — the repaint-loop guard
        }
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
        if ($spans === $this->toastOverlay) {
            return; // see setMenuOverlay — the repaint-loop guard
        }
        $this->toastOverlay = $spans;
        $this->invalidate();
    }

    /** @var list<array{row: int, col: int, width: int, segment: string}> the tooltip card's spans, above the toasts, under the menus */
    private array $tooltipOverlay = [];

    /**
     * @param list<array{row: int, col: int, width: int, segment: string}> $spans
     */
    public function setTooltipOverlay(array $spans): void
    {
        if ($spans === $this->tooltipOverlay) {
            return; // see setMenuOverlay — the repaint-loop guard
        }
        $this->tooltipOverlay = $spans;
        $this->invalidate();
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    #[\Override]
    public function postRender(array $lines, RenderContext $context): array
    {
        return $this->withMenuOverlay($lines, $this->overlayWidth($context));
    }

    /** The width this host's lines are drawn at: the context's, unless the host draws narrower. */
    protected function overlayWidth(RenderContext $context): int
    {
        return $context->getColumns();
    }

    /**
     * Composite the floating layers: the backdrop dim first (the pane's own
     * rows go faint), then the modal spans bright over it, then the toasts
     * (readable over a dimmed modal), then the tooltip card, then the menu
     * dropdowns brightest — an open menu always renders above everything.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    protected function withMenuOverlay(array $lines, int $width): array
    {
        if ($this->modalBackdrop) {
            $lines = \array_map(AnsiText::dim(...), $lines);
        }
        $lines = MenuOverlay::apply(MenuOverlay::apply($lines, $this->modalOverlay, $width), $this->toastOverlay, $width);
        $lines = MenuOverlay::apply($lines, $this->tooltipOverlay, $width);

        return MenuOverlay::apply($lines, $this->menuOverlay, $width);
    }
}
