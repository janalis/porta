<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\DotFiles;

/** Shared source bounds for native family lexers. */
final class MermaidSource
{
    public const int MAX_STATEMENT = 65_536;

    /** A leading byte-order mark, as a browser drops it, blanked so no offset moves. */
    public static function unmarked(string $source): string
    {
        return \str_starts_with($source, "\u{FEFF}") ? '   '.\substr($source, offset: 3) : $source;
    }

    public static function validate(string $source): void
    {
        if (\strlen($source) > DotFiles::MAX_BYTES) {
            throw new MermaidSyntaxError('Diagram exceeds the source size limit', offset: 0, sourceLine: 0, column: 0);
        }
        if (!\mb_check_encoding($source, encoding: 'UTF-8')) {
            throw new MermaidSyntaxError('Not a UTF-8 text file', offset: 0, sourceLine: 0, column: 0);
        }
    }
}
