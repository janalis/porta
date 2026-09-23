<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Layout;

use PHPUnit\Framework\TestCase;
use Porta\Layout\TabStripHeight;
use Porta\Ui\BidiTui;
use Porta\Widget\DockTabsWidget;
use Porta\Widget\TabBarWidget;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

/**
 * The strips' height follows the TERMINAL's, decided before each frame and
 * pushed into both core strips — their own render context carries only their
 * parent's height.
 */
final class TabStripHeightTest extends TestCase
{
    public function testTheStripsFollowTheTerminalAcrossTheThreshold(): void
    {
        $terminal = new VirtualTerminal(columns: 80, rows: 24);
        $tui = new BidiTui(terminal: $terminal, keybindings: new Keybindings([]));
        $tabBar = new TabBarWidget();
        $tabBar->setProvider(static fn (): array => [['name' => 'a.php', 'dirty' => false, 'active' => true]]);
        $dock = new DockTabsWidget();
        $height = new TabStripHeight($tui, $tabBar, $dock);

        $height->apply();
        $this->assertSame(1, $height->rows());
        $this->assertCount(1, $tabBar->render(new RenderContext(40, 5)));

        $terminal->simulateResize(columns: 80, rows: 30);
        $height->apply();

        $this->assertSame(2, $height->rows());
        $this->assertSame(2, $dock->stripRows());
        $this->assertCount(2, $tabBar->render(new RenderContext(40, 5)));

        $terminal->simulateResize(columns: 80, rows: 29);
        $tui->requestRender(force: true);
        $tui->processRender(); // the frame hook itself, not a direct call
        $this->assertSame(1, $height->rows(), 'and back, from the frame hook');
        $this->assertSame(1, $dock->stripRows());
    }
}
