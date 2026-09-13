<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferService;
use Porta\Editor\CloseMode;
use Porta\Editor\OpenFocus;
use Porta\Editor\PollBackoff;
use Porta\Event\BufferReloaded;
use Porta\Ide;
use Porta\Tests\Support\BootsIde;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\Toasts;
use Porta\Widget\FileTreeWidget;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

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

    public function testAStoodDownPollStatsNothingUntilItsGapHasPassed(): void
    {
        // The pacer's own arithmetic is {@see PollBackoffTest}'s; this is the
        // wiring — that a stood-down tick reaches no filesystem at all, and that
        // the detection it costs comes back on its own.
        $a = (string) realpath($this->project->write('a.php', "<?php // a\n"));
        $buffers = $this->buffers();
        $buffers->openFile($a, focus: OpenFocus::Editor);
        $manager = Services::resolve($this->ide, BufferManager::class);
        \assert($manager instanceof BufferManager, description: 'the locator serves the buffer manager');

        $backoff = Probe::object(Probe::object($buffers, 'reconciler'), 'backoff');
        Probe::set($backoff, 'skips', 2); // as a slow slice would have left it

        file_put_contents($a, data: "<?php // a v2 is longer\n");
        touch($a, time() + 2);
        $buffers->pollFilesystemSlice();
        $this->assertSame("<?php // a\n", $manager->get($a)?->document->getText(), 'the first stood-down tick polled nothing');
        $buffers->pollFilesystemSlice();
        $this->assertSame("<?php // a\n", $manager->get($a)?->document->getText(), 'nor the second');

        $buffers->pollFilesystemSlice();

        $this->assertSame("<?php // a v2 is longer\n", $manager->get($a)?->document->getText(), 'and the tick after the gap picked the edit up');
    }

    public function testASliceThatOverrunsStandsTheNextTickDown(): void
    {
        // The other half of the wiring: the slice is actually TIMED. Made slow
        // the way a real one is — by work inside it — using the one collaborator
        // a test can lengthen from outside, a listener on the reload the slice
        // performs. A plugin listener this slow is not hypothetical; it is the
        // shape of every freeze.log backtrace, with a stat in place of the
        // listener.
        $a = (string) realpath($this->project->write('a.php', "<?php // a\n"));
        $b = (string) realpath($this->project->write('b.php', "<?php // b\n"));
        $buffers = $this->buffers();
        $buffers->openFile($a, focus: OpenFocus::Editor);
        $buffers->openFile($b, focus: OpenFocus::Editor);
        $manager = Services::resolve($this->ide, BufferManager::class);
        \assert($manager instanceof BufferManager, description: 'the locator serves the buffer manager');
        Services::resolve($this->ide, EventDispatcherInterface::class)->addListener(
            BufferReloaded::class,
            // Twenty percent over the budget: enough that no machine reads this
            // slice as a quick one.
            static function (): void {
                usleep((int) (1.2 * PollBackoff::BUDGET_MICROS));
            },
        );

        file_put_contents($a, data: "<?php // a v2 is longer\n");
        touch($a, time() + 2);
        $buffers->pollFilesystemSlice(); // reloads a.php, and overruns doing it

        file_put_contents($b, data: "<?php // b v2 is longer\n");
        touch($b, time() + 2);
        $buffers->pollFilesystemSlice();

        $this->assertSame("<?php // b\n", $manager->get($b)?->document->getText(), 'the tick after the slow slice polled nothing');
        $buffers->pollFilesystemSlice();
        $this->assertSame("<?php // b v2 is longer\n", $manager->get($b)?->document->getText(), 'the one after it did');
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
