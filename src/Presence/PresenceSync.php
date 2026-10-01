<?php

declare(strict_types=1);

namespace Porta\Presence;

use Porta\Container\AsIdeService;
use Porta\Editor\BufferManager;
use Porta\Event\AgentActivity;
use Porta\Event\AgentLeft;
use Porta\Filesystem\ProjectLocation;
use Porta\Filesystem\RemoteTransportSource;
use Porta\Workbench\AgentActivityKind;
use Psr\EventDispatcher\EventDispatcherInterface;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;

/**
 * The other Porta instances on this project, found through the
 * {@see PresenceBoard} they all share — this folder's `.porta/presence/`, or
 * the server's when the project is remote — and shown as everyone else at
 * work is: a caret in their colour with their name ({@see AgentActivity}),
 * and a row in the {@see Participants} roster.
 *
 * Each look ({@see tick()}) first says where this side is — only when that
 * changed, or every heartbeat when it did not — then reads what the others
 * said. Nobody's clock is trusted but this one's: a beacon is alive while
 * it keeps CHANGING — its beat, or anything else in it — as this side sees
 * it, so two machines a minute apart still see each other. One quiet for
 * {@see self::STALE_BEATS} heartbeats is gone; one quiet for
 * {@see self::FORGET} seconds is taken off the board by whoever notices, so
 * a crashed instance does not linger there.
 *
 * A board that cannot be read is a lost link, not an empty room: the others
 * stay in the roster as last seen ({@see Participants::outOfTouch()}), their
 * carets go — where they were is no longer where they are — and the next
 * good read puts them back. While the link is lost, the others are not
 * hearing this side either, so the first good look says where it is again.
 */
#[AsIdeService]
final class PresenceSync
{
    /** How many heartbeats a beacon may miss before its Porta is gone. */
    public const int STALE_BEATS = 3;

    /** How long, in seconds, before a beacon nobody refreshed is taken off the board. */
    public const int FORGET = 600;

    /** How long, at most, leaving waits for the board to answer, in seconds. */
    public const float LEAVE_WAIT = 2.0;

    /** What the roster knows this source by. */
    public const string SOURCE = 'presence';

    /** What core's agent presence knows each instance by, before its id. */
    private const string AGENT = 'peer:';

    /** Shown until it leaves: this class says when, not a timer. */
    private const float FADE = 86_400.0;

    private readonly PresenceBoard $board;

    private readonly string $root;

    private readonly string $id;

    /** What was last said, beat aside — null when nothing is on the board for this side. */
    private ?string $said = null;

    private float $saidAt = 0.0;

    private bool $busy = false;

    /** Whether this side's beacon is on the board, as far as it knows. */
    private bool $onBoard = false;

    /** @var array<string, array{beacon: Beacon, seen: float}> id => what it said last, and when its beat last moved — the quiet ones too, so going quiet is not forgotten */
    private array $heard = [];

    /** @var array<string, true> the ones in the roster: not quiet for too long */
    private array $live = [];

    /** @var array<string, string> id => the caret last shown for it */
    private array $shown = [];

    /** Whether the board answered nothing but trouble since its last good read. */
    private bool $lost = false;

    /** Whether the board answered this side's leaving — or leaving stopped waiting. */
    private bool $answered = false;

    /** @var Suspension<mixed>|null what leaving waits on, while it does */
    private ?Suspension $leaving = null;

    /** @var list<string> the loop's timers */
    private array $timers = [];

    /**
     * @param (\Closure(): float)|null $clock seconds, this machine's; the loop's own by default
     */
    public function __construct(
        ProjectLocation $location,
        RemoteTransportSource $transports,
        string $rootDir,
        private readonly BufferManager $buffers,
        private readonly Participants $participants,
        private readonly Identity $identity,
        private readonly EventDispatcherInterface $events,
        private readonly ?\Closure $clock = null,
    ) {
        $this->root = ($real = \realpath($rootDir)) ? $real : $rootDir;
        $spec = $location->remote;
        $this->board = null === $spec ? new LocalPresenceBoard($this->root) : new RemotePresenceBoard($spec, $transports);
        $this->id = Beacon::newId();
    }

    /** Look now and every tick from now on. */
    public function start(): void
    {
        $this->timers = [
            EventLoop::defer($this->tick(...)),
            EventLoop::repeat($this->board->cadence()['tick'], $this->tick(...)),
        ];
        foreach ($this->timers as $timer) {
            EventLoop::unreference($timer);
        }
    }

    /**
     * Stop looking, take the others' carets off the screen, and this side off
     * the board: it is leaving.
     *
     * Called once the IDE's loop has stopped, which is too late for a server
     * to hear anything: so the loop is run again here, for at most
     * {@see self::LEAVE_WAIT} seconds, until the board answers — the others
     * then see this side go at their next look, not three heartbeats later.
     * The screen is already let go of, so nothing it runs meanwhile paints.
     * A board that answers at once (this disk's) is not waited for at all.
     */
    public function stop(): void
    {
        foreach ($this->timers as $timer) {
            EventLoop::cancel($timer);
        }
        $this->timers = [];
        foreach (\array_keys($this->shown) as $id) {
            $this->hide($id);
        }
        $this->answered = false;
        $this->withdraw($this->answered(...));
        if (!$this->answered) {
            $this->leaving = EventLoop::getSuspension();
            $timeout = EventLoop::delay(self::LEAVE_WAIT, $this->answered(...));
            $this->leaving->suspend();
            EventLoop::cancel($timeout);
            $this->leaving = null;
        }
        $this->board->close();
    }

    /** One look: say where this side is, then read where the others are. Never two at once. */
    public function tick(): void
    {
        if ($this->busy) {
            return;
        }
        $this->busy = true;
        $this->say(function (): void {
            $this->board->read(
                /** @param list<Beacon>|string $beacons */
                function (array|string $beacons): void {
                    $this->busy = false;
                    \is_string($beacons) ? $this->lose() : $this->heard($beacons);
                },
            );
        });
    }

    /**
     * Put this side's beacon on the board when there is news, or a heartbeat
     * is due — or take it off when it is not to be shared — then $then.
     *
     * @param \Closure(): void $then
     */
    private function say(\Closure $then): void
    {
        if (!$this->identity->sharing()) {
            $this->withdraw();
            $then();

            return;
        }
        $beacon = $this->beacon();
        $news = \json_encode([$beacon->name, $beacon->path, $beacon->line, $beacon->column, $beacon->anchorLine, $beacon->anchorColumn], \JSON_THROW_ON_ERROR);
        if ($news === $this->said && ($this->now() - $this->saidAt) < $this->board->cadence()['heartbeat']) {
            $then();

            return;
        }
        $this->board->publish($beacon, function (?string $error) use ($news, $then): void {
            // A beacon that did not land is said again next time.
            $this->said = null === $error ? $news : null;
            $this->saidAt = $this->now();
            $this->onBoard = $this->onBoard || null === $error;
            $then();
        });
    }

    /** @param list<Beacon> $beacons */
    private function heard(array $beacons): void
    {
        $now = $this->now();
        $stale = self::STALE_BEATS * $this->board->cadence()['heartbeat'];
        $back = $this->lost;
        $this->lost = false;
        if ($back) {
            // The others may have let this side go meanwhile: said again at once.
            $this->said = null;
        }
        $heard = [];
        $live = [];
        foreach ($beacons as $beacon) {
            if ($this->id === $beacon->id) {
                continue;
            }
            $known = $this->heard[$beacon->id] ?? null;
            // Back from a lost link, every beat seen counts as fresh: the
            // silence was this side's.
            // Anything it says anew is a sign of life — a beat, or a caret
            // moved within the same second as the last beat.
            $seen = null !== $known && !$back && $known['beacon']->toJson() === $beacon->toJson() ? $known['seen'] : $now;
            // Never seen before, and its writer says it has not written for
            // longer than anyone waits: long gone, whatever the clocks say.
            $ancient = null === $known && (\time() - $beacon->beat) > self::FORGET;
            if ($ancient || ($now - $seen) > self::FORGET) {
                $this->board->withdraw($beacon->id);

                continue;
            }
            $heard[$beacon->id] = ['beacon' => $beacon, 'seen' => $seen];
            if (($now - $seen) <= $stale) {
                $live[$beacon->id] = $beacon;
            }
        }
        $this->heard = $heard;
        foreach (\array_keys(\array_diff_key($this->live, $live)) as $id) {
            $this->hide($id);
        }
        $this->live = \array_fill_keys(\array_keys($live), value: true);
        foreach ($live as $id => $beacon) {
            $this->show($id, $beacon);
        }
        $this->participants->set(self::SOURCE, \array_values(\array_map($this->participant(...), $live)));
    }

    /** The board could not be read: the others are as last seen, with no caret, until it can. */
    private function lose(): void
    {
        if ($this->lost) {
            return;
        }
        $this->lost = true;
        $this->said = null;
        foreach (\array_keys($this->shown) as $id) {
            $this->hide($id);
        }
        $this->participants->outOfTouch(self::SOURCE);
    }

    /** $beacon's caret, when it moved since it was last shown. */
    private function show(string $id, Beacon $beacon): void
    {
        $path = null === $beacon->path ? null : $this->root.'/'.$beacon->path;
        $caret = \json_encode([$beacon->name, $path, $beacon->line, $beacon->column, $beacon->anchorLine, $beacon->anchorColumn], \JSON_THROW_ON_ERROR);
        $was = $this->shown[$id] ?? null;
        if ($caret === $was) {
            return;
        }
        // In another file now: off the last one first, or it stays there.
        if (null !== $was) {
            $this->hide($id);
        }
        if (null === $path) {
            return;
        }
        $this->shown[$id] = $caret;
        $this->events->dispatch(new AgentActivity(
            self::AGENT.$id,
            $beacon->name,
            $path,
            AgentActivityKind::Reading,
            $beacon->line,
            $beacon->column,
            self::FADE,
            $beacon->anchorLine,
            $beacon->anchorColumn,
        ));
    }

    private function hide(string $id): void
    {
        if (\array_key_exists($id, $this->shown)) {
            unset($this->shown[$id]);
            $this->events->dispatch(new AgentLeft(self::AGENT.$id));
        }
    }

    private function participant(Beacon $beacon): Participant
    {
        return new Participant($beacon->id, $beacon->name, $beacon->via, null === $beacon->path ? null : $this->root.'/'.$beacon->path, $beacon->line);
    }

    /** Where this side is, as the others are told. */
    private function beacon(): Beacon
    {
        $buffer = $this->buffers->active();
        $path = null === $buffer ? null : $this->relative($buffer->path);
        $line = -1;
        $column = 0;
        $anchorLine = -1;
        $anchorColumn = 0;
        if (null !== $buffer && null !== $path && !$buffer->isViewer()) {
            $document = $buffer->document;
            $line = $document->cursorLine;
            $column = $document->cursorCol;
            $range = $document->getSelectionRange();
            if (null !== $range) {
                // The range says which end comes first, not where it began:
                // the end the caret is not at is the anchor.
                $atStart = [$range['startLine'], $range['startCol']] === [$line, $column];
                $anchorLine = $atStart ? $range['endLine'] : $range['startLine'];
                $anchorColumn = $atStart ? $range['endCol'] : $range['startCol'];
            }
        }

        return new Beacon($this->id, $this->identity->name(), $this->board->cadence()['via'], $path, $line, $column, $anchorLine, $anchorColumn, \time());
    }

    /** $path relative to the project, or null when it is not inside it (or is Porta's own). */
    private function relative(string $path): ?string
    {
        $relative = \str_starts_with($path, $this->root.'/') ? \substr($path, \strlen($this->root) + 1) : null;

        return null === $relative || \str_starts_with($relative, '.porta/') ? null : $relative;
    }

    /**
     * The board answered, or leaving waited long enough — whichever comes
     * first resumes, once: the other may still fire in the same turn, and
     * finds it done.
     */
    private function answered(): void
    {
        if ($this->answered) {
            return;
        }
        $this->answered = true;
        $this->leaving?->resume();
    }

    /** @param (\Closure(): void)|null $done told once the board answered, or at once when nothing of this side is on it */
    private function withdraw(?\Closure $done = null): void
    {
        $this->said = null;
        if (!$this->onBoard) {
            $done?->__invoke();

            return;
        }
        $this->onBoard = false;
        $this->board->withdraw($this->id, $done);
    }

    private function now(): float
    {
        return null === $this->clock ? \microtime(true) : ($this->clock)();
    }
}
