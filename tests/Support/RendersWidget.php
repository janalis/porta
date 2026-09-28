<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * ANSI-stripped views of rendered widget lines — the scrape primitive the
 * widget suites re-declared per file. {@see plain()} joins into one
 * searchable text block; {@see rows()} keeps the per-row grid for
 * row-indexed assertions.
 */
trait RendersWidget
{
    /** @param list<string> $lines */
    private function plain(array $lines): string
    {
        return \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $lines));
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function rows(array $lines): array
    {
        return \array_map(AnsiUtils::stripAnsiCodes(...), $lines);
    }

    /**
     * The widget's lines as a frame shows them: render(), then the Renderer's
     * postRender() — where the floating menus, modals and toasts composite.
     * A bare render() has none of them.
     *
     * @return list<string>
     */
    private function painted(AbstractWidget $widget, RenderContext $context): array
    {
        return Paint::lines($widget, $context);
    }
}
