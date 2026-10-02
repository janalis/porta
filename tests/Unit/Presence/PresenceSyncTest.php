<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Presence;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Event\AgentActivity;
use Porta\Event\AgentLeft;
use Porta\Filesystem\ProjectLocation;
use Porta\Filesystem\RemoteSpec;
use Porta\Filesystem\RemoteTransport;
use Porta\Filesystem\RemoteTransportSource;
use Porta\Presence\Beacon;
use Porta\Presence\Identity;
use Porta\Presence\Participant;
use Porta\Presence\Participants;
use Porta\Presence\PresenceBoard;
use Porta\Presence\PresenceSync;
use Porta\Tests\Support\FakeRemoteTransport;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RecordingEvents;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestParticipants;
use Porta\Workbench\AgentActivityKind;
use Revolt\EventLoop;

/**
 * The other Porta instances on the project, as one sees them through the
 * board: who is where, who went quiet, and what a lost link does.
 */
final class PresenceSyncTest extends TestCase
{
    private const string ADA = 'aaaaaaaaaaaaaaaa';
    private const string BO = 'bbbbbbbbbbbbbbbb';

    private TempProject $project;

    private string $root;

    private BufferManager $buffers;

    private RecordingEvents $events;

    private FakeScheduler $scheduler;

    private Participants $participants;

    private Identity $identity;

    private float $now = 1_000.0;

    private function sync(?ProjectLocation $location = null, ?RemoteTransport $transport = null): PresenceSync
    {
        $this->project = new TempProject(['src/A.php' => "<?php\n\$a = 1;\n\$b = 2;\n", 'img.png' => '']);
        $this->root = (string) \realpath($this->project->dir);
        $this->buffers = new BufferManager();
        $this->events = new RecordingEvents();
        $this->scheduler = new FakeScheduler();
        $this->identity = new Identity();
        $this->identity->rename('Me');
        $this->participants = TestParticipants::create(scheduler: $this->scheduler, rootDir: $this->root, identity: $this->identity);

        return new PresenceSync(
            $location ?? new ProjectLocation(),
            new readonly class($transport) implements RemoteTransportSource {
                public function __construct(
                    private ?RemoteTransport $transport,
                ) {}

                public function remoteTransport(RemoteSpec $spec): ?RemoteTransport
                {
                    return $this->transport;
                }
            },
            $this->project->dir,
            $this->buffers,
            $this->participants,
            $this->identity,
            $this->events,
            fn (): float => $this->now,
        );
    }

    private function peer(string $id, string $name, ?string $path = 'src/A.php', int $line = 1, int $beat = 0): void
    {
        $this->project->write(PresenceBoard::DIR.'/'.$id.'.json', new Beacon($id, $name, 'local', $path, $line, 2, -1, 0, 0 === $beat ? \time() : $beat)->toJson());
    }

    /** @return list<string> what core was told to show, in order */
    private function shown(): array
    {
        $shown = [];
        foreach ($this->events->dispatched as $event) {
            if ($event instanceof AgentActivity) {
                $shown[] = '+'.$event->agent.' '.$event->name.' '.\substr($event->path, \strlen($this->root) + 1).':'.$event->line;
            }
            if ($event instanceof AgentLeft) {
                $shown[] = '-'.$event->agent;
            }
        }
        $this->events->dispatched = [];

        return $shown;
    }

    /** @return list<string> */
    private function roster(): array
    {
        return \array_map(static fn (Participant $p): string => $p->name, $this->participants->all());
    }

    private function mine(PresenceSync $sync): ?Beacon
    {
        $file = $this->project->path(PresenceBoard::DIR.'/'.Probe::get($sync, 'id').'.json');

        return \is_file($file) ? Beacon::fromJson((string) \file_get_contents($file)) : null;
    }

    public function testTheOthersAreShownWhereTheyAreAndTheirCaretFollowsThem(): void
    {
        $sync = $this->sync();
        $this->peer(self::ADA, 'Ada');
        $this->peer(self::BO, 'Bo', path: null);

        $sync->tick();
        $this->assertSame(['+peer:'.self::ADA.' Ada src/A.php:1'], $this->shown(), 'one with no file open has no caret to show…');
        $this->assertSame(['Ada', 'Bo'], $this->roster(), '…and is in the roster all the same');
        $this->assertSame([$this->root.'/src/A.php', null], \array_map(static fn (Participant $p): ?string => $p->path, $this->participants->all()));

        $sync->tick();
        $this->assertSame([], $this->shown(), 'nothing moved: nothing said again');

        $this->peer(self::ADA, 'Ada', line: 2);
        $sync->tick();
        $this->assertSame(['-peer:'.self::ADA, '+peer:'.self::ADA.' Ada src/A.php:2'], $this->shown());

        $this->peer(self::ADA, 'Ada', path: 'img.png', line: -1);
        $sync->tick();
        $this->assertSame(['-peer:'.self::ADA, '+peer:'.self::ADA.' Ada img.png:-1'], $this->shown(), 'off the last file first, or its caret stays there');

        $this->project->write(PresenceBoard::DIR.'/'.self::ADA.'.json', '');
        $sync->tick();
        $this->assertSame(['-peer:'.self::ADA], $this->shown(), 'gone from the board: gone');
        $this->assertSame(['Bo'], $this->roster());
    }

    public function testTheCaretIsShownAsTheEditorHasIt(): void
    {
        $sync = $this->sync();
        $this->peer(self::ADA, 'Ada');
        $sync->tick();
        $event = $this->events->take(AgentActivity::class)[0];
        $this->assertEquals(new AgentActivity('peer:'.self::ADA, 'Ada', $this->root.'/src/A.php', AgentActivityKind::Reading, 1, 2, 86_400.0, -1, 0), $event);
    }

    public function testThisSideSaysWhereItIsOnNewsAndOnEveryHeartbeat(): void
    {
        $sync = $this->sync();
        $sync->tick();
        $mine = $this->mine($sync);
        $this->assertInstanceOf(Beacon::class, $mine);
        $this->assertSame(['Me', 'local', null, -1], [$mine->name, $mine->via, $mine->path, $mine->line], 'no file open: here, nowhere in particular');
        $this->assertSame([], $this->roster(), 'never its own participant');

        $document = $this->buffers->open($this->root.'/src/A.php', "<?php\n\$a = 1;\n\$b = 2;\n")->document;
        $document->setSelectionRange(2, 3, 1, 1);
        $sync->tick();
        $mine = $this->mine($sync);
        $this->assertInstanceOf(Beacon::class, $mine);
        $this->assertSame(['src/A.php', 1, 1, 2, 3], [$mine->path, $mine->line, $mine->column, $mine->anchorLine, $mine->anchorColumn], 'selected upwards: the anchor is the end');
        $document->setSelectionRange(1, 1, 2, 3);
        $sync->tick();
        $mine = $this->mine($sync);
        $this->assertInstanceOf(Beacon::class, $mine);
        $this->assertSame([2, 3, 1, 1], [$mine->line, $mine->column, $mine->anchorLine, $mine->anchorColumn], 'selected downwards: the anchor is the start');

        \unlink($this->project->path(PresenceBoard::DIR.'/'.Probe::get($sync, 'id').'.json'));
        $this->now += 4.9;
        $sync->tick();
        $this->assertNull($this->mine($sync), 'no news, no heartbeat due: nothing written');
        $this->now += 0.1;
        $sync->tick();
        $this->assertInstanceOf(Beacon::class, $this->mine($sync), 'a heartbeat');

        $this->buffers->openPane($this->root.'/img.png', 'image', null);
        $sync->tick();
        $this->assertSame(['img.png', -1], [$this->mine($sync)?->path, $this->mine($sync)?->line], 'a viewer: the file, no caret');
        $this->buffers->open('/elsewhere/B.php', '');
        $sync->tick();
        $this->assertNull($this->mine($sync)?->path, 'outside the project: not theirs to know');
        $this->buffers->open($this->root.'/.porta/notes.md', '');
        $sync->tick();
        $this->assertNull($this->mine($sync)?->path, 'nor Porta\'s own files');

        $this->identity->rename('Me, renamed');
        $sync->tick();
        $this->assertSame('Me, renamed', $this->mine($sync)?->name, 'a new name is news');
    }

    public function testOneWhoGoesQuietLeavesAndIsForgottenLater(): void
    {
        $sync = $this->sync();
        $this->peer(self::ADA, 'Ada', beat: 1);
        $sync->tick();
        $this->assertSame(['Ada'], $this->roster(), 'its writer\'s clock may be any distance from this one: first seen is seen now');
        $this->assertFileExists($this->project->path(PresenceBoard::DIR.'/'.self::ADA.'.json'), 'and not wiped for being behind');

        $this->peer(self::ADA, 'Ada');
        $sync->tick();
        $this->shown();
        $this->now += 15.0;
        $sync->tick();
        $this->assertSame(['Ada'], $this->roster(), 'three heartbeats is not yet too many');
        $this->now += 0.1;
        $sync->tick();
        $this->assertSame([], $this->roster(), 'its writer\'s clock says nothing: its beat stopped moving, as seen here');
        $this->assertSame(['-peer:'.self::ADA], $this->shown());
        $sync->tick();
        $this->assertSame([], $this->shown(), 'and stays gone');

        $this->now += PresenceSync::FORGET;
        $sync->tick();
        $this->assertFileDoesNotExist($this->project->path(PresenceBoard::DIR.'/'.self::ADA.'.json'), 'quiet for ten minutes: taken off the board');
    }

    public function testACaretMovedWithinTheSameBeatIsASignOfLifeToo(): void
    {
        $sync = $this->sync();
        $beat = \time();
        $this->peer(self::ADA, 'Ada', beat: $beat);
        $sync->tick();
        $this->now += 15.0;
        $this->peer(self::ADA, 'Ada', line: 2, beat: $beat);
        $sync->tick();
        $this->now += 15.0;
        $sync->tick();
        $this->assertSame(['Ada'], $this->roster(), 'quiet for fifteen seconds since she moved, not thirty');
        $this->now += 0.1;
        $sync->tick();
        $this->assertSame([], $this->roster());
    }

    public function testALostLinkKeepsTheOthersAsLastSeenUntilItIsBack(): void
    {
        $sync = $this->sync();
        $this->peer(self::ADA, 'Ada');
        $sync->tick();
        $this->shown();
        $dir = $this->project->path(PresenceBoard::DIR);

        \chmod($dir, permissions: 0o300);
        try {
            $this->now += 60.0;
            $sync->tick();
            $this->assertSame(['-peer:'.self::ADA], $this->shown(), 'where they were is no longer where they are');
            $this->assertSame(['Ada'], $this->roster(), 'but they are not gone');
            $this->assertTrue($this->participants->isOutOfTouch(PresenceSync::SOURCE));
            $sync->tick();
            $this->assertSame([], $this->shown());
            $this->assertCount(1, $this->scheduler->scheduled, 'lost once, however long');
        } finally {
            \chmod($dir, permissions: 0o700);
        }

        \unlink($dir.'/'.Probe::get($sync, 'id').'.json');
        $this->now += 1.0;
        $sync->tick();
        $this->assertSame(['+peer:'.self::ADA.' Ada src/A.php:1'], $this->shown(), 'back, though their beat did not move while this side was away');
        $this->assertFalse($this->participants->isOutOfTouch(PresenceSync::SOURCE));
        $sync->tick();
        $this->assertInstanceOf(Beacon::class, $this->mine($sync), 'and this side says where it is at once, heartbeat or not: the others stopped hearing it');
    }

    public function testALinkLostForTooLongTakesThemOut(): void
    {
        $sync = $this->sync();
        $this->peer(self::ADA, 'Ada');
        $sync->tick();
        \chmod($this->project->path(PresenceBoard::DIR), permissions: 0o300);
        try {
            $sync->tick();
            $this->scheduler->fire();
            $this->assertSame([], $this->roster());
        } finally {
            \chmod($this->project->path(PresenceBoard::DIR), permissions: 0o700);
        }
    }

    public function testNotSharingTakesThisSideOffTheBoardAndStillSeesTheOthers(): void
    {
        $sync = $this->sync();
        $this->peer(self::ADA, 'Ada');
        $sync->tick();
        $this->assertInstanceOf(Beacon::class, $this->mine($sync));

        $this->identity->share(false);
        $sync->tick();
        $this->assertNull($this->mine($sync));
        $this->assertSame(['Ada'], $this->roster());
        $sync->tick();
        $this->assertNull($this->mine($sync), 'nor put back');
        $sync->stop();

        $this->identity->share(true);
        $sync->tick();
        $this->assertInstanceOf(Beacon::class, $this->mine($sync));
    }

    public function testStoppingTakesThisSideOffTheBoardAndTheTimersOut(): void
    {
        $sync = $this->sync();
        $sync->start();
        $this->assertCount(2, Probe::get($sync, 'timers'));
        foreach (Probe::get($sync, 'timers') as $timer) {
            $this->assertFalse(EventLoop::isReferenced($timer), 'a look never keeps the loop alive');
        }
        $this->peer(self::ADA, 'Ada');
        $sync->tick();
        $this->assertInstanceOf(Beacon::class, $this->mine($sync));
        $this->shown();

        $sync->stop();
        $this->assertSame([], Probe::get($sync, 'timers'));
        $this->assertNull($this->mine($sync));
        $this->assertSame(['-peer:'.self::ADA], $this->shown(), 'the others\' carets go with it: nothing of theirs outlives this side');
    }

    public function testARemoteProjectSharesTheServersBoardAndNeverLooksTwiceAtOnce(): void
    {
        $server = new TempProject();
        $transport = new FakeRemoteTransport($server->dir);
        $sync = $this->sync(new ProjectLocation(new RemoteSpec('ftp', 'example.org', '/srv')), $transport);
        $server->write(PresenceBoard::DIR.'/'.self::ADA.'.json', new Beacon(self::ADA, 'Ada', 'ssh', 'src/A.php', 0, 0, -1, 0, \time())->toJson());
        $transport->hold = true;

        $sync->tick();
        $sync->tick();
        $this->assertSame(['mkdir .porta/presence'], $transport->calls, 'one look at a time');
        $transport->release();
        $this->assertSame(['Ada'], $this->roster());
        $mine = Beacon::fromJson((string) \file_get_contents($server->path(PresenceBoard::DIR.'/'.Probe::get($sync, 'id').'.json')));
        $this->assertSame('ftp', $mine?->via);
        $this->assertSame([10.0], [Probe::get($sync, 'board')->cadence()['tick']]);

        $transport->calls = [];
        $transport->hold = true;
        $release = EventLoop::delay(0.05, $transport->release(...));
        $started = \microtime(true);
        $sync->stop();
        EventLoop::cancel($release);
        $this->assertLessThan(PresenceSync::LEAVE_WAIT, \microtime(true) - $started, 'waited for the answer, not the time limit');
        $this->assertSame(['remove .porta/presence/'.Probe::get($sync, 'id').'.json'], $transport->calls);
        $this->assertSame([self::ADA.'.json'], \array_values(\array_diff((array) \scandir($server->path(PresenceBoard::DIR)), ['.', '..'])), 'off the server before the session ends');
        $this->assertTrue($transport->closed);
    }

    public function testLeavingWaitsForAServerThatNeverAnswersOnlySoLong(): void
    {
        $server = new TempProject();
        $transport = new FakeRemoteTransport($server->dir);
        $sync = $this->sync(new ProjectLocation(new RemoteSpec('ssh', 'example.org', '/srv')), $transport);
        $sync->tick();
        $transport->hold = true;

        $started = \microtime(true);
        $sync->stop();

        $this->assertGreaterThanOrEqual(PresenceSync::LEAVE_WAIT, \microtime(true) - $started);
        $this->assertLessThan(PresenceSync::LEAVE_WAIT + 1.0, \microtime(true) - $started);
        $this->assertTrue($transport->closed, 'what was in flight is let go');

        // The answer, late: leaving no longer waits, and it must not resume
        // a suspension nobody waits on any more.
        $transport->release();
        $this->assertSame([], $transport->held);
    }

    public function testWhileTheServerCannotBeReachedLooksAreSpacedOut(): void
    {
        $server = new TempProject();
        $transport = new FakeRemoteTransport($server->dir);
        $sync = $this->sync(new ProjectLocation(new RemoteSpec('ssh', 'example.org', '/srv')), $transport);
        $transport->down = 'Network is unreachable';
        $looks = static fn (): int => \count(\array_filter($transport->calls, static fn (string $call): bool => \str_starts_with($call, 'list ')));

        $sync->tick();
        $this->assertSame(1, $looks());
        $this->now += 0.5;
        $sync->tick();
        $this->assertSame(1, $looks(), 'not every tick: each is a login');
        $this->now += 0.6;
        $sync->tick();
        $this->assertSame(2, $looks(), 'a second later, yes');
        $this->now += 1.5;
        $sync->tick();
        $this->assertSame(2, $looks(), 'and then two seconds after that, not one');

        $transport->down = null;
        $this->now += 0.6;
        $sync->tick();
        $this->now += 0.1;
        $sync->tick();
        $this->assertSame(4, $looks(), 'back: every tick again');
        $sync->stop();
    }

    public function testALookThatThrowsIsNotTheLast(): void
    {
        $sync = $this->sync();
        $this->buffers->open($this->root."/src/\xff.php", "x\n");

        try {
            $sync->tick();
        } catch (\JsonException) {
            $this->fail('a path that is not UTF-8 is shared, not thrown on');
        }

        $this->assertInstanceOf(Beacon::class, $this->mine($sync));
        $this->assertFalse(Probe::get($sync, 'busy'), 'and the next look goes out');
    }

    public function testALookThatThrowsLeavesTheNextOneFree(): void
    {
        $server = new TempProject();
        $transport = new FakeRemoteTransport($server->dir);
        $sync = $this->sync(new ProjectLocation(new RemoteSpec('ssh', 'example.org', '/srv')), $transport);
        $transport->throws = new \LogicException('a bug in a transport');

        try {
            $sync->tick();
            $this->fail('what threw is still heard');
        } catch (\LogicException $error) {
            $this->assertSame('a bug in a transport', $error->getMessage());
        }

        $this->assertFalse(Probe::get($sync, 'busy'), 'not frozen for the session');
        $sync->tick();
        $this->assertContains('list .porta/presence', $transport->calls);
        $sync->stop();
    }
}
