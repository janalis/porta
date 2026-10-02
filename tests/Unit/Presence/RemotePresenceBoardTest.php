<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Presence;

use PHPUnit\Framework\TestCase;
use Porta\Filesystem\RemoteSpec;
use Porta\Filesystem\RemoteTransport;
use Porta\Filesystem\RemoteTransportSource;
use Porta\Presence\Beacon;
use Porta\Presence\PresenceBoard;
use Porta\Presence\RemotePresenceBoard;
use Porta\Tests\Support\FakeRemoteTransport;
use Porta\Tests\Support\TempProject;

/** The board in the server's `.porta/presence/`: as few calls as it can, and a lost server said as such. */
final class RemotePresenceBoardTest extends TestCase
{
    private TempProject $server;

    private FakeRemoteTransport $transport;

    /** How many times the plugins were asked for a transport. */
    private int $asked = 0;

    private float $now = 1_000.0;

    // @mago-expect lint:no-boolean-flag-parameter -- whether a plugin serves the scheme is the one fact a case varies
    private function board(string $scheme = 'ssh', bool $served = true): RemotePresenceBoard
    {
        $this->server = new TempProject();
        $this->transport = new FakeRemoteTransport($this->server->dir);
        $transport = $served ? $this->transport : null;

        return new RemotePresenceBoard(
            new RemoteSpec($scheme, 'example.org', '/srv'),
            new class($transport, $this->asked) implements RemoteTransportSource {
                public function __construct(
                    private readonly ?RemoteTransport $transport,
                    private int &$asked,
                ) {}

                public function remoteTransport(RemoteSpec $spec): ?RemoteTransport
                {
                    ++$this->asked;

                    return $this->transport;
                }
            },
            fn (): float => $this->now,
        );
    }

    private static function beacon(string $id, string $name = 'Ada'): Beacon
    {
        return new Beacon($id, $name, 'ssh', 'a.php', 0, 0, -1, 0, 1_700_000_000);
    }

    /** @return list<Beacon>|string */
    private static function read(PresenceBoard $board): array|string
    {
        $read = 'never answered';
        $board->read(static function (array|string $beacons) use (&$read): void {
            $read = $beacons;
        });

        return $read;
    }

    private static function publish(PresenceBoard $board, Beacon $beacon): ?string
    {
        $answer = 'never answered';
        $board->publish($beacon, static function (?string $error) use (&$answer): void {
            $answer = $error;
        });

        return $answer;
    }

    /** @param list<Beacon>|string $read */
    private static function names(array|string $read): array
    {
        return \is_string($read) ? [$read] : \array_map(static fn (Beacon $b): string => $b->name, $read);
    }

    public function testTheDirectoryIsMadeOnceAndOnlyMovedBeaconsAreFetched(): void
    {
        $board = $this->board();

        $this->assertNull(self::publish($board, self::beacon('aaaaaaaaaaaaaaaa')));
        $this->assertSame(['mkdir .porta/presence', 'put .porta/presence/aaaaaaaaaaaaaaaa.json'], $this->transport->calls, 'paths relative to the project, as every verb takes them');
        $this->server->write(PresenceBoard::DIR.'/bbbbbbbbbbbbbbbb.json', self::beacon('bbbbbbbbbbbbbbbb', 'Bo')->toJson());
        $this->server->write(PresenceBoard::DIR.'/README', 'not a beacon');
        \mkdir($this->server->path(PresenceBoard::DIR.'/cccccccccccccccc.json'));
        $this->transport->calls = [];

        $this->assertSame(['Ada', 'Bo'], self::names(self::read($board)));
        $this->assertSame(
            ['list .porta/presence', 'fetch .porta/presence/aaaaaaaaaaaaaaaa.json', 'fetch .porta/presence/bbbbbbbbbbbbbbbb.json'],
            $this->transport->calls,
            'made once; a directory or a stray file is no beacon',
        );

        $this->transport->calls = [];
        $this->assertSame(['Ada', 'Bo'], self::names(self::read($board)));
        $this->assertSame(['list .porta/presence'], $this->transport->calls, 'nothing moved: nothing fetched');

        $this->server->write(PresenceBoard::DIR.'/bbbbbbbbbbbbbbbb.json', self::beacon('bbbbbbbbbbbbbbbb', 'Bo, moved')->toJson());
        $this->transport->calls = [];
        $this->assertSame(['Ada', 'Bo, moved'], self::names(self::read($board)));
        $this->assertSame(['list .porta/presence', 'fetch .porta/presence/bbbbbbbbbbbbbbbb.json'], $this->transport->calls);

        $answered = false;
        $board->withdraw('aaaaaaaaaaaaaaaa', static function () use (&$answered): void {
            $answered = true;
        });
        $this->assertTrue($answered, 'told when the server answered');
        $this->assertSame(['Bo, moved'], self::names(self::read($board)));
        $this->assertSame(1, $this->asked, 'the transport is asked for once');
        $board->close();
        $this->assertTrue($this->transport->closed);
    }

    public function testARewriteThatKeepsTheStampIsCaughtOnceTheStampHasSettled(): void
    {
        $board = $this->board();
        $file = PresenceBoard::DIR.'/aaaaaaaaaaaaaaaa.json';
        $this->server->write($file, new Beacon('aaaaaaaaaaaaaaaa', 'Ada', 'ssh', 'a.php', 1, 0, -1, 0, 1)->toJson());
        \touch($this->server->path($file), mtime: 1_700_000_000);
        $this->assertSame([1], self::lines(self::read($board)));

        // The caret one line down, in the same second: same size, same time.
        $this->server->write($file, new Beacon('aaaaaaaaaaaaaaaa', 'Ada', 'ssh', 'a.php', 2, 0, -1, 0, 1)->toJson());
        \touch($this->server->path($file), mtime: 1_700_000_000);
        $this->now += RemotePresenceBoard::SETTLE - 0.1;
        $this->transport->calls = [];
        $this->assertSame([1], self::lines(self::read($board)), 'not settled yet: what was fetched stands');
        $this->assertSame(['list .porta/presence'], $this->transport->calls);
        $this->now += 0.1;
        $this->assertSame([2], self::lines(self::read($board)), 'settled: fetched once more, and the rewrite is caught');
        $this->transport->calls = [];
        $this->now += 60.0;
        $this->assertSame([2], self::lines(self::read($board)));
        $this->assertSame(['list .porta/presence'], $this->transport->calls, 'and never again while the stamp holds');
    }

    /** @param list<Beacon>|string $read */
    private static function lines(array|string $read): array
    {
        return \is_string($read) ? [$read] : \array_map(static fn (Beacon $b): int => $b->line, $read);
    }

    public function testAnAnswerHeldBackIsWaitedForAndOneThatFailsIsAskedAgain(): void
    {
        $board = $this->board();
        $this->server->write(PresenceBoard::DIR.'/aaaaaaaaaaaaaaaa.json', self::beacon('aaaaaaaaaaaaaaaa')->toJson());
        $this->server->write(PresenceBoard::DIR.'/bbbbbbbbbbbbbbbb.json', self::beacon('bbbbbbbbbbbbbbbb', 'Bo')->toJson());
        $this->transport->hold = true;
        $read = 'never answered';
        $board->read(static function (array|string $beacons) use (&$read): void {
            $read = $beacons;
        });
        $this->transport->release();
        $this->assertSame(['Ada', 'Bo'], self::names($read), 'answered once, when the last fetch came back');

        $this->server->write(PresenceBoard::DIR.'/bbbbbbbbbbbbbbbb.json', self::beacon('bbbbbbbbbbbbbbbb', 'Bo again')->toJson());
        $this->transport->fail[PresenceBoard::DIR.'/bbbbbbbbbbbbbbbb.json'] = 'withdrawn since';
        $this->assertSame(['Ada', 'Bo'], self::names(self::read($board)), 'a fetch that fails keeps what was known');
        $this->assertSame(['Ada', 'Bo again'], self::names(self::read($board)), 'and is asked again');
    }

    public function testAServerThatCannotBeReachedSaysSo(): void
    {
        $board = $this->board();
        $this->transport->fail['*'] = 'connection refused';
        $this->assertNull(self::publish($board, self::beacon('aaaaaaaaaaaaaaaa')), 'a failed mkdir is not the news: what follows it is');
        $this->transport->down = 'connection refused';
        $this->assertSame('connection refused', self::read($board), '…the listing is');
        $this->transport->down = 'disk full';
        $this->assertSame('disk full', self::publish($board, self::beacon('aaaaaaaaaaaaaaaa')));
        $this->transport->down = null;
        $calls = \count($this->transport->calls);
        self::publish($board, self::beacon('aaaaaaaaaaaaaaaa'));
        $this->assertStringStartsWith('mkdir ', $this->transport->calls[$calls], 'a directory never made is asked for again');
    }

    public function testNoTransportYetIsNobodyNotALostLink(): void
    {
        $board = $this->board(served: false);

        $this->assertSame([], self::read($board));
        $this->assertSame('no transport', self::publish($board, self::beacon('aaaaaaaaaaaaaaaa')));
        $answered = false;
        $board->withdraw('aaaaaaaaaaaaaaaa', static function () use (&$answered): void {
            $answered = true;
        });
        $this->assertTrue($answered, 'nobody to ask: answered at once');
        $board->withdraw('aaaaaaaaaaaaaaaa'); // and nobody to tell
        $board->close();
        $this->assertSame(4, $this->asked, 'asked again each time: a plugin may serve it later — and none to close');
    }

    public function testFtpIsAskedLessOftenThanSsh(): void
    {
        $this->assertSame(['via' => 'ssh', 'tick' => 3.0, 'heartbeat' => 15.0], $this->board('sftp')->cadence());
        $this->assertSame(['via' => 'ftp', 'tick' => 10.0, 'heartbeat' => 30.0], $this->board('ftp')->cadence());
        $this->assertSame('ftp', $this->board('ftps')->cadence()['via']);
    }
}
