<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Container;

use PHPUnit\Framework\TestCase;
use Porta\Config\IdeConfig;
use Porta\Container\Ansi;
use Porta\Container\IdeContainerFactory;
use Porta\Widget\ContextMenu;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\DockTabsWidget;
use Porta\Widget\FindBarWidget;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\TerminalPanelWidget;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The chrome roots ChromeInvalidator sweeps on a live theme/locale/cursor/
 * tab-position switch declare themselves with #[AsChromeRoot] (plus the one
 * getter service porta.widget.main tagged at its def). This test builds the
 * container and asserts the `porta.chrome_root` tagged set is exactly the
 * expected roots, so dropping the attribute from a widget — which would
 * silently stop that widget recoloring on a theme switch — fails loudly here.
 */
final class ChromeRootMembershipTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        foreach (['PORTA_CACHE_DIR', 'PORTA_CONTAINER_CACHE'] as $key) {
            $this->savedEnv[$key] = getenv($key);
        }
        putenv('PORTA_CONTAINER_CACHE=off'); // force the in-memory ContainerBuilder path (tags introspectable)
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            putenv(false === $value ? $key : $key.'='.$value);
        }
    }

    public function testChromeRootTagIsExactlyTheExpectedWidgets(): void
    {
        $container = new IdeContainerFactory()->build('/tmp/porta-chrome-root', IdeConfig::load('/tmp/porta-chrome-root'), Ansi::Enabled);
        \assert($container instanceof ContainerBuilder, description: 'PORTA_CONTAINER_CACHE=off yields the introspectable builder');

        $actual = array_keys($container->findTaggedServiceIds('porta.chrome_root'));
        sort($actual);

        $expected = [
            ContextMenu::class,
            DebugPanelWidget::class,
            DockTabsWidget::class,
            FindBarWidget::class,
            MenuBarWidget::class,
            ResultsPanelWidget::class,
            StatusBarWidget::class,
            TerminalPanelWidget::class,
            'porta.widget.main',
        ];
        sort($expected);

        self::assertSame($expected, $actual, 'every chrome root must carry #[AsChromeRoot] (or the porta.widget.main tag)');
    }
}
