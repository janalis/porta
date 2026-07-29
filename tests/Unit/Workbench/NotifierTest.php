<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench;

use PHPUnit\Framework\TestCase;
use Porta\Overlay\State\ToastCenter;
use Porta\Overlay\State\ToastSeverity;
use Porta\Tests\Support\FakeScheduler;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\NotificationLog;
use Porta\Workbench\Notifier;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class NotifierTest extends TestCase
{
    public function testFlashShowsOnTheStatusBarAndAppendsToTheLog(): void
    {
        $status = new StatusBarWidget(clock: $clock = new MockClock());
        $tui = new Tui(terminal: new VirtualTerminal(columns: 80, rows: 24));
        $log = new NotificationLog($clock, new EventDispatcher());
        $notifier = new Notifier($status, $tui, $log);

        $notifier->flash('saved a.php');

        $this->assertSame(['saved a.php'], array_column($log->entries(), 'text'), 'the flash was logged');
    }

    public function testFlashWithoutALogStillWorks(): void
    {
        $notifier = new Notifier(new StatusBarWidget(), new Tui(terminal: new VirtualTerminal(columns: 80, rows: 24)));
        $notifier->flash('no log wired');

        $this->addToAssertionCount(1); // no crash: the log is optional for tests
    }

    public function testSeveritiesToastWithTheirRankAndLogWithTheGlyph(): void
    {
        $status = new StatusBarWidget(clock: $clock = new MockClock());
        $tui = new Tui(terminal: new VirtualTerminal(columns: 80, rows: 24));
        $log = new NotificationLog($clock, new EventDispatcher());
        $toasts = new ToastCenter(new FakeScheduler(), $tui);
        $notifier = new Notifier($status, $tui, $log, $toasts);

        $notifier->error('disk full');
        $notifier->warn('slow disk');
        $notifier->success('saved');
        $notifier->flash('opened');

        $this->assertSame(
            [ToastSeverity::Error, ToastSeverity::Warn, ToastSeverity::Success, ToastSeverity::Info],
            array_reverse(array_map(static fn ($t) => $t->severity, $toasts->toasts())),
        );
        $this->assertSame(['✗ disk full', '▲ slow disk', '✓ saved', 'opened'], array_column($log->entries(), 'text'), 'non-info entries carry their glyph into the log');

        $notifier->dismissAll();
        $this->assertTrue($toasts->isEmpty(), 'teardown drops the stack');
    }

    /** A hint whispers on the status bar only: no toast, no log entry. */
    public function testHintStaysOnTheStatusBar(): void
    {
        $status = new StatusBarWidget(clock: $clock = new MockClock());
        $tui = new Tui(terminal: new VirtualTerminal(columns: 80, rows: 24));
        $log = new NotificationLog($clock, new EventDispatcher());
        $toasts = new ToastCenter(new FakeScheduler(), $tui);
        $notifier = new Notifier($status, $tui, $log, $toasts);

        $notifier->hint('scrolled +3');

        $this->assertTrue($toasts->isEmpty(), 'no toast for a hint');
        $this->assertSame([], $log->entries(), 'no log entry either');
        $this->assertSame('scrolled +3', \Porta\Tests\Support\Probe::get($status, 'flash')['text'] ?? null, 'the status bar carries it');
    }
}
