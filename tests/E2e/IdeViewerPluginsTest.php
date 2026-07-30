<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferService;
use Porta\Editor\EditorPanes;
use Porta\Plugin\Lifecycle\PluginLifecycle;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UsesBundledPlugins;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The bundled viewer plugins' enable/disable round-trips: a disabled
 * graph-viewer opens .dot files as editable TEXT (deliberate — edit the
 * source), a disabled image-viewer refuses images with the ordinary not-text
 * flash, and live toggles route subsequent opens while already-open tabs stay
 * (the highlighter "applies to files opened afterwards" precedent). The
 * plugin commands leave and rejoin the registry with the toggle.
 */
final class IdeViewerPluginsTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;
    use UsesBundledPlugins;

    private const string SMALL_DOT = "digraph G { rankdir=LR; A -> B; }\n";

    public function testDisabledViewerPluginsRouteToTheTextPath(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject([
            '.porta.json' => (string) json_encode(['enabledPlugins' => []]), // explicit: BOTH viewers off
            'g.dot' => self::SMALL_DOT,
            'img.png' => $this->onePixelPng(),
        ]);

        $this->runIde(
            $project,
            $project->path('g.dot'),
            [
                200 => function ($tui, $terminal, $ide) use ($project): void {
                    $tui->tick();
                    $this->assertSame('editor', $tui->getFocus()?->getId(), 'a .dot file opens in the TEXT editor when the graph viewer is off');
                    $this->assertStringContainsString('digraph G', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the DOT source is editable text');

                    $buffers = Services::resolve($ide, BufferManager::class);
                    $this->assertCount(1, $buffers->all());
                    Services::resolve($ide, BufferService::class)->openFile((string) realpath($project->path('img.png')));
                    $tui->tick();
                    $this->assertCount(1, $buffers->all(), 'the image was refused');
                    $this->assertStringContainsString('Not a UTF-8 text file', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the ordinary text-path refusal');

                    $this->assertNull(Services::resolve($ide, CommandRegistry::class)->label('graph-viewer:architecture'), 'a disabled plugin registers no commands');
                },
            ],
        );
    }

    public function testLiveToggleRoutesSubsequentOpensAndKeepsOpenTabs(): void
    {
        $project = new TempProject([
            '.porta.json' => (string) json_encode(['settings' => ['dotPath' => \dirname(__DIR__).'/Support/fake-dot']]),
            'a.dot' => self::SMALL_DOT,
            'b.dot' => self::SMALL_DOT,
            'c.dot' => self::SMALL_DOT,
        ]);
        $this->phpOnlyPath($project);

        $this->runIde(
            $project,
            $project->path('a.dot'),
            [
                200 => function ($tui, $terminal, $ide) use ($project): void {
                    $this->await(static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'nodes'), what: 'the graph layout');
                    $buffers = Services::resolve($ide, BufferManager::class);
                    $aPath = (string) realpath($project->path('a.dot'));
                    $this->assertTrue($buffers->get($aPath)?->paneId === 'graph', 'default-enabled: the viewer routed the initial file');

                    $lifecycle = Services::resolve($ide, PluginLifecycle::class);
                    $lifecycle->disable('graph-viewer');
                    $this->assertTrue($buffers->get($aPath)?->paneId === 'graph', 'the open tab STAYS a graph tab');
                    $this->assertNull(Services::resolve($ide, CommandRegistry::class)->label('graph-viewer:architecture'), 'the plugin commands left the registry live');

                    $service = Services::resolve($ide, BufferService::class);
                    $bPath = (string) realpath($project->path('b.dot'));
                    $service->openFile($bPath);
                    $tui->tick();
                    $this->assertFalse($buffers->get($bPath)?->paneId === 'graph', 'a NEW .dot open routes to text while disabled');

                    $lifecycle->enable('graph-viewer');
                    $this->assertNotNull(Services::resolve($ide, CommandRegistry::class)->label('graph-viewer:architecture'), 're-enable restores the commands live');
                    $cPath = (string) realpath($project->path('c.dot'));
                    $service->openFile($cPath);
                    $this->assertTrue($buffers->get($cPath)?->paneId === 'graph', 'routing resumed for files opened afterwards');
                },
            ],
        );
    }

    public function testAPluginShipsItsOwnPane(): void
    {
        // The whole point of the registry: a pane that core has never heard of,
        // built from the plugin's own class, answering to the id its claim
        // returns. Consumption comes later — what this pins is that the
        // registration survives the boot and dies with the plugin.
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n", 'doc.demo' => 'demo payload']);
        $this->demoPanePlugin($project);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide) use ($project): void {
                    $panes = Services::resolve($ide, EditorPanes::class);
                    $pane = $panes->paneFor('demo');
                    $this->assertNotNull($pane, 'the plugin\'s pane is registered under its id');
                    $this->assertSame('demo', $pane->paneId());

                    $loader = $panes->loaderFor('demo');
                    $this->assertNotNull($loader);
                    $this->assertSame('demo payload', $loader($project->path('doc.demo')), 'the pane reads its own payload');

                    // Open the claimed file IN the pane while the plugin is live.
                    $buffers = Services::resolve($ide, BufferManager::class);
                    $service = Services::resolve($ide, BufferService::class);
                    $demo = (string) realpath($project->path('doc.demo'));
                    $service->openFile($demo);
                    $this->assertSame('demo', $buffers->get($demo)?->paneId, 'the claim routed it to the plugin pane');

                    // Disabling stops the ROUTING claim and leaves the pane
                    // standing: this tab still has to render, which is the
                    // contract this suite already pins for the bundled viewers.
                    $lifecycle = Services::resolve($ide, PluginLifecycle::class);
                    $lifecycle->disable('demo-pane');
                    $this->assertNotNull($panes->paneFor('demo'), 'a disabled plugin keeps its pane for tabs already open in it');

                    // Removal is the other half: the code is gone from disk, so
                    // nothing may route to it again.
                    $lifecycle->deactivate('demo-pane');
                    $this->assertNull($panes->paneFor('demo'), 'a removed plugin takes its pane with it');

                    // The tab it left behind survives both refresh paths. With no
                    // loader there is nothing to read a new payload with, and that
                    // must read as "keep what you have", not as a crash — on the
                    // explicit re-open AND on the filesystem poll.
                    file_put_contents($demo, data: 'changed underneath');
                    touch($demo, mtime: time() + 5);
                    $service->openFile($demo);
                    $service->pollFilesystem();
                    $this->assertSame('demo payload', $buffers->get($demo)?->panePayload, 'no loader, no refresh');
                },
            ],
        );
    }

    /** A project plugin that claims an extension AND supplies the pane for it. */
    private function demoPanePlugin(TempProject $project): void
    {
        $dir = $project->path('.porta/plugins/demo-pane');
        mkdir($dir, permissions: 0o777, recursive: true);
        file_put_contents($dir.'/plugin.php', data: <<<'PHP'
            <?php

            declare(strict_types=1);

            use Porta\Plugin\Api\AbstractPlugin;
            use Porta\Editor\PaneServices;
            use Porta\Plugin\Api\PluginApi;
            use Porta\Widget\EditorPaneWidget;
            use Symfony\Component\Tui\Render\RenderContext;

            return new class('demo-pane') extends AbstractPlugin {
                public function activate(PluginApi $api): void
                {
                    $api->registerViewer('demo', ['demo']);
                    $api->registerPane(
                        'demo',
                        static fn (PaneServices $services): EditorPaneWidget => new class extends EditorPaneWidget {
                            private string $body = '';

                            public function paneId(): string { return 'demo'; }
                            public function show(string $path, mixed $payload): void { $this->body = (string) $payload; $this->invalidate(); }
                            public function clear(): void { $this->body = ''; }
                            public function statusRight(): string { return 'demo pane'; }
                            public function handleInput(string $data): void {}

                            public function render(RenderContext $context): array
                            {
                                $cols = $context->getColumns();
                                $lines = [substr(str_pad($this->body, $cols), 0, $cols)];
                                for ($row = 1; $row < $context->getRows(); ++$row) {
                                    $lines[] = str_repeat(' ', $cols);
                                }

                                return $this->withMenuOverlay($lines, $cols);
                            }
                        },
                        static fn (string $path): string => (string) file_get_contents($path),
                    );
                }
            };
            PHP);
    }

    /**
     * The stubs run via `#!/usr/bin/env php` (the IdeGraphViewerTest trick):
     * a throwaway bin dir with ONLY a php symlink keeps git/LSP blocked while
     * the fake dot binary stays runnable.
     */
    private function phpOnlyPath(TempProject $project): void
    {
        $bin = $project->path('.php-only-bin');
        mkdir($bin);
        symlink(\PHP_BINARY, $bin.'/php');
        putenv('PATH='.$bin);
    }

    /** A 1×1 PNG, enough to exercise the routing refusal. */
    private function onePixelPng(): string
    {
        $gd = imagecreatetruecolor(1, height: 1);
        \assert(false !== $gd, description: 'the GD handle was created');
        ob_start();
        imagepng($gd);

        return (string) ob_get_clean();
    }
}
