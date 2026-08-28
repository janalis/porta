<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Support\PerfCollector;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;

/**
 * The frame-rate cap on a live session: Ide wires setFramePacing, so
 * back-to-back wheel frames inside the 60fps window are coalesced — and the
 * trailing wakeup still lands the final resting frame on the real scheduler.
 */
final class IdeFramePacingTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;

    public function testAWheelBurstIsCappedAndTheRestingFrameStillLands(): void
    {
        putenv('PATH=/nonexistent');
        $lines = "<?php\n".implode("\n", array_map(static fn (int $i): string => "// line {$i}", range(start: 1, end: 60)))."\n";
        $project = new TempProject(['a.php' => $lines]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide): void {
                    $tui->tick(); // a frame, so widget rects exist for the hit-test

                    // Two wheel notches with an immediate tick each: the second
                    // tick lands microseconds after the first paint — with the
                    // real NativeClock wired that is deep inside the cap window.
                    $tui->handleInput("\x1b[<65;45;6M");
                    $tui->tick();
                    $tui->handleInput("\x1b[<65;45;6M");
                    $tui->tick();

                    $perf = Services::resolve($ide, PerfCollector::class);
                    $this->assertGreaterThanOrEqual(1, Probe::get($perf, 'capSkips'), 'Ide wired the pacing: the burst frame was capped');

                    // The trailing wakeup runs on the real RevoltScheduler; the
                    // scrolled viewport must still paint without any forcing.
                    $editor = $tui->getById('editor');
                    $scroll = \Porta\Tests\Support\EditorState::scroll($editor);
                    $this->assertSame(6, $scroll->vScroll(), 'both notches scrolled the model immediately');
                    $this->await(
                        static fn (): bool => false === (bool) Probe::get($tui, 'renderRequested'),
                        what: 'the capped frame to land via the trailing wakeup',
                    );
                },
            ],
        );
    }
}
