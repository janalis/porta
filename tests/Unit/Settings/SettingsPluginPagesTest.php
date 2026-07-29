<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Porta\I18n\Translator;
use Porta\Settings\SettingsPluginPages;
use Porta\Tests\Support\RendersWidget;
use Porta\Widget\Settings\SettingsNavWidget;
use Symfony\Component\Tui\Render\RenderContext;

/** The seam that keeps the plugin lifecycle out of Widget/. */
final class SettingsPluginPagesTest extends TestCase
{
    use RendersWidget;

    public function testTheInstalledSetBecomesNavSubItems(): void
    {
        $nav = new SettingsNavWidget(new Translator('en'));
        $pages = new SettingsPluginPages($nav);

        $pages->refresh(['phpstan', 'mago']);

        $screen = $this->plain($nav->render(new RenderContext(24, 24)));
        $this->assertStringContainsString('   phpstan', $screen);
        $this->assertStringContainsString('   mago', $screen);

        $pages->refresh(['phpstan']);
        $screen = $this->plain($nav->render(new RenderContext(24, 24)));
        $this->assertStringNotContainsString('mago', $screen, 'a removed plugin loses its page');
    }
}
