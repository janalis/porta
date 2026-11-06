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

    /**
     * The line ending a file's bytes are written with: the FIRST one decides,
     * because a mixed file has no majority worth honouring and the one it
     * opens with is the one a reader saw — not whichever kind appears
     * anywhere: one pasted CRLF line, or a stray CR, rewrote every line of the
     * file on its next save. A file with no break at all is `\n`.
     */
    public static function ending(string $bytes): string
    {
        $ending = \substr($bytes, \strcspn($bytes, characters: "\r\n"), length: 2);

        return match (true) {
            "\r\n" === $ending => "\r\n",
            \str_starts_with($ending, "\r") => "\r",
            default => "\n",
        };
    }

    /** $text, held with `\n` breaks, as a file ending its lines with $eol writes it. */
    public static function inEnding(string $text, string $eol): string
    {
        return "\n" === $eol ? $text : \str_replace("\n", replace: $eol, subject: $text);
    }
}
