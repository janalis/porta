<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Help\Fence;

use PHPUnit\Framework\TestCase;
use Porta\Help\Fence\FenceRenderQueue;
use Porta\Help\Fence\FenceResult;
use Porta\Tests\Support\FakeScheduler;

final class FenceRenderQueueTest extends TestCase
{
    public function testDeferredBoundedWorkAndStaleCompletion(): void
    {
        $scheduler = new FakeScheduler();
        $queue = new FenceRenderQueue($scheduler);
        $started = [];
        $completed = [];
        $cancelled = [];
        $success = [];
        $error = [];
        $cancels = [];
        for ($i = 0; $i < 4; ++$i) {
            $cancels[] = $queue->submit(
                static function (\Closure $ok, \Closure $fail) use ($i, &$started, &$cancelled, &$success, &$error): \Closure {
                    $started[] = $i;
                    $success[$i] = $ok;
                    $error[$i] = $fail;

                    return static function () use ($i, &$cancelled): void {
                        $cancelled[] = $i;
                    };
                },
                static function (FenceResult $result) use ($i, &$completed): void {
                    $completed[] = [$i, $result->rows];
                },
                static function (string $message) use ($i, &$completed): void {
                    $completed[] = [$i, $message];
                },
            );
        }
        $this->assertSame([], $started, 'submitting work cannot invoke a renderer during paint');
        $this->assertCount(1, $scheduler->scheduled);
        $scheduler->fire();
        $this->assertSame([0, 1], $started, 'only two external slots may run');
        $cancels[3](); // queued requests never start
        $cancels[0](); // running requests release their slot
        $cancels[0](); // idempotent
        $success[0](FenceResult::rows(['obsolete'], [0]));
        $error[0]('obsolete');
        $this->assertSame([], $completed);
        $this->assertSame([0], $cancelled);
        $scheduler->fire();
        $this->assertSame([0, 1, 2], $started);
        $success[1](FenceResult::rows(['current'], [1]));
        $error[2]('failed');
        $error[2]('duplicate');
        $cancels[1]();
        $this->assertSame([[1, ['current']], [2, 'failed']], $completed);
        $this->assertSame([], $scheduler->scheduled);
    }

    public function testSynchronousSuccessAndThrowingRendererReleaseSlots(): void
    {
        $scheduler = new FakeScheduler();
        $queue = new FenceRenderQueue($scheduler);
        $results = [];
        $cleaned = 0;
        $queue->submit(
            static function (\Closure $ok) use (&$cleaned): \Closure {
                $ok(FenceResult::rows([], []));

                return static function () use (&$cleaned): void {
                    ++$cleaned;
                };
            },
            static function (FenceResult $result) use (&$results): void {
                $results[] = $result->rows;
            },
            static function (): void {},
        );
        $queue->submit(
            static function (): never {
                throw new \RuntimeException('renderer threw');
            },
            static function (): void {},
            static function (string $message) use (&$results): void {
                $results[] = $message;
            },
        );
        $scheduler->fire();
        $this->assertSame([[], 'renderer threw'], $results);
        $this->assertSame(1, $cleaned);
        // Synchronous completion schedules the next drain while one is active.
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
    }

    public function testCancelBeforeStartRemovesScheduledWork(): void
    {
        $scheduler = new FakeScheduler();
        $queue = new FenceRenderQueue($scheduler);
        $cancel = $queue->submit(
            static function (): never {
                throw new \LogicException('must not start');
            },
            static function (): void {},
            static function (): void {},
        );
        $cancel();
        $this->assertSame([], $scheduler->scheduled);
        $this->assertSame(['1'], $scheduler->cancelled);
    }

    public function testSelfCancellationBeforeHandleArrivesCleansItOnce(): void
    {
        $scheduler = new FakeScheduler();
        $queue = new FenceRenderQueue($scheduler);
        $cancel = null;
        $cleaned = 0;
        $cancel = $queue->submit(
            static function () use (&$cancel, &$cleaned): \Closure {
                $cancel();

                return static function () use (&$cleaned): void {
                    ++$cleaned;
                };
            },
            static function (): void {},
            static function (): void {},
        );
        $scheduler->fire();
        $this->assertSame(1, $cleaned);
        $cancel();
        $this->assertSame(1, $cleaned);
    }
}
