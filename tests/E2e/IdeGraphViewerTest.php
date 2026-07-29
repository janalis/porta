<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferService;
use Porta\Editor\OpenFocus;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The graph viewer tab: opening a .dot file routes it to the viewer (layout
 * via the committed fake-dot stub — no graphviz dependency), zoom/pan/focus,
 * the invalid-document banner over the last good render (via the filesystem
 * reconciler), tab reuse, and the actionable missing-binary pane.
 */
final class IdeGraphViewerTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;

    private const string SMALL_DOT = "digraph G { rankdir=LR; A -> B [label=\"calls\"]; B -> C; }\n";

    private static function fakeDot(): string
    {
        return \dirname(__DIR__).'/Support/fake-dot';
    }

    /**
     * The stubs run via `#!/usr/bin/env php`, so PATH must resolve `php` — but
     * the usual PATH=/nonexistent (blocking git/LSP spawns) would break them.
     * A throwaway bin dir with ONLY a php symlink keeps both properties.
     */
    private function phpOnlyPath(TempProject $project): void
    {
        $bin = $project->path('.php-only-bin');
        mkdir($bin);
        symlink(\PHP_BINARY, $bin.'/php');
        putenv('PATH='.$bin);
    }

    public function testDotFileOpensInTheGraphViewerWithZoomPanAndSelection(): void
    {
        $project = new TempProject([
            '.porta.json' => (string) json_encode(['settings' => ['dotPath' => self::fakeDot()]]),
            'g.dot' => self::SMALL_DOT,
        ]);
        $this->phpOnlyPath($project);

        $dotPath = $project->path('g.dot');
        $this->runIde(
            $project,
            $dotPath,
            [
                200 => function ($tui, $terminal, $ide): void {
                    // The stub answers on the loop: await the layout instead of sleeping.
                    $this->await(static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'nodes'), what: 'the graph layout to land');
                    $tui->tick();
                    $this->assertSame('graph-viewer', $tui->getFocus()?->getId(), 'the viewer takes editor-area focus');
                    $output = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('g.dot', $output, 'the tab title is the basename');
                    $this->assertStringContainsString('3 nodes · 2 edges', $output, 'the status segment');
                    $this->assertStringContainsString('Fit', $output, 'fit zoom label');
                    $this->assertStringContainsString('calls', $output, 'the edge label rendered');
                    $tui->handleInput('n'); // select the first node
                },
                380 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('· A', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'selection surfaces in the status bar');
                    $tui->handleInput("\x1b[<64;40;10M"); // wheel over the graph pane → pans (no crash)
                    $tui->handleInput("\x1b[<67;40;10M"); // horizontal wheel → pans sideways
                    $tui->handleInput("\x1b[<80;40;10M"); // ctrl+wheel → zoom toward the pointer
                    $tui->handleInput("\x1b[<0;40;10M"); // press arms drag-pan
                    $tui->handleInput("\x1b[<32;44;12M"); // drag → pans
                    $tui->handleInput("\x1b[<0;44;12m"); // release after motion: NOT a select
                    $tui->handleInput("\x1b[<0;40;10M"); // press…
                    $tui->handleInput("\x1b[<0;40;10m"); // …release without motion = click-select
                    $tui->handleInput('0'); // back to fit
                },
                560 => function ($tui, $terminal, $ide) use ($dotPath): void {
                    $tui->tick();
                    $this->assertSame('graph-viewer', $tui->getFocus()?->getId(), 'drag interactions keep the viewer focused');
                    // Break the document on disk: the reconciler re-shows it, the
                    // failing layout keeps the previous rendering under a banner.
                    file_put_contents($dotPath, data: 'digraph { broken');
                    Services::resolve($ide, BufferService::class)->pollFilesystem();
                    $this->await(static function () use ($terminal): bool {
                        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());

                        return str_contains($screen, '✗') || str_contains($screen, 'syntax error');
                    }, what: 'the error banner');
                    $output = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('3 nodes · 2 edges', $output, 'the last good graph is still the model');
                },
            ],
        );
    }

    public function testMissingBinaryShowsTheActionablePane(): void
    {
        // No dotPath setting and an empty PATH: discovery finds nothing.
        putenv('PATH=/nonexistent');
        $project = new TempProject([
            'g.dot' => self::SMALL_DOT,
        ]);

        $this->runIde(
            $project,
            $project->path('g.dot'),
            [
                200 => function ($tui, $terminal): void {
                    $tui->tick();
                    $output = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('graphviz not found', $output);
                    $this->assertStringContainsString('dotPath', $output, 'the message names the setting');
                },
            ],
        );
    }

    public function testGraphTabReusesAndClosesLikeAnyTab(): void
    {
        $project = new TempProject([
            '.porta.json' => (string) json_encode(['settings' => ['dotPath' => self::fakeDot()]]),
            'g.dot' => self::SMALL_DOT,
            'src/A.php' => "<?php\n",
            'big.dot' => str_repeat('/* pad */ ', 1_100_000).'digraph {}',
            'binary.dot' => "\xff\xfe\x00digraph",
            '.porta/architecture.dot' => self::SMALL_DOT,
        ]);
        $this->phpOnlyPath($project);

        // Buffers are keyed by realpath (macOS /var symlinks to /private/var),
        // so the paths handed to open/close must be canonical too.
        $dotPath = (string) realpath($project->path('g.dot'));
        $phpPath = (string) realpath($project->path('src/A.php'));
        $bigPath = (string) realpath($project->path('big.dot'));
        $binaryPath = (string) realpath($project->path('binary.dot'));
        $archPath = (string) realpath($project->path('.porta/architecture.dot'));
        $this->runIde(
            $project,
            $dotPath,
            [
                200 => function ($tui, $terminal, $ide) use ($dotPath, $phpPath, $bigPath, $binaryPath, $archPath): void {
                    $this->await(static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'nodes'), what: 'the graph layout');
                    $buffers = Services::resolve($ide, BufferManager::class);
                    $this->assertCount(1, $buffers->all());
                    $service = Services::resolve($ide, BufferService::class);
                    $service->openFile($dotPath); // same path: reuse, no duplicate
                    $this->assertCount(1, $buffers->all(), 'the existing tab was reused');

                    $service->openFile($phpPath);
                    $tui->tick();
                    $this->assertCount(2, $buffers->all());
                    $this->assertSame('editor', $tui->getFocus()?->getId(), 'a text tab swaps the editor back in');

                    $service->openFile($dotPath); // back to the graph: cached layout, no re-run
                    $tui->tick();
                    $this->assertStringContainsString('3 nodes', AnsiUtils::stripAnsiCodes($terminal->getOutput()));

                    $service->openFile($bigPath);
                    $this->assertCount(2, $buffers->all(), 'an oversized DOT is refused');
                    $service->openFile($binaryPath);
                    $this->assertCount(2, $buffers->all(), 'a non-UTF-8 DOT is refused');

                    $service->openFile($archPath, OpenFocus::Editor);
                    $tui->tick();
                    $this->assertStringContainsString('Project Architecture', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the artifact path titles its tab');
                    $this->assertSame('graph-viewer', $tui->getFocus()?->getId(), 'OpenFocus::Editor lands on the viewer');

                    $service->closeTabsExcept($phpPath); // graph tabs bulk-close through forget()
                    $tui->tick();
                    $this->assertCount(1, $buffers->all());
                    $service->openFile($dotPath); // reopen for the close step below
                    $this->await(static fn (): bool => true, what: 'noop');
                },
                420 => function ($tui, $terminal, $ide) use ($dotPath): void {
                    $tui->handleInput("\x1bw"); // alt+w: close the graph tab through the handler
                    $tui->tick();
                    $buffers = Services::resolve($ide, BufferManager::class);
                    $this->assertCount(1, $buffers->all(), 'the graph tab closed');
                    $this->assertFalse($buffers->has($dotPath));
                },
            ],
        );
    }
}
