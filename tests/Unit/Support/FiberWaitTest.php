<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Support\FiberWait;
use Porta\Tests\Support\PumpsLoop;
use Revolt\EventLoop;

final class FiberWaitTest extends TestCase
{
    use PumpsLoop;

    public function testAnAnswerThatComesLaterIsWaitedFor(): void
    {
        $fiber = new \Fiber(static fn (): mixed => FiberWait::for(static function (\Closure $done): void {
            EventLoop::delay(0.01, static fn () => $done('later'));
        }));
        $fiber->start();
        $this->assertFalse($fiber->isTerminated(), 'it suspended');

        $this->await($fiber->isTerminated(...), what: 'the delayed answer');

        $this->assertSame('later', $fiber->getReturn());
    }

    public function testAnAnswerGivenAtOnceNeverSuspends(): void
    {
        // Outside any fiber: a suspension here would throw, so passing proves
        // the fast path never asks for one.
        $this->assertSame(['at', 'once'], FiberWait::for(static fn (\Closure $done) => $done(['at', 'once'])));
        $this->assertNull(FiberWait::for(static fn (\Closure $done) => $done(null)), 'a null answer is still an answer');
    }

    public function testTheFirstAnswerWinsSoATimeoutCanRaceTheOperation(): void
    {
        $this->assertSame('first', FiberWait::for(static function (\Closure $done): void {
            $done('first');
            $done('second');
        }));

        $fiber = new \Fiber(static fn (): mixed => FiberWait::for(static function (\Closure $done): void {
            EventLoop::delay(0.01, static fn () => $done('fast'));
            EventLoop::delay(0.03, static fn () => $done('slow'));
        }));
        $fiber->start();
        $this->await($fiber->isTerminated(...), what: 'the first answer');
        $this->pump(0.05); // the late answer arrives, and must not resume anything

        $this->assertSame('fast', $fiber->getReturn());
    }
}
