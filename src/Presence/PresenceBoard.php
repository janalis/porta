<?php

declare(strict_types=1);

namespace Porta\Presence;

/**
 * Where the Porta instances on one project leave their {@see Beacon}s for
 * each other: a directory of one file per instance, here or on the server.
 *
 * Every answer comes through a callback, because a server's comes back
 * later; and a board that cannot be reached says so with a string, which
 * is what tells "out of touch" from "nobody else here".
 */
abstract class PresenceBoard
{
    /** The directory the beacons are in, relative to the project. */
    public const string DIR = '.porta/presence';

    /**
     * How the others reach these files, as a beacon says it, then how often
     * to look, and how often to say "still here" with nothing new to say —
     * in seconds. A server is asked much less often than a disk.
     *
     * @return array{via: string, tick: float, heartbeat: float}
     */
    abstract public function cadence(): array;

    /**
     * Leave $beacon on the board, replacing this instance's last.
     *
     * @param \Closure(?string): void $done null once written, else why not
     */
    abstract public function publish(Beacon $beacon, \Closure $done): void;

    /**
     * Every beacon on the board — this instance's own too.
     *
     * @param \Closure(list<Beacon>|string): void $done the beacons, or why the board could not be read
     */
    abstract public function read(\Closure $done): void;

    /** Take beacon $id off the board — this instance's own when it leaves, or one long gone quiet. Best effort: nobody waits for it. */
    abstract public function withdraw(string $id): void;

    /** Let go of whatever the board holds open: the project is closing, and nothing in flight is waited for. */
    public function close(): void {}

    protected static function file(string $id): string
    {
        return $id.'.json';
    }

    /** The id a board's file $name is the beacon of, or null when it is not one. */
    protected static function idOf(string $name): ?string
    {
        $id = \str_ends_with($name, '.json') ? \substr($name, offset: 0, length: -5) : '';

        return Beacon::isId($id) ? $id : null;
    }
}
