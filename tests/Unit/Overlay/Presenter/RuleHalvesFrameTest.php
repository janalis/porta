<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Overlay\Presenter;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferService;
use Porta\Editor\OpenFocus;
use Porta\Ide;
use Porta\Layout\WorkspaceLayout;
use Porta\Tests\Support\BootsIde;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\Services;
use Porta\Ui\BidiTui;
use Porta\Widget\Support\TabBarPosition;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Render\WidgetRect;

/**
 * The halves in the real frame: the shown side tab's in the rule over the
 * strip, and — with the settings view open — General's in the window's
 * padding, while the tab strip's go on outside the window.
 */
final class RuleHalvesFrameTest extends TestCase
{
    use BootsIde;
    use PreservesPath;

    /**
     * An Ide booted with no git and no language server on the PATH: a booted
     * Ide spawns one for a PHP file and, never run, never shuts it down.
     *
     * @param array<string, string> $files
     */
    private function bootQuietIde(array $files): Ide
    {
        \putenv('PATH=/nonexistent');

        return $this->bootIde($files);
    }

    /** @return list<string> the frame as composed, plain */
    private function frame(BidiTui $tui): array
    {
        $tui->requestRender(true);
        Probe::invoke($tui, 'processRender');
        $frame = Probe::get($this->ide, 'lastFrame');
        $this->assertIsArray($frame);

        return $frame;
    }

    public function testTheShownTabsAndTheOpenNavsHalvesAreDrawn(): void
    {
        $this->columns = 100;
        $this->rows = 40;
        $ide = $this->bootQuietIde(['composer.json' => '{}', 'a.php' => "<?php\n"]);
        $tui = Probe::object($ide, 'bidiTui');
        $this->assertInstanceOf(BidiTui::class, $tui);
        Services::resolve($ide, WorkspaceLayout::class)->setTabBarPosition(TabBarPosition::Right);
        Services::resolve($ide, BufferService::class)->openFile($this->project->path('a.php'), OpenFocus::Editor);
        $renderer = Services::resolve($ide, Renderer::class);

        $frame = $this->frame($tui);
        $tabs = $renderer->getWidgetRect($tui->getById('tabs') ?? $this->fail('no tabs'));
        $this->assertInstanceOf(WidgetRect::class, $tabs);
        $over = \mb_substr($frame[$tabs->row - 1], $tabs->col, $tabs->columns);
        $this->assertSame(\str_repeat('▄', $tabs->columns), $over, 'the shown tab\'s upper half, its rule\'s column too: the tab is open');

        Services::resolve($ide, ViewRegistry::class)->open('settings');
        $this->frame($tui);
        $frame = $this->frame($tui);
        $nav = $renderer->getWidgetRect($tui->getById('settings-nav') ?? $this->fail('no nav'));
        $this->assertInstanceOf(WidgetRect::class, $nav);
        $this->assertStringContainsString('▄', \mb_substr($frame[$nav->row - 1], $nav->col, $nav->columns), 'General\'s upper half in the window\'s padding');
        $this->assertSame($over, \mb_substr($frame[$tabs->row - 1], $tabs->col, $tabs->columns), 'and the strip\'s still over it, outside the window');
    }

    /** On top, the tree's divider opens beside the shown first tab: blank on its row, its halves over and under. */
    public function testATopStripOpensTheDividerBesideItsShownFirstTab(): void
    {
        $this->columns = 100;
        $this->rows = 40;
        $ide = $this->bootQuietIde(['composer.json' => '{}', 'a.php' => "<?php\n"]);
        $tui = Probe::object($ide, 'bidiTui');
        $this->assertInstanceOf(BidiTui::class, $tui);
        Services::resolve($ide, BufferService::class)->openFile($this->project->path('a.php'), OpenFocus::Editor);

        $frame = $this->frame($tui);
        $tabs = Services::resolve($ide, Renderer::class)->getWidgetRect($tui->getById('tabs') ?? $this->fail('no tabs'));
        $this->assertInstanceOf(WidgetRect::class, $tabs);
        $divider = $tabs->col - 1;

        $this->assertSame(['▄', ' ', '▀'], [
            \mb_substr($frame[$tabs->row - 1], $divider, length: 1),
            \mb_substr($frame[$tabs->row], $divider, length: 1),
            \mb_substr($frame[$tabs->row + 1], $divider, length: 1),
        ]);
        $this->assertSame('│', \mb_substr($frame[$tabs->row + 2], $divider, length: 1), 'the divider runs on under the strip');
    }
}
