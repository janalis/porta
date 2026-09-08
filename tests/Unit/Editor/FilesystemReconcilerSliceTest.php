<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferService;
use Porta\Editor\CloseMode;
use Porta\Editor\OpenFocus;
use Porta\Ide;
use Porta\Tests\Support\BootsIde;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\Toasts;
use Porta\Widget\FileTreeWidget;

/**
 * The poll timer's amortized reconciliation path: bounded slices of buffers
 * and tree directories per tick (the synchronous full pass stays the seam the
 * rest of the suite drives via {@see Ide::pollFilesystem()}).
 */
final class FilesystemReconcilerSliceTest extends TestCase
{
    use BootsIde;

    #[\Override]
    protected function setUp(): void
    {
        $this->bootIde(['composer.json' => '{}']);
    }

    private function buffers(): BufferService
    {
        $buffers = Services::resolve($this->ide, BufferService::class);
        \assert($buffers instanceof BufferService, description: 'the locator serves the buffer service');

        return $buffers;
    }

    public function testAnExternalEditIsPickedUpByTheSlicedPoll(): void
    {
        $a = (string) realpath($this->project->write('a.php', "<?php // a\n"));
        $b = (string) realpath($this->project->write('b.php', "<?php // b\n"));
        $buffers = $this->buffers();
        $buffers->openFile($a, focus: OpenFocus::Editor);
        $buffers->openFile($b, focus: OpenFocus::Editor);

        file_put_contents($b, data: "<?php // b v2\n");
        touch($b, time() + 2);
        $buffers->pollFilesystemSlice();

        $manager = Services::resolve($this->ide, BufferManager::class);
        \assert($manager instanceof BufferManager, description: 'the locator serves the buffer manager');
        $this->assertSame("<?php // b v2\n", $manager->get($b)?->document->getText(), 'one slice covered both open buffers');
    }

    public function testASliceChecksTheFirstFourBuffersAndLeavesTheFifth(): void
    {
        $buffers = $this->buffers();
        $paths = [];
        foreach (['a', 'b', 'c', 'd', 'e'] as $name) {
            $paths[] = (string) realpath($this->project->write($name.'.php', "<?php\n"));
            $buffers->openFile((string) end($paths), focus: OpenFocus::Editor);
        }

        $buffers->pollFilesystemSlice();

        $reconciler = Probe::object($buffers, 'reconciler'); // inlined service: only reachable through its owner
        $this->assertSame([$paths[4]], Probe::get($reconciler, 'pendingPaths'), 'the slice takes from the FRONT and leaves the last-opened buffer for the next tick');
    }

    public function testABufferClosedMidCycleIsNeverStattedOrFlashed(): void
    {
        $buffers = $this->buffers();
        $paths = [];
        foreach (['a', 'b', 'c', 'd', 'e'] as $name) {
            $paths[$name] = (string) realpath($this->project->write($name.'.php', "<?php\n"));
            $buffers->openFile($paths[$name], focus: OpenFocus::Editor);
        }
        $buffers->pollFilesystemSlice(); // e.php (opened last) is the one left pending

        $buffers->closeActiveBuffer(CloseMode::Force); // e.php was focused last
        unlink($paths['e']);
        $buffers->pollFilesystemSlice();

        $this->assertStringNotContainsString('deleted', Toasts::last($this->ide), 'a closed buffer is skipped, not flashed as deleted');
    }

    public function testTheTreeSliceIsBoundedToEightDirsPerTick(): void
    {
        $deep = $this->project->write('d1/d2/d3/d4/d5/d6/d7/d8/d9/leaf.php', "<?php\n");
        $tree = Services::resolve($this->ide, FileTreeWidget::class);
        \assert($tree instanceof FileTreeWidget, description: 'the locator serves the file tree');
        $tree->revealPath((string) realpath($deep)); // ten scanned dirs: root + d1..d9

        $this->buffers()->pollFilesystemSlice();

        $this->assertSame(8, Probe::get($tree, 'dirCursor'), 'one tick advances the tree scan by exactly eight dirs');
    }

    public function testATreeChangeFoundByTheSliceRequestsARender(): void
    {
        $tree = Services::resolve($this->ide, FileTreeWidget::class);
        \assert($tree instanceof FileTreeWidget, description: 'the locator serves the file tree');
        $tui = Services::resolve($this->ide, \Symfony\Component\Tui\Tui::class);
        Probe::invoke($tui, 'processRender'); // settle: clear any pending request

        $this->project->write('new-entry.php', "<?php\n");
        $this->buffers()->pollFilesystemSlice();

        $this->assertTrue((bool) Probe::get($tui, 'renderRequested'), 'the tree refresh asked for a repaint');
        $this->assertStringContainsString('new-entry.php', implode("\n", array_map(
            \Symfony\Component\Tui\Ansi\AnsiUtils::stripAnsiCodes(...),
            $tree->render(new \Symfony\Component\Tui\Render\RenderContext(30, 20)),
        )));
    }
}
