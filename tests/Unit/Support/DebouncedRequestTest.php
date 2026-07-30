<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Support\DebouncedRequest;
use Porta\Tests\Support\FakeScheduler;

/** The coalesce-and-debounce both vector viewers re-render through. */
final class DebouncedRequestTest extends TestCase
{
    private FakeScheduler $scheduler;

    /** @var list<float> */
    private array $delivered = [];

    private function request(): DebouncedRequest
    {
        $this->scheduler = new FakeScheduler();

        return new DebouncedRequest($this->scheduler, 0.15);
    }

    private function record(): \Closure
    {
        return function (float $value): void {
            $this->delivered[] = $value;
        };
    }

    public function testTheValueArrivesOnceTheDebounceElapses(): void
    {
        $pending = $this->request();
        $pending->want(2.0, $this->record());

        $this->assertSame([], $this->delivered, 'nothing goes out immediately');
        $this->scheduler->fire();
        $this->assertSame([2.0], $this->delivered);
    }

    public function testAskingAgainForTheSameValueIsFree(): void
    {
        $pending = $this->request();
        $pending->want(2.0, $this->record());
        $pending->want(2.0, $this->record());

        $this->assertCount(1, $this->scheduler->scheduled, 'a pan asks for the resolution it already has');
        $this->assertSame([], $this->scheduler->cancelled);
    }

    public function testANewValueCancelsAndRearms(): void
    {
        $pending = $this->request();
        $pending->want(2.0, $this->record());
        $pending->want(3.0, $this->record());
        $pending->want(4.0, $this->record());
        $this->scheduler->fire();

        $this->assertSame([4.0], $this->delivered, 'a glide costs one request, the last one');
        $this->assertCount(2, $this->scheduler->cancelled);
    }

    public function testSettleMakesTheNextAskForThatValueFree(): void
    {
        // The viewers' first render goes out immediately rather than through the
        // debounce; settle() is how they say so.
        $pending = $this->request();
        $pending->settle(1.0);
        $pending->want(1.0, $this->record());

        $this->assertSame([], $this->scheduler->scheduled, 'already covered');

        $pending->want(2.0, $this->record());
        $this->assertCount(1, $this->scheduler->scheduled);
    }

    public function testResetForgetsBothThePendingRequestAndTheWantedValue(): void
    {
        $pending = $this->request();
        $pending->want(2.0, $this->record());
        $pending->reset();

        $this->assertSame([], $this->scheduler->scheduled, 'the tab went away; so did the request');
        $this->assertCount(1, $this->scheduler->cancelled);

        // And the same value is askable again, since nothing is on screen now.
        $pending->want(2.0, $this->record());
        $this->assertCount(1, $this->scheduler->scheduled);
    }

    public function testCancelKeepsWhatWasWanted(): void
    {
        $pending = $this->request();
        $pending->want(2.0, $this->record());
        $pending->cancel();
        $pending->cancel(); // idempotent
        $pending->want(2.0, $this->record());

        $this->assertSame([], $this->scheduler->scheduled, 'still the value on screen: nothing to do');
    }
}
