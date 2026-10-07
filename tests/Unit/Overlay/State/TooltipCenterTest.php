<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Overlay\State;

use PHPUnit\Framework\TestCase;
use Porta\Overlay\State\TooltipCenter;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\Probe;
use Porta\Ui\Tooltip;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * The tooltip's state: a dwell keyed on the element rather than the pointer,
 * the warm hand-off from one card to the next, and the two ways a card goes —
 * leaving the element, and doing something.
 */
final class TooltipCenterTest extends TestCase
{
    private FakeScheduler $scheduler;

    private float $now = 100.0;

    private Tui $tui;

    private function center(): TooltipCenter
    {
        $this->scheduler = new FakeScheduler();
        $this->tui = new Tui(terminal: new VirtualTerminal(columns: 80, rows: 24));

        return new TooltipCenter($this->scheduler, $this->tui, fn (): float => $this->now);
    }

    private static function tip(string $key, string $text = 'words', int $col = 3): Tooltip
    {
        return new Tooltip($key, $text, 1, $col, $col + 2);
    }

    public function testACardShowsOnlyAfterItsDwell(): void
    {
        $center = $this->center();
        $center->point(self::tip('a'));

        $this->assertNull($center->shown(), 'nothing before the dwell');
        $this->assertSame([0.5], \array_values($this->scheduler->delays), 'the default dwell is half a second');

        $this->scheduler->fire();
        $this->assertSame('a', $center->shown()?->key);
    }

    /** A resting hand jitters: the same element must not restart its dwell, or it never ends. */
    public function testMovesOnTheSameElementKeepTheDwellRunning(): void
    {
        $center = $this->center();
        $center->point(self::tip('a', col: 3));
        $center->point(self::tip('a', col: 4));
        $center->point(self::tip('a', col: 5));

        $this->assertCount(1, $this->scheduler->delays, 'one dwell for one element');
        $this->assertSame([], $this->scheduler->cancelled);

        $this->scheduler->fire();
        $this->assertSame(5, $center->shown()?->colStart, 'the card anchors where the element was last reported');
    }

    public function testAnotherElementRestartsTheDwell(): void
    {
        $center = $this->center();
        $center->point(self::tip('a'));
        $center->point(self::tip('b'));

        $this->assertSame(['1'], $this->scheduler->cancelled, "a's dwell is dropped");
        $this->scheduler->fire();
        $this->assertSame('b', $center->shown()?->key);
    }

    /** Sweeping along a toolbar reads it: the next card follows at once while one is up. */
    public function testTheNextCardFollowsAtOnceWhileOneIsUp(): void
    {
        $center = $this->center();
        $center->point(self::tip('a'));
        $this->scheduler->fire();

        $center->point(self::tip('b'));

        $this->assertSame('b', $center->shown()?->key);
        $this->assertSame([], $this->scheduler->scheduled, 'no second dwell');
    }

    public function testAShortGapBetweenElementsStaysWarmAndALongOneDoesNot(): void
    {
        $center = $this->center();
        $center->point(self::tip('a'));
        $this->scheduler->fire();

        $center->point(null);
        $this->assertNull($center->shown(), 'leaving takes the card');
        $this->now += 0.5;
        $center->point(self::tip('b'));
        $this->assertSame('b', $center->shown()?->key, 'back within the grace: at once');

        $center->point(null);
        $this->now += 0.7;
        $center->point(self::tip('c'));
        $this->assertNull($center->shown(), 'past the grace: the dwell again');
    }

    /** A key or a press is the user acting: the card goes, and the next waits its full dwell. */
    public function testDismissTakesTheCardAndTheWarmth(): void
    {
        $center = $this->center();
        $center->point(self::tip('a'));
        $this->scheduler->fire();

        $center->dismiss();
        $this->assertNull($center->shown());

        $center->point(self::tip('b'));
        $this->assertNull($center->shown(), 'no grace after a dismissal');
    }

    public function testDismissCancelsAPendingDwell(): void
    {
        $center = $this->center();
        $center->point(self::tip('a'));
        $center->dismiss();

        $this->assertSame([], $this->scheduler->scheduled, 'nothing may outlive the loop');
        $center->point(self::tip('a'));
        $this->assertCount(1, $this->scheduler->scheduled, 'the same element dwells afresh');
    }

    /** Mode 1003 floods moves; one over the shown card's element that changes nothing must not ask for a frame. */
    public function testAMoveThatChangesNothingRequestsNoFrame(): void
    {
        $center = $this->center();
        $center->point(self::tip('a'));
        $this->scheduler->fire();

        Probe::set($this->tui, 'renderRequested', false);
        $center->point(self::tip('a'));
        $this->assertFalse(Probe::get($this->tui, 'renderRequested'));
        $this->assertSame([], $this->scheduler->scheduled, 'nor a dwell');
    }

    public function testTheShownCardFollowsNewWordsOrANewSpan(): void
    {
        $center = $this->center();
        $center->point(self::tip('a', 'one'));
        $this->scheduler->fire();

        $center->point(self::tip('a', 'two'));
        $this->assertSame('two', $center->shown()?->text);
        $center->point(new Tooltip('a', 'two', 5, 3, 5));
        $this->assertSame(5, $center->shown()?->row);
        $center->point(new Tooltip('a', 'two', 5, 3, 9));
        $this->assertSame(9, $center->shown()?->colEnd);
    }

    /** An answer the card was waiting on lands in place — on the card up, or on the one still dwelling. */
    public function testARefreshUpdatesOnlyItsOwnCard(): void
    {
        $center = $this->center();
        $center->point(self::tip('a', 'one'));
        $center->refresh(self::tip('a', 'pending, now two'));
        $this->scheduler->fire();
        $this->assertSame('pending, now two', $center->shown()?->text, 'the dwelling card shows the newer words');

        Probe::set($this->tui, 'renderRequested', false);
        $center->refresh(self::tip('a', 'pending, now two'));
        $this->assertFalse(Probe::get($this->tui, 'renderRequested'), 'the same words ask for no frame');

        $center->refresh(self::tip('a', 'three'));
        $this->assertSame('three', $center->shown()?->text);
        $this->assertTrue(Probe::get($this->tui, 'renderRequested'), 'new words are painted');

        $center->refresh(self::tip('b', 'other'));
        $this->assertSame('a', $center->shown()?->key, "another element's answer is not this card's");
    }

    public function testALateAnswerNeverBringsADismissedCardBack(): void
    {
        $center = $this->center();
        $center->point(self::tip('a', 'one'));
        $this->scheduler->fire();
        $center->dismiss();

        $center->refresh(self::tip('a', 'two'));

        $this->assertNull($center->shown());
    }

    public function testADelayOfZeroShowsAtOnce(): void
    {
        $center = $this->center();
        $center->setDelayMs(0);
        $center->point(self::tip('a'));

        $this->assertSame('a', $center->shown()?->key);
        $this->assertSame([], $this->scheduler->delays);
    }

    public function testTheDelayIsTheSettingInMilliseconds(): void
    {
        $center = $this->center();
        $center->setDelayMs(1200);
        $center->point(self::tip('a'));

        $this->assertSame([1.2], \array_values($this->scheduler->delays));
    }

    public function testANegativeDelayReadsAsNone(): void
    {
        $center = $this->center();
        $center->setDelayMs(-300);
        $center->point(self::tip('a'));

        $this->assertSame('a', $center->shown()?->key);
    }

    public function testTurnedOffNothingShowsAndTheCardUpGoes(): void
    {
        $center = $this->center();
        $center->point(self::tip('a'));
        $this->scheduler->fire();

        $center->setEnabled(false);
        $this->assertNull($center->shown(), 'the card up goes');
        $center->point(self::tip('b'));
        $this->assertSame([], $this->scheduler->scheduled, 'and none dwells');

        $center->setEnabled(true);
        $center->point(self::tip('b'));
        $this->assertCount(1, $this->scheduler->scheduled);
    }
}
