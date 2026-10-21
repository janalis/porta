<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferService;
use Porta\Editor\EditorPanes;
use Porta\Editor\OpenFocus;
use Porta\Ide;
use Porta\Input\InputDispatcher;
use Porta\Input\MouseEvent;
use Porta\Search\SearchActions;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestPane;
use Porta\Workbench\View\ViewPointer;
use Porta\Workbench\View\ViewPointers;
use Porta\Workbench\View\WheelAxis;
use Porta\Workbench\View\WheelGesture;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * What core does for a pane it has never heard of: the plugin brings a
 * {@see TestPane} for `.demo` files (and `.dot` ones, for the architecture
 * title), and every gesture, refresh and fallback below is core's own — the
 * graph and image viewers are only the bundled panes that happen to use it.
 */
final class IdePluginPaneTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;

    /** A project plugin serving {@see TestPane}; its loader refuses a file that reads `boom`. */
    private function panePlugin(TempProject $project): void
    {
        $dir = $project->path('.porta/plugins/test-pane');
        \mkdir($dir, permissions: 0o777, recursive: true);
        \file_put_contents($dir.'/plugin.php', data: <<<'PHP'
            <?php

            declare(strict_types=1);

            use Porta\Editor\PaneServices;
            use Porta\Plugin\Api\AbstractPlugin;
            use Porta\Plugin\Api\PluginApi;
            use Porta\Tests\Support\TestPane;
            use Porta\Widget\EditorPaneWidget;

            return new class('test-pane') extends AbstractPlugin {
                public function activate(PluginApi $api): void
                {
                    $api->registerViewer('demo', ['demo', 'dot']);
                    $api->registerPane(
                        'demo',
                        static fn (PaneServices $services): EditorPaneWidget => new TestPane('demo'),
                        static function (string $path): string {
                            $text = (string) file_get_contents($path);
                            if ('boom' === $text) {
                                throw new \RuntimeException('unreadable now');
                            }

                            return $text;
                        },
                    );
                }
            };
            PHP);
    }

    private static function pane(Ide $ide): TestPane
    {
        $pane = Services::resolve($ide, EditorPanes::class)->paneFor('demo');
        self::assertInstanceOf(TestPane::class, $pane);

        return $pane;
    }

    /** A file's content changed AND its mtime moved, so the stamp sees it. */
    private static function rewrite(string $path, string $content, int $ahead): void
    {
        \file_put_contents($path, $content);
        \touch($path, mtime: \time() + $ahead);
    }

    public function testThePointerPansZoomsAndSelectsInTheNavigablePane(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['doc.demo' => 'first']);
        $this->panePlugin($project);

        $this->runIde(
            $project,
            null,
            [
                0 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    Services::resolve($ide, BufferService::class)->openFile($project->path('doc.demo'), OpenFocus::Editor);
                    $this->awaitPainted($tui, $terminal, 'showing doc.demo');
                    $pane = self::pane($ide);
                    $rect = Services::resolve($ide, Renderer::class)->getWidgetRect($pane);
                    $this->assertInstanceOf(WidgetRect::class, $rect);
                    // SGR cells are 1-based: the pane's cell (2, 5) is this.
                    [$col, $row] = [$rect->col + 6, $rect->row + 3];
                    $pane->calls = [];

                    $tui->handleInput(\sprintf("\x1b[<65;%d;%dM", $col, $row)); // wheel down
                    $tui->handleInput(\sprintf("\x1b[<67;%d;%dM", $col, $row)); // wheel right
                    // Ctrl+wheel zooms toward the pointer: a lone notch steps
                    // the ladder, one inside a burst glides. The burst is made
                    // by stamping the last notch ahead of the clock, not by two
                    // quick inputs, which a loaded box can space past 100ms.
                    $tui->handleInput(\sprintf("\x1b[<81;%d;%dM", $col, $row));
                    Probe::set(Probe::object(Services::resolve($ide, InputDispatcher::class), 'mouseRouter', 'wheel', 'zoom'), 'lastTime', \microtime(true) + 60.0);
                    $tui->handleInput(\sprintf("\x1b[<80;%d;%dM", $col, $row));
                    $this->assertSame(['scroll:0,6', 'scroll:6,0', 'zoom:-1@5,2', 'magnify:1.08@5,2'], $pane->calls);
                    $pane->calls = [];

                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $col, $row)); // press arms a drag
                    $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $col + 2, $row)); // motion with no button held is no drag
                    $this->assertSame(['press:2,5'], $pane->calls, 'a hover pans nothing');
                    $tui->handleInput(\sprintf("\x1b[<32;%d;%dM", $col + 4, $row + 1)); // drag: the world moves with the pointer
                    $this->assertSame(['press:2,5', 'scroll:-4,-2'], $pane->calls, 'as it moves, not at the release');
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $col + 4, $row + 1)); // a release after motion selects nothing
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $col, $row));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $col, $row)); // a release that never moved is a click
                    $this->assertSame(['press:2,5', 'scroll:-4,-2', 'press:2,5', 'select:2,5'], $pane->calls);
                    $this->assertSame($pane, $tui->getFocus(), 'a press focuses the pane');
                },
            ],
        );
    }

    /**
     * An open view's pointer sees a sideways wheel before any pane does — the
     * settings view stands in for a plugin view that scrolls sideways.
     */
    public function testAnOpenViewsPointerTakesTheSidewaysWheelFirst(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);
        $gestures = [];

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                0 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use (&$gestures): void {
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                    $this->frame($tui);
                    Services::resolve($ide, ViewPointers::class)->register('settings', new class($gestures) implements ViewPointer {
                        /** @param list<string> $gestures */
                        public function __construct(
                            private array &$gestures,
                        ) {}

                        public function continueDrag(MouseEvent $mouse): bool
                        {
                            return false;
                        }

                        public function cancelDrag(): void {}

                        public function press(MouseEvent $mouse): bool
                        {
                            return false;
                        }

                        public function hover(MouseEvent $mouse): void {}

                        public function wheel(WheelGesture $gesture): bool
                        {
                            $this->gestures[] = $gesture->axis->name.':'.$gesture->step;

                            return WheelAxis::Horizontal === $gesture->axis;
                        }
                    });

                    $tui->handleInput("\x1b[<66;40;10M"); // wheel left

                    $this->assertSame(['Horizontal:-6'], $gestures);
                },
            ],
        );
    }

    /**
     * A pane tab refreshes from disk through its plugin's loader — on a
     * re-open and on the filesystem poll — and a loader that refuses NOW
     * keeps the last good payload rather than blanking the tab.
     */
    public function testAPaneTabRefreshesFromDiskAndKeepsItsPayloadWhenTheLoaderRefuses(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['doc.demo' => 'first']);
        $this->panePlugin($project);

        $this->runIde(
            $project,
            null,
            [
                0 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    $service = Services::resolve($ide, BufferService::class);
                    $buffers = Services::resolve($ide, BufferManager::class);
                    $path = (string) \realpath($project->path('doc.demo'));
                    $service->openFile($path, OpenFocus::Editor);
                    $this->assertSame('first', $buffers->get($path)?->panePayload);

                    self::rewrite($path, 'second', ahead: 5);
                    $service->openFile($path);
                    $this->assertSame('second', $buffers->get($path)?->panePayload, 'the re-open read it again');

                    self::rewrite($path, 'boom', ahead: 10);
                    $service->openFile($path);
                    $this->assertSame('second', $buffers->get($path)?->panePayload, 'a refusal on re-open keeps what the tab had');
                    $service->pollFilesystem();
                    $this->assertSame('second', $buffers->get($path)?->panePayload, 'and so does one on the poll');

                    self::rewrite($path, 'third', ahead: 15);
                    $service->pollFilesystem();
                    $this->assertSame('third', $buffers->get($path)?->panePayload, 'the poll reads it once it reads again');
                    $this->assertSame('third', self::pane($ide)->shownPayload, 'and shows it');
                },
            ],
        );
    }

    /**
     * The pickers live in the text editor, which a pane tab detaches: with no
     * text tab to hop back to, they refuse and say so rather than open over
     * nothing.
     */
    public function testWithOnlyAPaneTabOpenTheProjectSearchRefuses(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['doc.demo' => 'first']);
        $this->panePlugin($project);

        $this->runIde(
            $project,
            null,
            [
                0 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    Services::resolve($ide, BufferService::class)->openFile($project->path('doc.demo'), OpenFocus::Editor);
                    $this->awaitPainted($tui, $terminal, 'showing doc.demo');

                    Services::resolve($ide, SearchActions::class)->openProjectSearch();

                    $this->awaitPainted($tui, $terminal, 'Not available in this view');
                    $this->assertTrue(Services::resolve($ide, BufferManager::class)->active()?->isViewer(), 'the pane tab stays active');
                },
            ],
        );
    }

    /**
     * The architecture artifact is titled for what it is, and "Open as Text"
     * turns the viewer tab into the document behind the picture.
     */
    public function testTheArchitectureTabIsTitledAndOpensAsText(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['architecture.dot' => 'digraph G { A -> B }']);
        $this->panePlugin($project);

        $this->runIde(
            $project,
            null,
            [
                0 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    $buffers = Services::resolve($ide, BufferManager::class);
                    $path = (string) \realpath($project->path('architecture.dot'));
                    Services::resolve($ide, BufferService::class)->openFile($path, OpenFocus::Editor);
                    $this->assertSame('Project Architecture', $buffers->get($path)?->title);

                    Services::resolve($ide, CommandRegistry::class)->run('open_as_text');

                    $this->awaitPainted($tui, $terminal, 'digraph G');
                    $this->assertFalse($buffers->get($path)?->isViewer(), 'the tab is a text buffer now');
                    $this->assertCount(1, $buffers->all(), 'in place of the viewer, not beside it');
                },
            ],
        );
    }
}
