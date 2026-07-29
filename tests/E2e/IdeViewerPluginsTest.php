<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferService;
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
                    $this->assertTrue($buffers->get($aPath)?->isGraph(), 'default-enabled: the viewer routed the initial file');

                    $lifecycle = Services::resolve($ide, PluginLifecycle::class);
                    $lifecycle->disable('graph-viewer');
                    $this->assertTrue($buffers->get($aPath)?->isGraph(), 'the open tab STAYS a graph tab');
                    $this->assertNull(Services::resolve($ide, CommandRegistry::class)->label('graph-viewer:architecture'), 'the plugin commands left the registry live');

                    $service = Services::resolve($ide, BufferService::class);
                    $bPath = (string) realpath($project->path('b.dot'));
                    $service->openFile($bPath);
                    $tui->tick();
                    $this->assertFalse($buffers->get($bPath)?->isGraph(), 'a NEW .dot open routes to text while disabled');

                    $lifecycle->enable('graph-viewer');
                    $this->assertNotNull(Services::resolve($ide, CommandRegistry::class)->label('graph-viewer:architecture'), 're-enable restores the commands live');
                    $cPath = (string) realpath($project->path('c.dot'));
                    $service->openFile($cPath);
                    $this->assertTrue($buffers->get($cPath)?->isGraph(), 'routing resumed for files opened afterwards');
                },
            ],
        );
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
