<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The presenter tests' shared fake pane: records what lands in the overlay
 * span slots (modal, toast and tooltip) plus the backdrop flag, and renders a
 * rows×columns block of dots so a real Renderer pass gives it a rect for the
 * delivery bounds to lay out against. The backdrop still routes through the
 * real trait, and render composites through withMenuOverlay, so a test can
 * also assert the faint dim the way WelcomeModalControllerTest does.
 */
final class TestOverlayPane extends AbstractWidget implements MenuOverlayHost
{
    use MenuOverlayHostTrait {
        setModalBackdrop as private traitSetModalBackdrop;
        setWindowBackdrop as private traitSetWindowBackdrop;
    }

    /** @var list<array{row: int, col: int, width: int, segment: string}> the last pushed window spans */
    public array $windowSpans = [];

    public bool $windowDimmed = false;

    /** @var list<array{row: int, col: int, width: int, segment: string}> the last pushed modal spans */
    public array $modalSpans = [];

    /** @var list<array{row: int, col: int, width: int, segment: string}> the last pushed toast spans */
    public array $toastSpans = [];

    /** @var list<array{row: int, col: int, width: int, segment: string}> the last pushed tooltip spans */
    public array $tooltipSpans = [];

    public bool $backdrop = false;

    public function setWindowOverlay(array $spans): void
    {
        $this->windowSpans = $spans;
    }

    public function setWindowBackdrop(bool $on): void
    {
        $this->windowDimmed = $on;
        $this->traitSetWindowBackdrop($on);
    }

    public function setModalOverlay(array $spans): void
    {
        $this->modalSpans = $spans;
    }

    public function setModalBackdrop(bool $on): void
    {
        $this->backdrop = $on;
        $this->traitSetModalBackdrop($on);
    }

    public function setToastOverlay(array $spans): void
    {
        $this->toastSpans = $spans;
    }

    public function setTooltipOverlay(array $spans): void
    {
        $this->tooltipSpans = $spans;
    }

    public function render(RenderContext $context): array
    {
        $columns = \max(0, $context->getColumns());

        return $this->withMenuOverlay(\array_fill(0, \max(0, $context->getRows()), \str_repeat('.', $columns)), $columns);
    }

    public function handleInput(string $data): void {}
}
