<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui\Toast;

use PHPUnit\Framework\TestCase;
use Porta\Overlay\Region\ModalBox;
use Porta\Tests\Support\FakeScheduler;
use Porta\Ui\Toast\ToastCenter;
use Porta\Ui\Toast\ToastSeverity;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * The toast stack: auto-expiry timers cancelled on every dismissal path, the
 * stack cap, and the ×/body/miss click triage against the presenter's boxes.
 */
final class ToastCenterTest extends TestCase
{
    private FakeScheduler $scheduler;

    private function center(): ToastCenter
    {
        $this->scheduler = new FakeScheduler();

        return new ToastCenter($this->scheduler, new Tui(terminal: new VirtualTerminal(columns: 80, rows: 24)));
    }

    /** @return array{box: ModalBox, id: int} a card box at rows 1-3, cols 60-79 */
    private function box(int $id, int $top = 1): array
    {
        return ['box' => new ModalBox($top, 60, 20, 3, headerRows: 1, windowStart: 0, count: 1), 'id' => $id];
    }

    public function testPushStacksNewestFirstAndExpiresOnItsTimer(): void
    {
        $center = $this->center();
        $center->push('first', ToastSeverity::Info);
        $center->push('second', ToastSeverity::Error);

        $this->assertSame(['second', 'first'], array_map(static fn ($t) => $t->message, $center->toasts()));
        $this->assertFalse($center->isEmpty());

        $this->scheduler->fire(); // the FIRST toast's expiry
        $this->assertSame(['second'], array_map(static fn ($t) => $t->message, $center->toasts()));
    }

    public function testTheStackCapEvictsTheOldestAndCancelsItsTimer(): void
    {
        $center = $this->center();
        foreach (range(1, 6) as $i) {
            $center->push("t{$i}", ToastSeverity::Info);
        }

        $this->assertCount(5, $center->toasts(), 'the cap holds');
        $this->assertSame('t2', array_map(static fn ($t) => $t->message, $center->toasts())[4], 'the oldest was evicted');
        $this->assertSame(['1'], $this->scheduler->cancelled, "the evicted toast's timer was cancelled");
    }

    public function testClickTriageCloseBodyAndMiss(): void
    {
        $center = $this->center();
        $center->push('a', ToastSeverity::Info);
        $id = $center->toasts()[0]->id;
        $center->setBoxes([$this->box($id)]);

        $this->assertFalse($center->clickAt(10, 10), 'a miss falls through to the panes');
        $this->assertTrue($center->clickAt(2, 65), 'a card-body click is swallowed');
        $this->assertFalse($center->isEmpty(), 'and dismisses nothing');

        $this->assertTrue($center->clickAt(1, 77), 'the × (width − 2) dismisses');
        $this->assertTrue($center->isEmpty());
        $this->assertNotSame([], $this->scheduler->cancelled, 'the dismissed toast cancelled its expiry');
    }

    public function testHoverTracksTheCloseAndReportsCardCoverage(): void
    {
        $center = $this->center();
        $center->push('a', ToastSeverity::Warn);
        $id = $center->toasts()[0]->id;
        $center->setBoxes([$this->box($id)]);

        $this->assertFalse($center->hoverAt(10, 10), 'off every card');
        $this->assertNull($center->closeHoveredId());
        $this->assertTrue($center->hoverAt(1, 77), 'over the ×');
        $this->assertSame($id, $center->closeHoveredId());
        $this->assertTrue($center->hoverAt(2, 65), 'over the body clears the × tint');
        $this->assertNull($center->closeHoveredId());
    }

    public function testDismissAllCancelsEveryTimer(): void
    {
        $center = $this->center();
        $center->push('a', ToastSeverity::Info);
        $center->push('b', ToastSeverity::Success);

        $center->dismissAll();
        $this->assertTrue($center->isEmpty());
        $this->assertSame([], $this->scheduler->scheduled, 'no timer outlives the stack');
        $this->assertCount(2, $this->scheduler->cancelled);
    }

    public function testDismissingAnUnknownIdIsANoOp(): void
    {
        $center = $this->center();
        $center->push('a', ToastSeverity::Info);
        $center->dismiss(999);
        $this->assertCount(1, $center->toasts());
    }
}
