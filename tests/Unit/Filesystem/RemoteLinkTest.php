<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Filesystem;

use PHPUnit\Framework\TestCase;
use Porta\Filesystem\RemoteLink;
use Porta\Tests\Support\FakeRemoteTransport;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\TempProject;

final class RemoteLinkTest extends TestCase
{
    use PumpsLoop;

    /** Held: the server is gone with it. */
    private TempProject $server;

    private FakeRemoteTransport $transport;

    /** @var list<string> */
    private array $heard = [];

    protected function setUp(): void
    {
        $this->server = new TempProject(['a.txt' => 'a']);
        $this->transport = new FakeRemoteTransport($this->server->dir);
    }

    private function link(): RemoteLink
    {
        return new RemoteLink(
            function (string $why): void {
                $this->heard[] = 'lost: '.$why;
            },
            function (array $root): void {
                $this->heard[] = 'back: '.\count($root);
            },
            function (): void {
                $this->heard[] = 'changed';
            },
        );
    }

    public function testFailuresTogetherAreCheckedWithOneListing(): void
    {
        $link = $this->link();
        $this->transport->hold = true;
        $outcomes = [];
        foreach (['one', 'two'] as $name) {
            $link->failed(
                $this->transport,
                'denied',
                static function () use (&$outcomes, $name): void {
                    $outcomes[] = 'retry '.$name;
                },
                static function () use (&$outcomes, $name): void {
                    $outcomes[] = 'refused '.$name;
                },
            );
        }

        $this->transport->release();

        $this->assertSame(['list '], $this->transport->calls, 'one listing for both');
        $this->assertSame(['refused one', 'refused two'], $outcomes, 'the root lists: the server meant it');
        $this->assertNull($link->down());
        $this->assertSame([], $this->heard);
    }

    public function testWhileDownAFailureJustWaits(): void
    {
        $link = $this->link();
        $this->transport->down = 'gone';
        $link->failed($this->transport, 'gone', static function (): void {}, static function (): void {});
        $calls = \count($this->transport->calls);
        $ran = false;

        $link->failed(
            $this->transport,
            'gone',
            static function () use (&$ran): void {
                $ran = true;
            },
            static function (): void {},
        );

        $this->assertCount($calls, $this->transport->calls, 'no listing: it is known to be down');
        $this->assertSame(['lost: gone', 'changed'], $this->heard);
        $this->transport->down = null;
        $link->reconnect($this->transport);
        $this->assertTrue($ran);
        $this->assertSame(['lost: gone', 'changed', 'back: '.\count((array) \glob($this->server->dir.'/*')), 'changed'], $this->heard, 'back, with the root as listed');
    }

    public function testAProbeThatAnswersAfterTheCloseIsIgnored(): void
    {
        $link = $this->link();
        $this->transport->down = 'gone';
        $link->failed($this->transport, 'gone', static function (): void {}, static function (): void {});
        $this->transport->down = null;
        $this->transport->hold = true;
        $link->reconnect($this->transport);

        $link->close();
        $this->transport->release();

        $this->assertNull($link->down());
        $this->assertSame(['lost: gone', 'changed'], $this->heard, 'not "back"');
    }

    public function testReconnectWhileUpDoesNothing(): void
    {
        $link = $this->link();

        $link->reconnect($this->transport);

        $this->assertSame([], $this->transport->calls);
    }

    public function testAFailedProbeSaysTheLatestReasonAndTriesLater(): void
    {
        $link = $this->link();
        $this->transport->down = 'gone';
        $link->failed($this->transport, 'gone', static function (): void {}, static function (): void {});
        $this->transport->down = FakeRemoteTransport::LOGIN_DENIED;

        $this->heard = [];
        $link->reconnect($this->transport);

        $this->assertSame(FakeRemoteTransport::LOGIN_DENIED, $link->down());
        $this->assertTrue($link->loginRefused());
        $this->assertSame(['changed'], $this->heard, 'the cell shows the latest reason');
        $this->assertSame(1, Probe::get($link, 'tries'), 'one more try: the next backoff is longer');

        $this->transport->down = null;
        $link->reconnect($this->transport);
        $this->assertFalse($link->loginRefused(), 'back: nothing refused');
    }

    public function testReconnectWhileTheRootIsBeingListedAsksOnce(): void
    {
        $link = $this->link();
        $this->transport->down = 'gone';
        $link->failed($this->transport, 'gone', static function (): void {}, static function (): void {});
        $this->transport->hold = true;
        $calls = \count($this->transport->calls);

        $link->reconnect($this->transport);
        $link->reconnect($this->transport);

        $this->assertCount($calls + 1, $this->transport->calls, 'one listing at a time: each is a login');
        $this->transport->release();
        $link->close();
    }
}
