<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Overlay\State;

use PHPUnit\Framework\TestCase;
use Porta\I18n\Translator;
use Porta\Overlay\Region\ModalBox;
use Porta\Overlay\State\Toast;
use Porta\Overlay\State\ToastCenter;
use Porta\Overlay\State\ToastSeverity;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\Probe;
use Porta\Ui\Markup\Markup;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * The toast stack: auto-expiry timers cancelled on every dismissal path, the
 * stack cap, and the ✖/body/miss click triage against the presenter's boxes.
 */
final class ToastCenterTest extends TestCase
{
    private FakeScheduler $scheduler;

    private Tui $tui;

    private function center(): ToastCenter
    {
        $this->scheduler = new FakeScheduler();
        $this->tui = new Tui(terminal: new VirtualTerminal(columns: 80, rows: 24));

        return new ToastCenter($this->scheduler, $this->tui);
    }

    /** @return array{box: ModalBox, id: int} a card box at rows 1-3, cols 60-79 */
    private function box(int $id, int $top = 1): array
    {
        return ['box' => new ModalBox($top, 60, 20, 3, headerRows: 1, windowStart: 0, count: 1), 'id' => $id];
    }

    public function testPushStacksNewestFirstAndExpiresOnItsTimer(): void
    {
        $center = $this->center();
        $center->push(Markup::literal('first'), ToastSeverity::Info);
        $center->push(Markup::literal('second'), ToastSeverity::Error);

        $ids = \array_map(static fn (Toast $t): int => $t->id, $center->toasts());
        $this->assertSame([2, 1], $ids, 'ids climb with each push, never fall');
        $this->assertSame(['second', 'first'], \array_map(static fn (Toast $t): string => $t->message->plain(), $center->toasts()));
        $this->assertFalse($center->isEmpty());

        Probe::set($this->tui, 'renderRequested', false);
        $this->scheduler->fire(); // the FIRST toast's expiry
        $this->assertSame(['second'], \array_map(static fn (Toast $t): string => $t->message->plain(), $center->toasts()));
        $this->assertTrue(Probe::get($this->tui, 'renderRequested'), 'the timer expiry itself asks for a frame');
    }

    public function testPushRequestsARenderImmediately(): void
    {
        $center = $this->center();
        Probe::set($this->tui, 'renderRequested', false);

        $center->push(Markup::literal('a'), ToastSeverity::Info);

        $this->assertTrue(Probe::get($this->tui, 'renderRequested'));
    }

    /**
     * A push into a slot still showing takes that card over in place — same
     * id, same stack position, the old timer cancelled and a fresh one armed —
     * so a sticky "Fetching…" becomes its outcome instead of lingering beside it.
     */
    public function testASlottedPushReplacesItsCardInPlace(): void
    {
        $center = $this->center();
        $center->push(Markup::literal('Fetching…'), ToastSeverity::Info, 600.0, 'git.remote');
        $center->push(Markup::literal('other'), ToastSeverity::Info);
        Probe::set($this->tui, 'renderRequested', false);

        $center->push(Markup::literal('Fetched'), ToastSeverity::Success, 3.0, 'git.remote');

        $this->assertSame(['other', 'Fetched'], \array_map(static fn (Toast $t): string => $t->message->plain(), $center->toasts()), 'replaced where it stood, not stacked');
        $this->assertSame([2, 1], \array_map(static fn (Toast $t): int => $t->id, $center->toasts()), 'the card keeps its id');
        $this->assertSame(ToastSeverity::Success, $center->toasts()[1]->severity);
        $this->assertSame(['1'], $this->scheduler->cancelled, "the progress card's 600 s timer is gone");
        $this->assertSame([600.0, 2.5, 3.0], \array_values($this->scheduler->delays), 'the outcome runs on its own timer');
        $this->assertTrue(Probe::get($this->tui, 'renderRequested'));

        $this->scheduler->fire('3');
        $this->assertSame(['other'], \array_map(static fn (Toast $t): string => $t->message->plain(), $center->toasts()), 'the outcome expires on its own timer');

        // A slot no longer showing is simply a new card; a slot-less push never replaces.
        $center->push(Markup::literal('Pulling…'), ToastSeverity::Info, 600.0, 'git.remote');
        $center->push(Markup::literal('plain'), ToastSeverity::Info);
        $center->push(Markup::literal('plain'), ToastSeverity::Info);
        $this->assertCount(4, $center->toasts());
    }

    public function testTheStackCapEvictsTheOldestAndCancelsItsTimer(): void
    {
        $center = $this->center();
        foreach (\range(1, end: 6) as $i) {
            $center->push(Markup::literal("t{$i}"), ToastSeverity::Info);
        }

        $this->assertCount(5, $center->toasts(), 'the cap holds');
        $this->assertSame('t2', \array_map(static fn (Toast $t): string => $t->message->plain(), $center->toasts())[4], 'the oldest was evicted');
        $this->assertSame(['1'], $this->scheduler->cancelled, "the evicted toast's timer was cancelled");
    }

    public function testClickTriageCloseBodyAndMiss(): void
    {
        $center = $this->center();
        $center->push(Markup::literal('a'), ToastSeverity::Info);
        $id = $center->toasts()[0]->id;
        $center->setBoxes([$this->box($id)]);

        $this->assertFalse($center->clickAt(10, 10), 'a miss falls through to the panes');
        $this->assertTrue($center->clickAt(2, 65), 'a card-body click is swallowed');
        $this->assertFalse($center->isEmpty(), 'and dismisses nothing');

        $this->assertTrue($center->clickAt(1, 77), 'the ✖ (width − 2) dismisses');
        $this->assertTrue($center->isEmpty());
        $this->assertNotSame([], $this->scheduler->cancelled, 'the dismissed toast cancelled its expiry');
    }

    public function testHoverTracksTheCloseAndReportsCardCoverage(): void
    {
        $center = $this->center();
        $center->push(Markup::literal('a'), ToastSeverity::Warn);
        $id = $center->toasts()[0]->id;
        $center->setBoxes([$this->box($id)]);

        $this->assertFalse($center->hoverAt(10, 10), 'off every card');
        $this->assertNull($center->closeHoveredId());
        Probe::set($this->tui, 'renderRequested', false);
        $this->assertTrue($center->hoverAt(1, 77), 'over the ✖');
        $this->assertSame($id, $center->closeHoveredId());
        $this->assertTrue(Probe::get($this->tui, 'renderRequested'), 'the ✖ hover changed — a frame is needed to paint the tint');
        $this->assertTrue($center->hoverAt(2, 65), 'over the body clears the ✖ tint');
        $this->assertNull($center->closeHoveredId());
    }

    public function testCoversAndTheCloseTooltip(): void
    {
        $center = $this->center();
        $center->push(Markup::literal('a'), ToastSeverity::Info);
        $id = $center->toasts()[0]->id;
        $center->setBoxes([$this->box($id), $this->box($id + 1, top: 5)]);

        $this->assertTrue($center->covers(2, 65), 'a card body');
        $this->assertTrue($center->covers(6, 65), 'the second card');
        $this->assertFalse($center->covers(10, 10));

        $close = $center->tooltipAt(1, 77, new Translator('en'));
        $this->assertSame(['toast:'.$id, 'Dismiss', 1, 77, 78], [$close?->key, $close?->text, $close?->row, $close?->colStart, $close?->colEnd]);
        $this->assertSame('toast:'.($id + 1), $center->tooltipAt(5, 77, new Translator('en'))?->key, 'each card its own ✖');
        $this->assertNull($center->tooltipAt(2, 65, new Translator('en')), "a card's words need no tooltip");
    }

    public function testDismissAllCancelsEveryTimer(): void
    {
        $center = $this->center();
        $center->push(Markup::literal('a'), ToastSeverity::Info);
        $center->push(Markup::literal('b'), ToastSeverity::Success);

        $center->dismissAll();
        $this->assertTrue($center->isEmpty());
        $this->assertSame([], $this->scheduler->scheduled, 'no timer outlives the stack');
        $this->assertCount(2, $this->scheduler->cancelled);
    }

    public function testDismissingAnUnknownIdIsANoOp(): void
    {
        $center = $this->center();
        $center->push(Markup::literal('a'), ToastSeverity::Info);
        $center->dismiss(999);
        $this->assertCount(1, $center->toasts());
    }

    public function testDismissRequestsARender(): void
    {
        $center = $this->center();
        $center->push(Markup::literal('a'), ToastSeverity::Info);
        $id = $center->toasts()[0]->id;
        Probe::set($this->tui, 'renderRequested', false);

        $center->dismiss($id);

        $this->assertTrue(Probe::get($this->tui, 'renderRequested'));
    }

    /** The match may not be the first entry scanned — the loop must keep going past a miss, not stop there. */
    public function testDismissingAMiddleToastLeavesItsNeighborsIntact(): void
    {
        $center = $this->center();
        $center->push(Markup::literal('a'), ToastSeverity::Info);
        $center->push(Markup::literal('b'), ToastSeverity::Info);
        $center->push(Markup::literal('c'), ToastSeverity::Info);
        [, $idB] = \array_map(static fn (Toast $t): int => $t->id, $center->toasts());

        $center->dismiss($idB);

        $this->assertSame(['c', 'a'], \array_map(static fn (Toast $t): string => $t->message->plain(), $center->toasts()), 'only the middle toast was removed');
        $this->assertContains((string) $idB, $this->scheduler->cancelled);
    }

    /**
     * The card under the pointer holds still: its timer stops, and resumes
     * with the time it had left when the pointer goes — at least a second,
     * so it does not vanish the moment it was being read.
     */
    public function testHoverPausesTheCardAndLeavingResumesIt(): void
    {
        $clock = 100.0;
        $this->scheduler = new FakeScheduler();
        $this->tui = new Tui(terminal: new VirtualTerminal(columns: 80, rows: 24));
        $center = new ToastCenter($this->scheduler, $this->tui, static function () use (&$clock): float {
            return $clock;
        });
        $center->push(Markup::literal('read me'), ToastSeverity::Error, 5.0);
        $center->push(Markup::literal('other'), ToastSeverity::Info, 5.0);
        [$newer, $older] = $center->toasts();
        $center->setBoxes([$this->box($newer->id), $this->box($older->id, top: 5)]);

        $clock = 102.0;
        $center->hoverAt(6, 65); // on the older card
        $this->assertTrue($older->paused);
        $this->assertFalse($newer->paused, 'only the card being read');
        $this->assertContains($older->timerId, $this->scheduler->cancelled);
        $center->hoverAt(6, 66);
        $this->assertCount(1, $this->scheduler->cancelled, 'resting on it again changes nothing');

        $clock = 150.0; // however long it is read
        $center->hoverAt(20, 10); // gone
        $this->assertFalse($older->paused);
        $this->assertEqualsWithDelta(3.0, $this->scheduler->delays[$older->timerId], 0.001, 'the three seconds it had left');

        $clock = 152.5; // half a second left when the pointer comes back
        $center->hoverAt(6, 65);
        $center->hoverAt(20, 10);
        $this->assertEqualsWithDelta(1.0, $this->scheduler->delays[$older->timerId], 0.001, 'at least a second back');
    }

    /** Alt+D takes the top card — the newest — and says whether there was one. */
    public function testDismissNewestTakesTheTopCard(): void
    {
        $center = $this->center();
        $this->assertFalse($center->dismissNewest());
        $center->push(Markup::literal('older'), ToastSeverity::Info);
        $center->push(Markup::literal('newer'), ToastSeverity::Info);

        $this->assertTrue($center->dismissNewest());
        $this->assertSame(['older'], \array_map(static fn (Toast $t): string => $t->message->plain(), $center->toasts()));
    }

    /** A slotted card replaced under the resting pointer stays held, with the new card's full time once the pointer goes. */
    public function testAReplacedCardUnderThePointerStaysHeld(): void
    {
        $clock = 0.0;
        $this->scheduler = new FakeScheduler();
        $this->tui = new Tui(terminal: new VirtualTerminal(columns: 80, rows: 24));
        $center = new ToastCenter($this->scheduler, $this->tui, static function () use (&$clock): float {
            return $clock;
        });
        $center->push(Markup::literal('Fetching…'), ToastSeverity::Info, 600.0, 'git.remote');
        $center->setBoxes([$this->box($center->toasts()[0]->id)]);
        $center->hoverAt(2, 65);

        $center->push(Markup::literal('Fetched'), ToastSeverity::Success, 3.0, 'git.remote');
        $card = $center->toasts()[0];
        $this->assertTrue($card->paused, 'still being read');
        $this->assertSame([], $this->scheduler->scheduled, 'no timer runs under the pointer');

        $clock = 50.0;
        $center->hoverAt(20, 10);
        $this->assertEqualsWithDelta(3.0, $this->scheduler->delays[$card->timerId], 0.001, 'its own three seconds, from when the pointer left');
    }
}
