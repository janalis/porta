<?php

declare(strict_types=1);

namespace Porta\Support;

/**
 * Plain-file sibling of {@see JsonFile}. One method, one idiom: the
 * read-or-empty the search readers and decoders each hand-rolled as
 * `is_file($p) ? (string) file_get_contents($p) : ''`.
 */
final class Files
{
    /**
     * The file's bytes, or '' when it does not exist — or vanished between
     * the check and the read: file_get_contents' false collapses to '' too,
     * which is the point. Callers treating '' as "nothing there" never need
     * the distinction.
     */
    public static function read(string $path): string
    {
        return is_file($path) ? (string) file_get_contents($path) : '';
    }
}
