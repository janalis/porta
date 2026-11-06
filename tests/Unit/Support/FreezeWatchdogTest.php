<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Support\FreezeWatchdog;
use Porta\Tests\Support\ProcessAlarm;
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
        $this->assertStringContainsString('loop stalled for 4.00s (recovered)', $log, 'five seconds between beats a second apart: blocked for four');
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

        $left = ProcessAlarm::borrow(static function () use ($watchdog): int {
            $watchdog->start();
            $watchdog->heartbeat(); // the signals arm re-arms the alarm on each beat
            $watchdog->handleAlarm();
            $watchdog->stop();

            return \pcntl_alarm(0);
        });

        $log = (string) \file_get_contents($watchdog->logFile());
        $this->assertStringContainsString('loop blocked ≥ 30s', $log);
        $this->assertStringContainsString('#0', $log, 'the backtrace names the blocking site');
        $this->assertSame(0, $left, 'stop() disarmed the pending alarm');
    }

    /**
     * An alarm already pending when the watchdog starts is someone else's —
     * PHPUnit's time limit is one — and overwriting it left every test that
     * boots an IDE with no time limit. Its handler fires at its own deadline,
     * our alarm is brought forward to meet it, and stop() hands back the
     * handler with what is left of the time.
     */
    public function testAnAlarmPendingAtStartStillFiresAndIsHandedBack(): void
    {
        $project = new TempProject();
        $watchdog = new FreezeWatchdog($project->dir, new MockClock('2026-07-13 11:00:00'), alarmSeconds: 30, signalsAvailable: true, monotonic: $this->ticking());
        $fired = 0;
        $outer = static function () use (&$fired): void {
            ++$fired;
        };

        [$armed, $handlerAfter, $leftAfter] = ProcessAlarm::borrow(function () use ($watchdog, $outer): array {
            \pcntl_signal(\SIGALRM, $outer);
            \pcntl_alarm(5);
            $watchdog->start();
            $armed = \pcntl_alarm(0);
            $watchdog->heartbeat();

            $watchdog->handleAlarm(); // ours: the outer one is not due yet
            $this->monotonic += 6.0;
            $watchdog->handleAlarm(); // the outer one's deadline has passed
            $watchdog->handleAlarm(); // and it fires once, not on every alarm after
            $this->monotonic -= 3.0;
            $watchdog->stop();

            return [$armed, \pcntl_signal_get_handler(\SIGALRM), \pcntl_alarm(0)];
        });

        $this->assertSame(5, $armed, 'our 30s alarm is brought forward to the outer 5s deadline');
        $this->assertSame(1, $fired, 'the outer handler ran at its deadline');
        $this->assertSame(2, \substr_count((string) \file_get_contents($watchdog->logFile()), needle: 'loop blocked'), 'and only our own alarms were logged as stalls');
        $this->assertSame($outer, $handlerAfter, 'stop() hands the handler back');
        $this->assertSame(0, $leftAfter, 'with no alarm left: the outer one already fired');
    }

    public function testStopHandsBackWhatIsLeftOfAnAlarmThatHasNotFired(): void
    {
        $project = new TempProject();
        $watchdog = new FreezeWatchdog($project->dir, new MockClock('2026-07-13 11:00:00'), alarmSeconds: 3, signalsAvailable: true, monotonic: $this->ticking());
        $outer = static function (): void {};

        $left = ProcessAlarm::borrow(function () use ($watchdog, $outer): int {
            \pcntl_signal(\SIGALRM, $outer);
            \pcntl_alarm(20);
            $watchdog->start();
            $this->monotonic += 12.4;
            $watchdog->stop();

            return \pcntl_alarm(0);
        });

        $this->assertSame(8, $left, 'the 7.6s still to run, in whole seconds rounded up');
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
