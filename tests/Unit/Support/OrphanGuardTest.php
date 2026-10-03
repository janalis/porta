<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Porta\Support\OrphanGuard;
use Porta\Tests\Support\Probe;
use Symfony\Component\Tui\Terminal\Terminal;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

/**
 * What the IDE started never outlives it: once the guard's pipe closes — the
 * IDE gone, however it went — every child still watched is ended, politely
 * first and for good after. Real processes, because the guarantee is the
 * operating system's: a pipe the kernel closes, signals that land.
 */
final class OrphanGuardTest extends TestCase
{
    /** The guard of a process that leads its own group, alive until that process exits. */
    private static ?OrphanGuard $heldUntilExit = null;

    /** @var list<resource> children to clean up whatever a test did */
    private array $children = [];

    protected function tearDown(): void
    {
        foreach ($this->children as $child) {
            if (\proc_get_status($child)['running']) {
                \proc_terminate($child, signal: 9);
            }
            \proc_close($child);
        }
    }

    /** @return array{resource, int} a child that runs until it is ended, and its pid */
    private function child(string $script = 'sleep 30'): array
    {
        $pipes = [];
        $child = \proc_open(['/bin/sh', '-c', 'exec '.$script], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $this->assertIsResource($child);
        $this->children[] = $child;

        return [$child, \proc_get_status($child)['pid']];
    }

    /** @param resource $child */
    private static function endsWithin(float $seconds, $child): bool
    {
        $deadline = \microtime(true) + $seconds;
        while (\microtime(true) < $deadline) {
            if (!\proc_get_status($child)['running']) {
                return true;
            }
            \usleep(50_000);
        }

        return false;
    }

    public function testAWatchedChildEndsWhenTheGuardGoes(): void
    {
        [$child, $pid] = $this->child();
        $guard = new OrphanGuard();
        $guard->watch($pid);
        \usleep(100_000);
        $this->assertTrue(\proc_get_status($child)['running'], 'alive while the guard is');

        unset($guard); // the pipe closes, as it does when the IDE ends

        $this->assertTrue(self::endsWithin(3.0, $child), 'ended once the guard went');
    }

    public function testAForgottenChildIsLeftAlone(): void
    {
        [$child, $pid] = $this->child();
        [$other, $otherPid] = $this->child();
        $guard = new OrphanGuard();
        $guard->watch($pid);
        $guard->watch($otherPid);
        $guard->forget($pid);
        $guard->forget(99_999_999); // forgetting what was never watched changes nothing

        unset($guard);

        $this->assertTrue(self::endsWithin(3.0, $other), 'the one still watched ends');
        $this->assertTrue(\proc_get_status($child)['running'], 'the forgotten one runs on: its pid may be another process\'s now');
    }

    /** A child that shrugs off the terminate is killed, which it cannot shrug off. */
    public function testAChildThatIgnoresTheTerminateIsKilled(): void
    {
        [$child, $pid] = $this->child("sh -c 'trap \"\" TERM; while :; do sleep 1; done'");
        $guard = new OrphanGuard();
        $guard->watch($pid);
        \usleep(200_000);

        unset($guard);

        $this->assertFalse(self::endsWithin(1.5, $child), 'the terminate alone does not end it');
        $this->assertTrue(self::endsWithin(3.0, $child), 'the kill does');
    }

    public function testForgettingBeforeAnyWatchStartsNothing(): void
    {
        $guard = new OrphanGuard();
        $guard->forget(1);

        $this->assertNull(Probe::get($guard, 'pipe'), 'no watcher for nothing to guard');
    }

    /** @return array{int, int} the guarded process's exit, and the pid of the child it left behind */
    // @mago-expect lint:no-boolean-flag-parameter -- whether the process leads its group IS the case under test.
    private function orphanOf(bool $leader): array
    {
        $script = \sprintf(
            <<<'PHP'
                require %s;
                if (%s) { posix_setsid(); }
                $guard = new Porta\Support\OrphanGuard();
                $guard->guardGroup(new Symfony\Component\Tui\Terminal\Terminal());
                $pipes = [];
                $child = proc_open(['/bin/sh', '-c', 'exec sleep 30'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
                echo proc_get_status($child)['pid'];
                usleep(200000);
                posix_kill(getmypid(), SIGKILL); // no teardown at all
                PHP,
            \var_export(\dirname(__DIR__, levels: 3).'/vendor/autoload.php', return: true),
            $leader ? 'true' : 'false',
        );
        $pipes = [];
        $process = \proc_open([\PHP_BINARY, '-r', $script], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $this->assertIsResource($process);
        $pid = (int) \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);

        return [\proc_close($process), $pid];
    }

    private static function goneWithin(float $seconds, int $pid): bool
    {
        $deadline = \microtime(true) + $seconds;
        while (\microtime(true) < $deadline) {
            if (!\posix_kill($pid, signal: 0)) {
                return true;
            }
            \usleep(50_000);
        }

        return false;
    }

    /**
     * Not only what was watched: an IDE that leads its process group takes
     * the whole group with it — a run, a shell, a plugin's server — even
     * killed outright, with no teardown to run.
     */
    public function testAGroupLeadersWholeGroupEndsWithIt(): void
    {
        [, $orphan] = $this->orphanOf(leader: true);

        $this->assertGreaterThan(0, $orphan);
        $this->assertTrue(self::goneWithin(4.0, $orphan), 'the child it never watched ends with it');
    }

    /** One sharing its launcher's group guards nothing by group: the launcher would go with it. */
    public function testOneThatLeadsNoGroupLeavesTheGroupAlone(): void
    {
        [, $orphan] = $this->orphanOf(leader: false);

        try {
            $this->assertFalse(self::goneWithin(1.5, $orphan), 'its unwatched child runs on');
        } finally {
            \posix_kill($orphan, \SIGKILL);
        }
    }

    /** An IDE on a virtual terminal is a test's, inside the runner's group: it guards none. */
    public function testAVirtualTerminalGuardsNoGroup(): void
    {
        $guard = new OrphanGuard();
        $guard->guardGroup(new VirtualTerminal());

        $this->assertNull(Probe::get($guard, 'pipe'), 'no watcher started for it');
    }

    /** A watcher that died leaves a broken pipe: the write that finds it drops the guard, and the next watch starts a new one. */
    public function testAWatcherThatDiedIsReplaced(): void
    {
        [$child, $pid] = $this->child();
        $guard = new OrphanGuard();
        $guard->watch($pid);
        $watcher = Probe::get($guard, 'watcher');
        $this->assertIsResource($watcher);
        \proc_terminate($watcher, signal: 9);
        $deadline = \microtime(true) + 3.0;
        while (\proc_get_status($watcher)['running'] && \microtime(true) < $deadline) {
            \usleep(20_000);
        }

        $guard->watch($pid); // finds the pipe broken
        $this->assertNull(Probe::get($guard, 'pipe'), 'the dead watcher dropped');
        $guard->watch($pid); // and starts another
        $this->assertNotSame($watcher, Probe::get($guard, 'watcher'));

        unset($guard);
        $this->assertTrue(self::endsWithin(3.0, $child), 'which guards as the first did');
    }

    /**
     * In a process that leads its own group the group is guarded: the line
     * that says so starts the watcher. A process of its own, made the leader
     * of a new session, so the group that ends with it is its own alone —
     * never the runner's.
     */
    #[RunInSeparateProcess]
    public function testInAGroupLeaderTheGroupIsGuarded(): void
    {
        \posix_setsid();
        // Held until this process exits: freed at the method's end, the guard
        // would end the group — this very process — before it reported back.
        $guard = new OrphanGuard();
        self::$heldUntilExit = $guard;

        $guard->guardGroup(new Terminal());

        $this->assertIsResource(Probe::get($guard, 'pipe'), 'the watcher holds the group');
    }

    /** No process can be started — no /bin/sh, no room for one: nothing is guarded, and nothing breaks. */
    #[RunInSeparateProcess]
    public function testWithNoWatcherToStartNothingIsGuarded(): void
    {
        $limits = \posix_getrlimit();
        \posix_setrlimit(\POSIX_RLIMIT_NPROC, soft_limit: 0, hard_limit: (int) ($limits['hard maxproc'] ?? 0));
        $guard = new OrphanGuard();

        $guard->watch(1);
        $guard->watch(1);

        $this->assertNull(Probe::get($guard, 'pipe'), 'no watcher, tried again and still none');
    }
}
