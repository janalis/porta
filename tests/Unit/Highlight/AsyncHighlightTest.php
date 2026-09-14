<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Highlight;

use PHPUnit\Framework\TestCase;
use Porta\Highlight\CapturingHighlightSink;
use Porta\Highlight\CodeHighlighter;
use Porta\Highlight\PlainTextHighlightProvider;
use Porta\Tests\Support\ClosureHighlightProvider;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\Probe;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * Paint-then-refine: an asynchronous backend answers after the frame that asked.
 */
final class AsyncHighlightTest extends TestCase
{
    private const string PAINT = "\x1b[38;5;201m";

    /** @param string[] $lines */
    private function asyncProvider(): ClosureHighlightProvider
    {
        return new ClosureHighlightProvider(
            static fn (string $content): string => \implode("\n", \array_map(
                static fn (string $line): string => self::PAINT.$line."\x1b[39m",
                \explode("\n", $content),
            )),
            async: true,
        );
    }

    private function highlighter(ClosureHighlightProvider $provider, ?\Closure $onRepaint = null): CodeHighlighter
    {
        $highlighter = new CodeHighlighter($provider, null, $onRepaint);
        $highlighter->setFile('/x/a.php');

        return $highlighter;
    }

    /** On first open there is nothing to carry forward, so the paint is plain. */
    public function testFirstPaintIsPlainThenRefinesOnDelivery(): void
    {
        $provider = $this->asyncProvider();
        $highlighter = $this->highlighter($provider);

        $provisional = $highlighter->highlight(['<?php', 'echo 1;'], 1);
        $this->assertSame(['<?php', 'echo 1;'], $provisional, 'painted plain while waiting');

        $provider->deliverPending();

        $resolved = $highlighter->highlight(['<?php', 'echo 1;'], 1);
        $this->assertStringContainsString(self::PAINT, \implode('', $resolved));
        $this->assertSame(['<?php', 'echo 1;'], \array_map(AnsiUtils::stripAnsiCodes(...), $resolved));
    }

    /** A late answer must ask for the frame that will show it. */
    public function testAsyncDeliveryRequestsARepaint(): void
    {
        $repaints = 0;
        $provider = $this->asyncProvider();
        $highlighter = $this->highlighter($provider, static function () use (&$repaints): void {
            ++$repaints;
        });

        $highlighter->highlight(['<?php'], 1);
        $this->assertSame(0, $repaints, 'nothing to repaint yet');

        $provider->deliverPending();
        $this->assertSame(1, $repaints);
    }

    /** A synchronous backend needs no repaint — the caller is already rendering. */
    public function testSynchronousDeliveryDoesNotRequestARepaint(): void
    {
        $repaints = 0;
        $provider = new ClosureHighlightProvider(static fn (string $content): string => $content);
        $highlighter = new CodeHighlighter($provider, null, static function () use (&$repaints): void {
            ++$repaints;
        });
        $highlighter->setFile('/x/a.php');

        $highlighter->highlight(['<?php'], 1);

        $this->assertSame(0, $repaints);
    }

    /** Once colors exist, a same-shape edit keeps them rather than flashing plain. */
    public function testProvisionalPaintCarriesPreviousColorsWhenTheShapeMatches(): void
    {
        $provider = $this->asyncProvider();
        $highlighter = $this->highlighter($provider);

        $highlighter->highlight(['<?php', 'echo 1;'], 1);
        $provider->deliverPending();
        $highlighter->highlight(['<?php', 'echo 1;'], 1);

        $provisional = $highlighter->highlight(['<?php', 'echo 2;'], 2);

        $this->assertStringContainsString(self::PAINT, \implode('', $provisional), 'kept the old colors');
    }

    /** A different line count cannot reuse the old paint, so it falls back to plain. */
    public function testProvisionalPaintFallsBackToPlainWhenTheShapeChanges(): void
    {
        $provider = $this->asyncProvider();
        $highlighter = $this->highlighter($provider);

        $highlighter->highlight(['<?php', 'echo 1;'], 1);
        $provider->deliverPending();
        $highlighter->highlight(['<?php', 'echo 1;'], 1);

        $provisional = $highlighter->highlight(['<?php', 'echo 1;', 'echo 2;'], 2);

        $this->assertSame(['<?php', 'echo 1;', 'echo 2;'], $provisional);
    }

    /**
     * The staleness check is the whole safety story: an answer to a question we
     * have moved on from must never reach the buffer.
     */
    public function testAnswerForASupersededVersionIsDropped(): void
    {
        $provider = $this->asyncProvider();
        $highlighter = $this->highlighter($provider);

        $highlighter->highlight(['one'], 1); // asked about v1
        $highlighter->highlight(['two'], 2); // superseded by v2

        $provider->deliverPending(); // both answers arrive; only v2's is wanted

        $this->assertSame(['two'], \array_map(AnsiUtils::stripAnsiCodes(...), $highlighter->highlight(['two'], 2)));
    }

    public function testUnsolicitedDeliveryIsIgnored(): void
    {
        $provider = $this->asyncProvider();
        $highlighter = $this->highlighter($provider);

        $highlighter->deliver('/x/a.php', 99, 'GARBAGE');

        $this->assertSame(['a'], $highlighter->highlight(['a'], 1));
    }

    /** A backend that answers with the wrong line count is a fault, not a render error. */
    public function testDeliveryWithAMangledLineCountFallsBackToPlain(): void
    {
        $provider = new ClosureHighlightProvider(static fn (): string => "only\none\nline\ncount\nwrong", async: true);
        $highlighter = $this->highlighter($provider);

        $highlighter->highlight(['a', 'b'], 1);
        $provider->deliverPending();

        $this->assertSame(['a', 'b'], $highlighter->highlight(['a', 'b'], 1));
    }

    /** An explicit give-up leaves the current paint alone. */
    public function testFailLeavesTheCurrentPaintAlone(): void
    {
        $provider = $this->asyncProvider();
        $highlighter = $this->highlighter($provider);

        $highlighter->highlight(['a'], 1);
        $highlighter->fail('/x/a.php', 1, new \RuntimeException('gave up'));

        $this->assertSame(['a'], $highlighter->highlight(['a'], 1));
    }

    public function testFailForASupersededVersionIsIgnored(): void
    {
        $provider = $this->asyncProvider();
        $highlighter = $this->highlighter($provider);

        $highlighter->highlight(['a'], 1);
        $highlighter->fail('/x/a.php', 99, new \RuntimeException('stale'));
        $provider->deliverPending();

        $this->assertStringContainsString(self::PAINT, \implode('', $highlighter->highlight(['a'], 1)));
    }

    /**
     * Three consecutive faults drop the backend for this buffer — a backend
     * that keeps costing frame time is worse than one that stays off.
     */
    public function testRepeatedFailuresTripTheBackendToPlain(): void
    {
        $provider = new ClosureHighlightProvider(static fn (): string => throw new \RuntimeException('always fails'));
        $highlighter = $this->highlighter($provider);

        foreach ([1, 2, 3] as $version) {
            $this->assertSame(['a'], $highlighter->highlight(['a'], $version));
        }

        $callsBefore = $provider->calls;
        $highlighter->highlight(['a'], 4);

        $this->assertSame($callsBefore, $provider->calls, 'tripped: the backend is no longer called');
    }

    /**
     * The capturing sink backs the single-line incremental path, where a
     * backend giving up has to read as "no result" rather than a stale one.
     */
    public function testCapturingSinkRecordsDeliveryAndClearsOnFailure(): void
    {
        $sink = new CapturingHighlightSink();
        $this->assertNull($sink->markup, 'nothing captured yet');

        $sink->deliver('/x/a.php', 1, 'PAINTED');
        $this->assertSame('PAINTED', $sink->markup);

        $sink->fail('/x/a.php', 1, new \RuntimeException('gave up'));
        $this->assertNull($sink->markup, 'a give-up must not leave the old markup behind');
    }

    /** A success resets the count, so an occasional blip never trips it. */
    public function testASuccessResetsTheStrikeCount(): void
    {
        $fail = true;
        $provider = new ClosureHighlightProvider(static function (string $content) use (&$fail): string {
            if ($fail) {
                throw new \RuntimeException('intermittent');
            }

            return $content;
        });
        $highlighter = $this->highlighter($provider);

        $highlighter->highlight(['a'], 1);
        $highlighter->highlight(['a'], 2);
        $fail = false;
        $highlighter->highlight(['a'], 3); // success resets
        $fail = true;
        $highlighter->highlight(['a'], 4);
        $highlighter->highlight(['a'], 5);

        $callsBefore = $provider->calls;
        $highlighter->highlight(['a'], 6);

        $this->assertGreaterThan($callsBefore, $provider->calls, 'still live: never hit three in a row');
    }

    /**
     * The strike counter only catches a backend that *fails*. One that
     * dispatches and never answers would leave the buffer provisionally painted
     * and re-dispatch on every edit forever, so the request gets a deadline.
     */
    public function testAnAnswerThatNeverArrivesIsAbandonedAndStruck(): void
    {
        $scheduler = new FakeScheduler();
        $provider = $this->asyncProvider();
        $highlighter = new CodeHighlighter($provider, null, null, $scheduler);
        $highlighter->setFile('/x/a.php');

        $highlighter->highlight(['a'], 1);
        $this->assertCount(1, $scheduler->scheduled, 'a deadline was armed');

        $scheduler->fire();

        // The request is forgotten, so a late answer is dropped by the ordinary
        // staleness check rather than clobbering a newer paint.
        $provider->deliverPending();
        $this->assertSame(['a'], $highlighter->highlight(['a'], 1));
    }

    /** Three timeouts in a row trip the backend, like three failures do. */
    public function testRepeatedTimeoutsTripTheBackend(): void
    {
        $scheduler = new FakeScheduler();
        $provider = $this->asyncProvider();
        $highlighter = new CodeHighlighter($provider, null, null, $scheduler);
        $highlighter->setFile('/x/a.php');

        foreach ([1, 2, 3] as $version) {
            $highlighter->highlight(['a'], $version);
            $scheduler->fire();
        }

        $callsBefore = $provider->calls;
        $highlighter->highlight(['a'], 4);

        $this->assertSame($callsBefore, $provider->calls, 'tripped: the backend is no longer called');
    }

    /** A delivered answer disarms its deadline, so it cannot strike afterwards. */
    public function testDeliveryDisarmsTheDeadline(): void
    {
        $scheduler = new FakeScheduler();
        $provider = $this->asyncProvider();
        $highlighter = new CodeHighlighter($provider, null, null, $scheduler);
        $highlighter->setFile('/x/a.php');

        $highlighter->highlight(['a'], 1);
        $provider->deliverPending();

        $this->assertSame([], $scheduler->scheduled, 'no deadline left pending');
        $this->assertCount(1, $scheduler->cancelled);
    }

    /**
     * A deadline outliving its request must no-op rather than strike.
     *
     * {@see CodeHighlighter::invalidate()} (a live theme switch) forgets the
     * in-flight request without touching the timer, so the timer is the one
     * place that has to re-check.
     */
    public function testADeadlineForAForgottenRequestIsIgnored(): void
    {
        $scheduler = new FakeScheduler();
        $provider = $this->asyncProvider();
        $highlighter = new CodeHighlighter($provider, null, null, $scheduler);
        $highlighter->setFile('/x/a.php');

        $highlighter->highlight(['a'], 1);
        $highlighter->invalidate();
        $scheduler->fire(); // the request is gone; this must not count a strike

        // Still live: a fresh request is dispatched and delivers normally.
        $highlighter->highlight(['a'], 2);
        $provider->deliverPending();

        $this->assertStringContainsString(self::PAINT, \implode('', $highlighter->highlight(['a'], 2)));
    }

    /** A synchronous backend never arms a deadline — it has already answered. */
    public function testASynchronousBackendArmsNoDeadline(): void
    {
        $scheduler = new FakeScheduler();
        $provider = new ClosureHighlightProvider(static fn (string $content): string => $content);
        $highlighter = new CodeHighlighter($provider, null, null, $scheduler);
        $highlighter->setFile('/x/a.php');

        $highlighter->highlight(['a'], 1);

        $this->assertSame([], $scheduler->scheduled);
    }

    /** A synchronous dispatch exception resets inFlightVersion to the exact idle sentinel. */
    public function testFailedDispatchResetsInFlightVersionToTheExactSentinel(): void
    {
        $provider = new ClosureHighlightProvider(static function (): string {
            throw new \RuntimeException('boom');
        });
        $highlighter = new CodeHighlighter($provider);
        $highlighter->setFile('/x/a.php');

        $highlighter->highlight(['a'], 1);

        $this->assertSame(-1, Probe::get($highlighter, 'inFlightVersion'));
    }

    /**
     * A synchronous dispatch exception gives up immediately — it must not
     * fall into the "wait for an async answer" provisional-paint path.
     */
    public function testFailedDispatchGivesUpImmediatelyRatherThanWaitingForAnAnswer(): void
    {
        $scheduler = new FakeScheduler();
        $provider = new ClosureHighlightProvider(static function (): string {
            throw new \RuntimeException('boom');
        });
        $highlighter = new CodeHighlighter($provider, null, null, $scheduler);
        $highlighter->setFile('/x/a.php');

        $highlighter->highlight(['a'], 1);

        $this->assertSame([], $scheduler->scheduled, 'no answer deadline armed — there is no answer coming');
        $this->assertFalse(Probe::get($highlighter, 'asyncBackend'), 'a thrown exception is not evidence of an async backend');
    }

    /** A mangled-line-count delivery resets inFlightVersion to the exact idle sentinel. */
    public function testMangledDeliveryResetsInFlightVersionToTheExactSentinel(): void
    {
        $provider = new ClosureHighlightProvider(static fn (): string => "only\none\nline", async: true);
        $highlighter = $this->highlighter($provider);

        $highlighter->highlight(['a', 'b'], 1);
        $provider->deliverPending();

        $this->assertSame(-1, Probe::get($highlighter, 'inFlightVersion'));
    }

    /** A mangled-line-count delivery is a real fault: three in a row trip the backend. */
    public function testMangledLineCountCountsAsAFailureAndCanTripTheBackend(): void
    {
        $provider = new ClosureHighlightProvider(static fn (): string => "only\none\nline", async: true);
        $highlighter = $this->highlighter($provider);

        foreach ([1, 2, 3] as $version) {
            $highlighter->highlight(['a', 'b'], $version);
            $provider->deliverPending();
        }

        $this->assertInstanceOf(PlainTextHighlightProvider::class, Probe::get($highlighter, 'provider'), 'three mangled deliveries trip the backend like three failures');
    }

    /** A successful delivery resets every counter to its exact idle value, not just clears it. */
    public function testSuccessfulDeliveryResetsCountersToTheirExactIdleValues(): void
    {
        $provider = $this->asyncProvider();
        $highlighter = $this->highlighter($provider);

        $highlighter->highlight(['a'], 1);
        Probe::set($highlighter, 'staleEdits', 7);
        Probe::set($highlighter, 'strikes', 2);
        $provider->deliverPending();

        $this->assertSame(-1, Probe::get($highlighter, 'inFlightVersion'));
        $this->assertSame(0, Probe::get($highlighter, 'staleEdits'));
        $this->assertSame(0, Probe::get($highlighter, 'strikes'));
    }

    /** An explicit give-up both disarms the deadline and counts as a failure. */
    public function testFailDisarmsTheDeadlineAndRecordsAFailure(): void
    {
        $scheduler = new FakeScheduler();
        $provider = $this->asyncProvider();
        $highlighter = new CodeHighlighter($provider, null, null, $scheduler);
        $highlighter->setFile('/x/a.php');

        $highlighter->highlight(['a'], 1);
        $this->assertCount(1, $scheduler->scheduled);

        $highlighter->fail('/x/a.php', 1, new \RuntimeException('gave up'));

        $this->assertSame([], $scheduler->scheduled, 'the deadline was disarmed');
        $this->assertCount(1, $scheduler->cancelled);
        $this->assertSame(1, Probe::get($highlighter, 'strikes'), 'an explicit fail() counts as a failure');
    }

    /** fail() resets inFlightVersion to the exact idle sentinel. */
    public function testFailResetsInFlightVersionToTheExactSentinel(): void
    {
        $provider = $this->asyncProvider();
        $highlighter = $this->highlighter($provider);

        $highlighter->highlight(['a'], 1);
        $highlighter->fail('/x/a.php', 1, new \RuntimeException('gave up'));

        $this->assertSame(-1, Probe::get($highlighter, 'inFlightVersion'));
    }

    /** Re-dispatching (a second edit before the first answer) cancels the first deadline. */
    public function testArmingANewDeadlineCancelsAnyPriorOne(): void
    {
        $scheduler = new FakeScheduler();
        $provider = $this->asyncProvider();
        $highlighter = new CodeHighlighter($provider, null, null, $scheduler);
        $highlighter->setFile('/x/a.php');

        $highlighter->highlight(['a'], 1);
        $firstTimer = (string) \array_key_first($scheduler->scheduled);
        $highlighter->highlight(['b'], 2);

        $this->assertCount(1, $scheduler->scheduled, 'only one deadline pending at a time');
        $this->assertContains($firstTimer, $scheduler->cancelled, 'the superseded deadline was cancelled, not left dangling');
    }

    /** A stale deadline firing for a request already forgotten via invalidate() must not count a strike. */
    public function testStaleDeadlineFiringForAForgottenRequestNeverRecordsAFailure(): void
    {
        $scheduler = new FakeScheduler();
        $provider = $this->asyncProvider();
        $highlighter = new CodeHighlighter($provider, null, null, $scheduler);
        $highlighter->setFile('/x/a.php');

        $highlighter->highlight(['a'], 1);
        $highlighter->invalidate();
        $scheduler->fire();

        $this->assertSame(0, Probe::get($highlighter, 'strikes'), 'a superseded deadline firing is a no-op, not a fault');
    }

    /** An answer-deadline that genuinely fires resets inFlightVersion to the exact idle sentinel. */
    public function testDeadlineFiringResetsInFlightVersionToTheExactSentinel(): void
    {
        $scheduler = new FakeScheduler();
        $provider = $this->asyncProvider();
        $highlighter = new CodeHighlighter($provider, null, null, $scheduler);
        $highlighter->setFile('/x/a.php');

        $highlighter->highlight(['a'], 1);
        $scheduler->fire();

        $this->assertSame(-1, Probe::get($highlighter, 'inFlightVersion'));
    }

    /** disarmAnswerDeadline() is safe even if forced to run with a timer set but no scheduler wired. */
    public function testDisarmingWithATimerSetButNoSchedulerDoesNotCrash(): void
    {
        $highlighter = new CodeHighlighter($this->asyncProvider());
        $highlighter->setFile('/x/a.php');
        Probe::set($highlighter, 'timeoutTimer', 'orphaned-timer-id');

        Probe::invoke($highlighter, 'disarmAnswerDeadline');

        $this->assertNull(Probe::get($highlighter, 'timeoutTimer'));
    }
}
