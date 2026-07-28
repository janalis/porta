<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The presenter tests' shared fake pane: records what lands in BOTH overlay
 * span slots (modal and toast) plus the backdrop flag, and renders a
 * rows×columns block of dots so a real Renderer pass gives it a rect for the
 * delivery bounds to lay out against. The backdrop still routes through the
 * real trait, and render composites through withMenuOverlay, so a test can
 * also assert the faint dim the way WelcomeModalControllerTest does.
 */
final class TestOverlayPane extends AbstractWidget implements MenuOverlayHost
{
    use MenuOverlayHostTrait {
        setModalBackdrop as private traitSetModalBackdrop;
    }

    /** @var list<array{row: int, col: int, width: int, segment: string}> the last pushed modal spans */
    public array $modalSpans = [];

    /** @var list<array{row: int, col: int, width: int, segment: string}> the last pushed toast spans */
    public array $toastSpans = [];

    public bool $backdrop = false;

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

    public function render(RenderContext $context): array
    {
        return $this->withMenuOverlay(array_fill(0, $context->getRows(), str_repeat('.', $context->getColumns())), $context->getColumns());
    }

    public function handleInput(string $data): void {}
}
