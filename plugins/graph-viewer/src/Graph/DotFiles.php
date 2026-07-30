<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Graph;

/**
 * What counts as a Graphviz DOT file — the routing predicate
 * {@see \Porta\Editor\BufferService} uses to send a path to the graph viewer
 * instead of the text editor (the {@see \Porta\ImageViewer\ImageDecoder} mirror).
 */
final class DotFiles
{
    public const array EXTENSIONS = ['dot', 'gv'];

    /** Documents past this size are refused rather than laid out. */
    public const int MAX_BYTES = 10 * 1024 * 1024;

    public static function isDotPath(string $path): bool
    {
        return \in_array(strtolower(pathinfo($path, \PATHINFO_EXTENSION)), self::EXTENSIONS, strict: true);
    }
}
