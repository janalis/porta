<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Layout;

use PHPUnit\Framework\TestCase;
use Porta\Layout\ChromeHeight;
use Porta\Ui\BidiTui;
use Porta\Ui\Density;
use Porta\Ui\ScreenDensity;
use Porta\Widget\DockTabsWidget;
use Porta\Widget\TabBarWidget;
use Porta\Workbench\ChromeInvalidator;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The screen's density follows the TERMINAL's height, decided before each
 * frame: pushed into both core strips — their own render context carries only
 * their parent's height — and, on a flip, every chrome cache cleared.
 */
final class ChromeHeightTest extends TestCase
{
    public function testTheDensityAndTheStripsFollowTheTerminalAcrossTheThreshold(): void
    {
        $terminal = new VirtualTerminal(columns: 80, rows: 24);
        $tui = new BidiTui(terminal: $terminal, keybindings: new Keybindings([]));
        $tabBar = new TabBarWidget();
        $tabBar->setProvider(static fn (): array => [['name' => 'a.php', 'dirty' => false, 'active' => true]]);
        $dock = new DockTabsWidget();
        $density = new ScreenDensity();
        $height = new ChromeHeight($tui, $density, $tabBar, $dock, new ChromeInvalidator());

        $this->assertSame(Density::Compact, $density->current(), 'decided at construction, before the first frame');
        $this->assertSame(1, $dock->stripRows());
        $this->assertCount(1, $tabBar->render(new RenderContext(40, 5)));

        $terminal->simulateResize(columns: 80, rows: 30);
        $height->apply();

        $this->assertSame(Density::Roomy, $density->current());
        $this->assertSame(3, $dock->stripRows(), 'the dock\'s strip draws its own top edge');
        $this->assertCount(2, $tabBar->render(new RenderContext(40, 5)), 'the editor\'s leaves its upper halves to the rule above');

        $terminal->simulateResize(columns: 80, rows: 29);
        $tui->requestRender(force: true);
        $tui->processRender(); // the frame hook itself, not a direct call
        $this->assertSame(Density::Compact, $density->current(), 'and back, from the frame hook');
        $this->assertSame(1, $dock->stripRows());
    }

    public function testAFlipClearsTheChromeAndASteadyFrameDoesNot(): void
    {
        $terminal = new VirtualTerminal(columns: 80, rows: 24);
        $tui = new BidiTui(terminal: $terminal, keybindings: new Keybindings([]));
        $root = new class extends AbstractWidget {
            #[\Override]
            public function render(RenderContext $context): array
            {
                return [];
            }
        };
        $revision = $root->getRenderRevision();
        $height = new ChromeHeight($tui, new ScreenDensity(), new TabBarWidget(), new DockTabsWidget(), new ChromeInvalidator([$root]));

        $height->apply();
        $this->assertSame($revision, $root->getRenderRevision(), 'the same density twice clears nothing');

        $terminal->simulateResize(columns: 80, rows: 40);
        $height->apply();
        $this->assertGreaterThan($revision, $root->getRenderRevision(), 'a widget cached at its own size would keep the old density');
    }
}
