<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Support;

use PHPUnit\Framework\TestCase;
use Porta\Ui\Theme\LightTheme;
use Porta\Ui\Theme\Styles;
use Porta\Widget\BreadcrumbsWidget;
use Symfony\Component\Tui\Render\RenderContext;

final class StylesDefaultTest extends TestCase
{
    public function testAnExplicitlyInjectedPaletteIsUsedInsteadOfTheDarkDefault(): void
    {
        // BreadcrumbsWidget composes StylesDefault; injecting a Light theme
        // must actually take effect, not be silently replaced by Styles::dark().
        $bar = new BreadcrumbsWidget(new Styles(LightTheme::definition()));
        $bar->setProvider(static fn (): array => [['label' => 'App', 'line' => 0]]);

        $line = $bar->render(new RenderContext(20, 1))[0];

        $this->assertStringContainsString("\x1b[38;5;16m", $line, 'the Light theme\'s Emphasis color (16) must appear');
        $this->assertStringNotContainsString("\x1b[38;5;255m", $line, 'the Dark theme default (255) must not appear once a palette is injected');
    }
}
