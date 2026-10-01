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
     * Stop what is in flight: the project is closing, and its callbacks need
     * not run. {@see CommandTransport} does it for a transport of processes.
     */
    abstract public function close(): void;
}
