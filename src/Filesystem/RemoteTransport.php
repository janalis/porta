<?php

declare(strict_types=1);

namespace Porta\Filesystem;

/**
 * How a plugin reaches one kind of server — the ssh plugin over ssh, the ftp
 * plugin over curl. Core owns the rest: the cache, what is fetched when, the
 * order uploads go in and what a conflict means. A transport only moves bytes
 * and names, so a new kind of server is one subclass.
 *
 * Every verb answers through its callback, exactly once, from the event loop
 * — never by blocking, since the whole IDE runs on that loop. A failure is a
 * string: the message a person is shown, already in words they can act on.
 *
 * Paths are RELATIVE to the project's directory on the server, `''` being
 * that directory itself; {@see RemoteSpec::remotePath()} turns one into the
 * server's own. A path never holds `..` or a NUL, and core never sends one
 * outside the project — but it may hold anything else a file name can,
 * spaces and quotes included, so a transport that builds a command line
 * quotes every one.
 *
 * Registered with {@see \Porta\Plugin\Api\PluginApi::registerRemoteTransport()}.
 */
abstract class RemoteTransport
{
    /**
     * The entries of directory $path — not `.` and `..`.
     *
     * @param \Closure(list<RemoteEntry>|string): void $done
     */
    abstract public function list(string $path, \Closure $done): void;

    /**
     * The content of file $path.
     *
     * @param \Closure(RemoteFile|string): void $done
     */
    abstract public function fetch(string $path, \Closure $done): void;

    /**
     * Write $bytes to file $path, creating it when it is not there, and
     * answer its new stamp. With $expected, the file must still have that
     * stamp, and when it does not, nothing is written and the answer is null:
     * it changed on the server since it was fetched. A null $expected writes
     * whatever is there.
     *
     * @param \Closure(RemoteStamp|string|null): void $done
     */
    abstract public function put(string $path, string $bytes, ?RemoteStamp $expected, \Closure $done): void;

    /** @param \Closure(?string): void $done null on success */
    abstract public function mkdir(string $path, \Closure $done): void;

    /**
     * Move $from to $to, replacing whatever is at $to.
     *
     * @param \Closure(?string): void $done null on success
     */
    abstract public function rename(string $from, string $to, \Closure $done): void;

    /**
     * Delete $path — with everything in it, when it is a directory.
     *
     * @param \Closure(?string): void $done null on success
     */
    // @mago-expect lint:no-boolean-flag-parameter -- whether it is a directory is the one fact the server needs, not a second responsibility
    abstract public function remove(string $path, bool $isDir, \Closure $done): void;

    /**
     * Every entry under directory $path, in one answer — not descending into
     * a directory named in $skip — each named by its path relative to $path.
     * What fills the cache with the whole tree, so go-to-file finds a file
     * nobody expanded the directory of.
     *
     * Optional: a transport that cannot answers false, and $done is never
     * called; the tree then fills as directories are shown.
     *
     * @param list<string>                             $skip directory names, matched at any depth
     * @param \Closure(list<RemoteEntry>|string): void $done
     */
    public function walk(string $path, array $skip, \Closure $done): bool
    {
        return false;
    }

    /**
     * $argv as a command line that runs it on the server, in the project's
     * directory there, or null when this transport cannot run anything. What
     * lets Find in Files grep the server's files rather than the cache, which
     * holds only the ones opened.
     *
     * @param list<string> $argv
     *
     * @return list<string>|null
     */
    public function remoteCommand(array $argv): ?array
    {
        return null;
    }

    /**
     * Stop what is in flight: the project is closing, and its callbacks need
     * not run. {@see CommandTransport} does it for a transport of processes.
     */
    abstract public function close(): void;
}
