<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Ui\BidiTui;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * The presenter tests' render rig: the trait owns the tui/renderer pair the
 * test builds in its setup, and {@see frame()} records the attached panes'
 * rects so the presenter has bounds to lay out against — getWidgetRect is
 * null until a real Renderer pass has run.
 */
trait RendersRoot
{
    private BidiTui $tui;

    private Renderer $renderer;

    /** Record the attached panes' rects so bounds exist. */
    private function frame(int $columns = 100, int $rows = 24): void
    {
        $root = new \ReflectionProperty(Tui::class, 'root')->getValue($this->tui);
        \assert($root instanceof ContainerWidget);
        $this->renderer->render($root, $columns, $rows);
    }
}
