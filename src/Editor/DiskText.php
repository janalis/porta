<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * A file's bytes as a {@see Document} holds them: no byte-order mark, `\n`
 * endings. The file keeps both — the document remembers them for the save —
 * so a question of "is the buffer what the disk holds?" is asked of this, or a
 * CRLF or BOM file never matches its own buffer.
 */
final class DiskText
{
    public static function asHeld(string $bytes): string
    {
        $text = \str_starts_with($bytes, Document::BOM) ? \substr($bytes, offset: \strlen(Document::BOM)) : $bytes;

        return \str_replace(["\r\n", "\r"], replace: "\n", subject: $text);
    }
}
