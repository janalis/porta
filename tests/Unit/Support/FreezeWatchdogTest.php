<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Support\FreezeWatchdog;
use Porta\Tests\Support\TempProject;
use Revolt\EventLoop;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

final class FreezeWatchdogTest extends TestCase
{
    /** A monotonic clock a test drives by hand, in seconds. */
    private float $monotonic = 100.0;

    public function testADriftPastTheThresholdIsLoggedWithItsDuration(): void
    {
        $project = new TempProject();
        $clock = new MockClock('2026-07-13 10:00:00');
        $watchdog = new FreezeWatchdog($project->dir, $clock, driftThreshold: 3.0, signalsAvailable: false, monotonic: $this->ticking());

        $watchdog->start();
        $watchdog->heartbeat();
        $this->monotonic += 5.0;
        $clock->modify('+5 seconds');
        $watchdog->heartbeat();
        $watchdog->stop();

        $log = (string) \file_get_contents($watchdog->logFile());
        $this->assertStringContainsString('loop stalled for 5.00s (recovered)', $log);
        $this->assertStringContainsString('2026-07-13 10:00:05', $log, 'the wall clock still stamps the line — it just no longer measures the gap');
    }

    public function testAMachineThatSleptIsNotAStall(): void
    {
        // What this log actually had a dozen of: `loop stalled for 1078.06s`,
        // which is a laptop having been shut. The loop was not blocked — it was
        // not running — and the most alarming line in the file was noise.
        $project = new TempProject();
        $clock = new MockClock('2026-07-13 10:00:00');
        $watchdog = new FreezeWatchdog($project->dir, $clock, driftThreshold: 3.0, signalsAvailable: false, monotonic: $this->ticking());

        $watchdog->start();
        $watchdog->heartbeat();
        $clock->modify('+18 minutes'); // the wall clock jumps…
        $this->monotonic += 0.2; // …while the process was suspended
        $watchdog->heartbeat();
        $watchdog->stop();

        $this->assertFileDoesNotExist($watchdog->logFile(), 'a suspended process is not a blocked loop');
    }

    public function testStayingUnderTheThresholdWritesNothing(): void
    {
        $project = new TempProject();
        $clock = new MockClock();
        $watchdog = new FreezeWatchdog($project->dir, $clock, driftThreshold: 3.0, signalsAvailable: false, monotonic: $this->ticking());

        $watchdog->start();
        $watchdog->heartbeat(); // the first beat has no predecessor: nothing to measure
        $this->monotonic += 1.0;
        $clock->modify('+1 second');
        $watchdog->heartbeat();
        $watchdog->stop();

        $this->assertFileDoesNotExist($watchdog->logFile());
    }

    /** @return \Closure(): float */
    private function ticking(): \Closure
    {
        return fn (): float => $this->monotonic;
    }

    public function testAlarmHandlerLogsABacktraceAndSignalsDisarmOnStop(): void
    {
        $project = new TempProject();
        $watchdog = new FreezeWatchdog($project->dir, new MockClock('2026-07-13 11:00:00'), alarmSeconds: 30, signalsAvailable: true);

        $watchdog->start();
        $watchdog->heartbeat(); // the signals arm re-arms the alarm on each beat
        $watchdog->handleAlarm();
        $watchdog->stop();

        $log = (string) \file_get_contents($watchdog->logFile());
        $this->assertStringContainsString('loop blocked ≥ 30s', $log);
        $this->assertStringContainsString('#0', $log, 'the backtrace names the blocking site');
        $this->assertSame(0, \pcntl_alarm(0), 'stop() disarmed the pending alarm');
    }

    public function testSignalsArmByDefault(): void
    {
        $project = new TempProject();
        $watchdog = new FreezeWatchdog($project->dir, new MockClock(), alarmSeconds: 30);

        $watchdog->start(); // ext-pcntl is a hard requirement, so the default arms the alarm
        $this->assertGreaterThan(0, \pcntl_alarm(0), 'the alarm was armed by default');
        $watchdog->stop();
    }

    public function testLogFailuresAreSilent(): void
    {
        $project = new TempProject(['blocker' => 'a regular file where .porta should go']);
        // rootDir pointing at a FILE: mkdir and the append both fail quietly.
        $watchdog = new FreezeWatchdog($project->path('blocker'), $clock = new MockClock(), driftThreshold: 1.0, signalsAvailable: false, monotonic: $this->ticking());

        $watchdog->start();
        $watchdog->heartbeat();
        $this->monotonic += 5.0;
        $clock->modify('+5 seconds');
        $watchdog->heartbeat(); // would log — the failure must not throw
        $watchdog->stop();

        $this->assertFileDoesNotExist($watchdog->logFile());
    }

    public function testARealLoopStallProducesADriftLine(): void
    {
        $project = new TempProject();
        $watchdog = new FreezeWatchdog($project->dir, new NativeClock(), interval: 0.05, driftThreshold: 0.1, signalsAvailable: false);

        $watchdog->start();
        EventLoop::delay(0.08, static function (): void {
            \usleep(300_000); // block the loop long past the drift threshold
        });
        $this->runLoopFor(0.6);
        $watchdog->stop();

        $this->assertFileExists($watchdog->logFile());
        $this->assertStringContainsString('loop stalled for', (string) \file_get_contents($watchdog->logFile()));
    }

    public function testARealHardHangFiresTheAlarmMidBlock(): void
    {
        $project = new TempProject();
        $watchdog = new FreezeWatchdog($project->dir, new NativeClock(), interval: 0.05, driftThreshold: 10.0, alarmSeconds: 1, signalsAvailable: true);

        $watchdog->start();
        EventLoop::delay(0.05, static function (): void {
            \sleep(2); // a hard block: only the async SIGALRM can interrupt it
        });
        $this->runLoopFor(2.6);
        $watchdog->stop();

        $log = (string) \file_get_contents($watchdog->logFile());
        $this->assertStringContainsString('loop blocked ≥ 1s', $log);
        $this->assertStringContainsString('sleep', $log, 'the backtrace names the blocking call');
    }

    private function runLoopFor(float $seconds): void
    {
        $suspension = EventLoop::getSuspension();
        EventLoop::delay($seconds, $suspension->resume(...));
        $suspension->suspend();
    }
}
