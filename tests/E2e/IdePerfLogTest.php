<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Ide;
use Porta\Support\FreezeWatchdog;
use Porta\Support\PerfCollector;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Tui\Tui;

/**
 * The perf collector on a live session: input and render evidence lands in
 * `.porta/perf.log` once per active second, and the watchdog runs with the
 * tightened thresholds the container configures.
 */
final class IdePerfLogTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;

    public function testAnActiveSecondAppendsAStatsLine(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n".\str_repeat("// filler\n", times: 40)]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function (Tui $tui, $terminal, Ide $ide) use ($project): void {
                    $watchdog = Services::resolve($ide, FreezeWatchdog::class);
                    $this->assertSame(0.25, Probe::get($watchdog, 'interval'), 'the container tightened the heartbeat');
                    $this->assertSame(0.5, Probe::get($watchdog, 'driftThreshold'), 'the container tightened the drift threshold');

                    $this->frame($tui); // a real frame, so widget rects exist for the wheel hit-test
                    $tui->handleInput("\x1b[<65;45;6M"); // wheel down over the editor
                    $tui->handleInput('j'); // a keystroke

                    // The 1Hz beat fires on the live loop; await the line landing.
                    $this->await(
                        static function () use ($project): bool {
                            $path = $project->path('.porta/perf.log');

                            return \is_file($path) && \str_contains((string) \file_get_contents($path), 'wheel=1');
                        },
                        what: 'the perf stats line to land',
                    );
                    $log = (string) \file_get_contents($project->path('.porta/perf.log'));
                    $this->assertMatchesRegularExpression('/in=\d+ wheel=1 passes=[1-9]/', $log, 'inputs and render passes were counted');
                    $this->assertMatchesRegularExpression(
                        '/edMs p50=\d+\.\d p95=\d+\.\d hlFull=\d+ hlSplice=\d+/',
                        $log,
                        'the beat attributes editor render time and parse kinds',
                    );
                    // The fields above print 0.0 either way — what proves the
                    // wiring is the collector instance on the widget itself.
                    $this->assertSame(
                        Services::resolve($ide, PerfCollector::class),
                        Probe::get($tui->getById('editor'), 'perf'),
                        'the container feeds the editor timer into the one collector',
                    );
                    $this->assertMatchesRegularExpression(
                        '/boot container=\d+ms plugins=\d+ms eager=\d+ms wired=\d+ms firstPaint=\d+ms/',
                        $log,
                        'the first paint stamped the phase-marked boot line',
                    );
                },
            ],
        );
    }
}
