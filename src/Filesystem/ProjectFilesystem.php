<?php

declare(strict_types=1);

namespace Porta\Filesystem;

use Symfony\Component\Filesystem\Exception\IOExceptionInterface;

/**
 * Where the project's files really live. The editor, the tree and search
 * read and write through this rather than straight to disk, because for a
 * remote project the directory Porta opened is only a cache of a server's.
 *
 * Every path is a LOCAL one under the project root — for a remote project, a
 * path in its cache. The cache holds the server's whole tree, a file whose
 * content was never fetched as an empty placeholder, so anything that only
 * lists or stats (the tree's poll, quick-open, the reconciler) needs nothing
 * from here. What reads content asks {@see available()} first, and what
 * changes the tree goes through the verbs below so the server hears of it.
 *
 * The verbs are synchronous and throw like Symfony's Filesystem, whose names
 * they keep: locally they are that, and remotely the cache changes at once
 * and the server's copy follows in the background.
 */
interface ProjectFilesystem
{
    /**
     * Whether $path's content can be read now. When it cannot (a remote file
     * not fetched yet), the fetch starts, false is returned, and $then runs
     * once the content is there — the caller's "try again". A failed fetch
     * says so itself and never calls $then; one that will not come — the
     * server refused it, or nothing can reach the server — calls $failed, so
     * whatever stood in for the file can go. A server merely out of reach
     * is waited for, and then $then runs after all.
     *
     * @param \Closure(): void       $then
     * @param (\Closure(): void)|null $failed
     */
    public function available(string $path, \Closure $then, ?\Closure $failed = null): bool;

    /**
     * Whether $dir's entries are known. When they are not, a listing starts
     * and the entries land in $dir when it answers — which the tree's own
     * poll then notices, as it notices any other change.
     */
    public function listed(string $dir): bool;

    /** @throws IOExceptionInterface */
    public function dumpFile(string $path, string $content): void;

    /** @throws IOExceptionInterface */
    public function mkdir(string $path): void;

    /** @throws IOExceptionInterface */
    // @mago-expect lint:no-boolean-flag-parameter -- Symfony's own signature, kept so a local rename is that call
    public function rename(string $from, string $to, bool $overwrite = false): void;

    /** @throws IOExceptionInterface */
    public function remove(string $path): void;

    /**
     * A file, or a directory with all it holds — over whatever is at $target.
     *
     * @throws IOExceptionInterface
     */
    public function copy(string $source, string $target): void;

    /**
     * How many changes have not reached where the files live yet — what
     * quitting now would lose.
     */
    public function pending(): int;
}
