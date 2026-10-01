<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use PHPUnit\Framework\Assert;
use Porta\Presence\PresenceBoard;

/**
 * The presence tests every transport passes over a real server, as test
 * methods: a class brings the server, two peers on it, and how often its
 * board says "still here" — the rest is {@see ExercisesPresence}'s.
 */
trait ProvesPresenceOverAServer
{
    use ExercisesPresence;

    /** @return array{PresencePeer, PresencePeer} Ada and Bo, each through a transport of their own */
    abstract private function peers(): array;

    abstract private function server(): DummyServer;

    /** The board's heartbeat, in seconds, over this transport. */
    abstract private function heartbeat(): float;

    public function testTwoPortaInstancesOnTheServerSeeEachOther(): void
    {
        [$ada, $bo] = $this->peers();

        $this->assertTwoInstancesSeeEachOther($ada, $bo);
        Assert::assertCount(2, (array) \glob($this->server()->root.'/'.PresenceBoard::DIR.'/*.json'), 'on the server, beside the project: one each');
    }

    public function testALostServerKeepsTheOthersAsLastSeen(): void
    {
        [$ada, $bo] = $this->peers();
        $this->assertTwoInstancesSeeEachOther($ada, $bo);

        $this->assertALostServerKeepsTheOthersAsLastSeen($ada, $bo, $this->server());
    }

    public function testOneWhoQuitsIsGoneAtOnce(): void
    {
        [$ada, $bo] = $this->peers();
        $this->assertTwoInstancesSeeEachOther($ada, $bo);

        $this->assertOneWhoQuitsIsGoneAtOnce($ada, $bo, $this->server());
    }

    public function testOneWhoGoesQuietIsGone(): void
    {
        [$ada, $bo] = $this->peers();
        $this->assertTwoInstancesSeeEachOther($ada, $bo);

        $this->assertOneWhoGoesQuietIsGone($ada, $bo, $this->heartbeat());
    }
}
