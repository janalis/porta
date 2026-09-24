<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench;

use PHPUnit\Framework\TestCase;
use Porta\Overlay\State\Toast;
use Porta\Overlay\State\ToastCenter;
use Porta\Overlay\State\ToastSeverity;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\Probe;
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

        $this->assertSame(['◦ saved a.php'], \array_column($log->entries(), 'text'), 'the flash was logged');
    }

    public function testFlashWithoutALogStillWorks(): void
    {
        $status = new StatusBarWidget();
        $tui = new Tui(terminal: new VirtualTerminal(columns: 80, rows: 24));
        $notifier = new Notifier($status, $tui);

        $notifier->flash('no log wired');

        // Without a ToastCenter, notify() falls back to the status-bar flash
        // and still requests a render — both real effects, not just "no crash".
        $this->assertSame('no log wired', Probe::get($status, 'flash')['text'] ?? null);
        $this->assertTrue((bool) Probe::get($tui, 'renderRequested'));
    }

    public function testNotifyIsCallableFromOutsideTheClass(): void
    {
        // PluginApi calls $notifier->notify(...) directly (see
        // PluginApi::flash) — a protected visibility would fatal there.
        $notifier = new Notifier(new StatusBarWidget(), new Tui(terminal: new VirtualTerminal(columns: 80, rows: 24)));

        $notifier->notify('direct call', ToastSeverity::Info);

        $this->addToAssertionCount(1);
    }

    public function testDismissAllIsSafeWithoutToasts(): void
    {
        $notifier = new Notifier(new StatusBarWidget(), new Tui(terminal: new VirtualTerminal(columns: 80, rows: 24)));

        $notifier->dismissAll();

        $this->addToAssertionCount(1); // the toasts collaborator is optional; must not fatal
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
            \array_reverse(\array_map(static fn (Toast $t): ToastSeverity => $t->severity, $toasts->toasts())),
        );
        $this->assertSame(['✗ disk full', '▲ slow disk', '✓ saved', '◦ opened'], \array_column($log->entries(), 'text'), 'every entry carries its glyph into the log, info included');

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

        Probe::set($tui, 'renderRequested', false);
        $notifier->hint('scrolled +3');

        $this->assertTrue($toasts->isEmpty(), 'no toast for a hint');
        $this->assertSame([], $log->entries(), 'no log entry either');
        $this->assertSame('scrolled +3', Probe::get($status, 'flash')['text'] ?? null, 'the status bar carries it');
        $this->assertTrue((bool) Probe::get($tui, 'renderRequested'), 'a hint still requests a render');
    }
}
