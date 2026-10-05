<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Terminal;

use PHPUnit\Framework\TestCase;
use Porta\Terminal\PtyProcess;
use Porta\Terminal\TerminalBuffer;
use Porta\Terminal\TerminalSession;
use Porta\Terminal\TerminalSessions;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\PumpsLoop;
use Symfony\Component\Process\ExecutableFinder;

final class TerminalSessionsTest extends TestCase
{
    use PumpsLoop;

    public function testCreateActivatesAndFind(): void
    {
        $sessions = new TerminalSessions();
        $this->assertTrue($sessions->isEmpty());
        $this->assertNull($sessions->active());

        $a = $sessions->create('shell:1', 'sh 1');
        $b = $sessions->create('run:build', 'build');
        $this->assertCount(2, Probe::get($sessions, 'sessions'));
        $this->assertSame($b, $sessions->active(), 'create activates the newest');
        $this->assertSame(1, $sessions->activeIndex);
        $this->assertSame($a, $sessions->find('shell:1'));
        $this->assertNull($sessions->find('nope'));
    }

    public function testNextPrevWrapAround(): void
    {
        $sessions = new TerminalSessions();
        $a = $sessions->create('a', 'a');
        $b = $sessions->create('b', 'b');
        $c = $sessions->create('c', 'c'); // active

        $this->assertSame($a, $sessions->next(), 'wraps past the end');
        $this->assertSame($b, $sessions->next());
        $this->assertSame($a, $sessions->prev());
        $this->assertSame($c, $sessions->prev(), 'wraps past the start');
    }

    public function testNextPrevActivateOnAnEmptyCollectionAreNull(): void
    {
        $sessions = new TerminalSessions();
        $this->assertNull($sessions->next());
        $this->assertNull($sessions->prev());
        $this->assertNull($sessions->activate(0));
    }

    public function testActivateClampsAndMakeActiveByIdentity(): void
    {
        $sessions = new TerminalSessions();
        $a = $sessions->create('a', 'a');
        $sessions->create('b', 'b');

        $this->assertSame($a, $sessions->activate(-5), 'clamped to the first');
        $this->assertSame('b', $sessions->activate(99)?->id, 'clamped to the last');

        $sessions->makeActive($a);
        $this->assertSame($a, $sessions->active());
        // A non-member is ignored.
        $orphan = new TerminalSession('x', 'x', new TerminalBuffer());
        $sessions->makeActive($orphan);
        $this->assertSame($a, $sessions->active());
    }

    public function testCloseMovesActiveAndEmptiesToNull(): void
    {
        $sessions = new TerminalSessions();
        $sessions->create('a', 'a');
        $b = $sessions->create('b', 'b');
        $c = $sessions->create('c', 'c'); // active at index 2

        $this->assertSame($b, $sessions->close(2), 'closing the active pointer falls back to the neighbour');
        $this->assertCount(2, Probe::get($sessions, 'sessions'));
        $this->assertSame($b, $sessions->close(9), 'an out-of-range close is a no-op returning the active');
        $this->assertSame($b, $sessions->active());

        $sessions->close(0); // drop 'a'
        $this->assertNull($sessions->close(0), 'closing the last empties the collection');
        $this->assertTrue($sessions->isEmpty());
        $this->assertSame(0, $sessions->activeIndex);
        $this->assertNotSame($c, $sessions->active());
    }

    public function testClosingANonTrailingSessionRemovesOnlyThatOne(): void
    {
        // Closing index 0 with two sessions still after it must not sweep up
        // its neighbour too — array_splice's length must be exactly 1.
        $sessions = new TerminalSessions();
        $sessions->create('a', 'a');
        $b = $sessions->create('b', 'b');
        $c = $sessions->create('c', 'c');

        $sessions->close(0); // drop 'a', both 'b' and 'c' must survive

        $remaining = Probe::get($sessions, 'sessions');
        $this->assertCount(2, $remaining);
        $this->assertSame([$b, $c], $remaining);
    }

    public function testMoveReordersKeepingTheActivePointerOnItsSession(): void
    {
        $sessions = new TerminalSessions();
        $sessions->create('a', 'a');
        $b = $sessions->create('b', 'b');
        $sessions->create('c', 'c');
        $d = $sessions->create('d', 'd'); // active
        $order = static fn (): array => \array_map(static fn (TerminalSession $s): string => $s->id, $sessions->sessions);

        $sessions->move(9, 0);
        $this->assertSame(['a', 'b', 'c', 'd'], $order(), 'an out-of-range source is a no-op');
        $sessions->move(1, 1);
        $this->assertSame(['a', 'b', 'c', 'd'], $order(), 'a same-slot move is a no-op');

        $sessions->move(3, 0); // the active session itself moves
        $this->assertSame(['d', 'a', 'b', 'c'], $order());
        $this->assertSame($d, $sessions->active(), 'the pointer follows the moved session');

        $sessions->move(1, 99); // 'a' to the end (clamped); the active stays in front
        $this->assertSame(['d', 'b', 'c', 'a'], $order());
        $this->assertSame($d, $sessions->active());

        $sessions->makeActive($b);
        $sessions->move(0, 2); // a session from before the active one passes it
        $this->assertSame(['b', 'c', 'd', 'a'], $order());
        $this->assertSame($b, $sessions->active());

        $sessions->move(3, 0); // a session from behind lands in front
        $this->assertSame(['a', 'b', 'c', 'd'], $order());
        $this->assertSame($b, $sessions->active());
    }

    public function testCloseAndStopAllStopTheRunningProcess(): void
    {
        if (null === new ExecutableFinder()->find('sh')) {
            $this->markTestSkipped('no shell');
        }
        $sessions = new TerminalSessions();
        $session = $sessions->create('shell:1', 'sh');
        $process = new PtyProcess(['/bin/sh', '-c', 'sleep 10'], __DIR__);
        $session->attach($process);
        $this->assertTrue($process->start());
        $this->await($process->isRunning(...));

        $sessions->close(0);
        $this->assertFalse($process->isRunning(), 'closing stopped the PTY');

        // stopAll over a fresh running session.
        $s2 = $sessions->create('shell:2', 'sh');
        $p2 = new PtyProcess(['/bin/sh', '-c', 'sleep 10'], __DIR__);
        $s2->attach($p2);
        $p2->start();
        $this->await($p2->isRunning(...));
        $sessions->stopAll();
        $this->assertFalse($p2->isRunning());
    }

    public function testAttachStopsThePreviousProcess(): void
    {
        if (null === new ExecutableFinder()->find('sh')) {
            $this->markTestSkipped('no shell');
        }
        $session = new TerminalSession('x', 'x', new TerminalBuffer());
        $this->assertFalse($session->isRunning());

        $first = new PtyProcess(['/bin/sh', '-c', 'sleep 10'], __DIR__);
        $session->attach($first);
        $first->start();
        $this->await($first->isRunning(...));

        $second = new PtyProcess(['/bin/sh', '-c', 'sleep 10'], __DIR__);
        $session->attach($second); // a re-run: the old one stops
        $this->assertFalse($first->isRunning(), 'the previous process was stopped on attach');
        $second->start();
        $this->await($second->isRunning(...));
        $this->assertTrue($session->isRunning());
        $session->stop();
    }
}
