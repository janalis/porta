<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Widget\OverlayWidget;
use Symfony\Component\Tui\Render\RenderContext;

/** The smallest concrete overlay: open() is the protected markOpen(), and it renders nothing. */
final class TestOverlayWidget extends OverlayWidget
{
    public function open(): void
    {
        $this->markOpen();
    }

    public function render(RenderContext $context): array
    {
        return [];
    }

    public function handleInput(string $data): void {}
}
