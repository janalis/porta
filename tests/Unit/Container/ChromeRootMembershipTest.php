<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Container;

use Porta\Widget\ContextMenu;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\DockTabsWidget;
use Porta\Widget\FindBarWidget;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\TerminalPanelWidget;

/**
 * The chrome roots ChromeInvalidator sweeps on a live theme/locale/cursor/
 * tab-position switch declare themselves with #[AsChromeRoot] (plus the one
 * getter service porta.widget.main tagged at its def). This test builds the
 * container and asserts the `porta.chrome_root` tagged set is exactly the
 * expected roots, so dropping the attribute from a widget — which would
 * silently stop that widget recoloring on a theme switch — fails loudly here.
 */
final class ChromeRootMembershipTest extends ContainerTestCase
{
    public function testChromeRootTagIsExactlyTheExpectedWidgets(): void
    {
        $container = $this->buildContainer();

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
