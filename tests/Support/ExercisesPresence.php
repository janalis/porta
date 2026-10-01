<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use PHPUnit\Framework\Assert;
use Porta\Event\AgentActivity;
use Porta\Event\AgentLeft;
use Porta\Presence\PresenceSync;

/**
 * Presence over a real server: two Porta instances on one server project,
 * each looking through a transport of its own, held to what a person would
 * see — each other by name, a caret that moves, a server that goes away and
 * comes back, one who goes quiet. Shared, so ssh and FTP answer the same.
 */
trait ExercisesPresence
{
    use PumpsLoop;

    /** One look by $peer, waited for: what it said reached the server, and what the server holds is read. */
    private function look(PresencePeer $peer): void
    {
        $peer->sync->tick();
        $this->await(static fn (): bool => !$peer->looking(), what: 'a look at the server');
    }

    private function assertTwoInstancesSeeEachOther(PresencePeer $ada, PresencePeer $bo): void
    {
        $ada->at('src/A.php', 1);
        $this->look($ada);
        $this->look($bo);
        Assert::assertSame(['Ada' => 1], $bo->sees(), 'Bo sees Ada, on her line');
        Assert::assertSame((string) \realpath($bo->cache->path('src/A.php')), $bo->roster()[0]->path, 'in Bo\'s copy of her file');
        Assert::assertSame('peer:', \substr($bo->events->take(AgentActivity::class)[0]->agent, offset: 0, length: 5), 'her caret is drawn');
        $this->look($ada);
        Assert::assertSame(['Bo' => -1], $ada->sees(), 'Ada sees Bo, who has no file open');

        // One line down — likely in the same second, at the same size: the
        // listing's stamp cannot tell, and a look once it settled must.
        $ada->at('src/A.php', 2);
        $this->look($ada);
        $this->await(function () use ($bo): bool {
            $this->look($bo);

            return ['Ada' => 2] === $bo->sees();
        }, what: 'Bo to see Ada move');
    }

    private function assertALostServerKeepsTheOthersAsLastSeen(PresencePeer $ada, PresencePeer $bo, DummyServer $server): void
    {
        $bo->events->dispatched = [];
        $server->stop();
        $this->look($bo);
        Assert::assertTrue($bo->participants->isOutOfTouch(PresenceSync::SOURCE), 'out of touch');
        Assert::assertSame(['Ada' => 2], $bo->sees(), 'Ada stays, as last seen');
        Assert::assertCount(1, $bo->events->take(AgentLeft::class), 'without her caret: where she was is not where she is');

        $server->start();
        $this->look($bo);
        Assert::assertFalse($bo->participants->isOutOfTouch(PresenceSync::SOURCE), 'back in touch');
        Assert::assertSame(['Ada' => 2], $bo->sees());
        Assert::assertCount(1, $bo->events->take(AgentActivity::class), 'and her caret with her');
        $this->look($bo);
        $this->look($ada);
        Assert::assertArrayHasKey('Bo', $ada->sees(), 'Bo said where he is again');

        $server->stop();
        $this->look($bo);
        $bo->scheduler->fire();
        Assert::assertSame([], $bo->sees(), 'lost for longer than the grace: Ada is let go');
        $server->start();
        $this->look($bo);
        Assert::assertSame(['Ada' => 2], $bo->sees(), 'and found again when the server is back');
    }

    private function assertOneWhoGoesQuietIsGone(PresencePeer $ada, PresencePeer $bo, float $heartbeat): void
    {
        $this->look($bo);
        Assert::assertArrayHasKey('Ada', $bo->sees());
        $bo->wait((PresenceSync::STALE_BEATS * $heartbeat) + 1.0);
        $this->look($bo);
        Assert::assertSame([], $bo->sees(), 'Ada stopped saying she is here: three heartbeats, and she is gone');

        $ada->at('src/A.php', 3);
        $this->look($ada);
        $this->await(function () use ($bo): bool {
            $this->look($bo);

            return ['Ada' => 3] === $bo->sees();
        }, what: 'Ada back when she speaks again');
    }
}
