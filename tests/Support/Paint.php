<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * A widget's lines as a frame shows them: render(), then the Renderer's
 * postRender() — where the floating menus, modals and toasts composite. A
 * bare render() has none of them. Static, so the E2e steps' static closures
 * reach it too.
 */
final class Paint
{
    /** @return list<string> */
    public static function lines(AbstractWidget $widget, RenderContext $context): array
    {
        return new Renderer()
            ->renderWidgetLines($widget, $context)
            ->toArray();
    }
}
