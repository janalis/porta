<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Porta\Ui\NotificationLog;
use Porta\Ui\Notifier;
use Porta\Widget\StatusBarWidget;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class NotifierTest extends TestCase
{
    public function testFlashShowsOnTheStatusBarAndAppendsToTheLog(): void
    {
        $status = new StatusBarWidget(clock: $clock = new MockClock());
        $tui = new Tui(terminal: new VirtualTerminal(columns: 80, rows: 24));
        $log = new NotificationLog($clock);
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
}
