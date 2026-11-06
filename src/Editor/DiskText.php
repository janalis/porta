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

    /**
     * $document's text as its file is written: the ending and the byte-order
     * mark it was loaded with put back. For a write that is not a buffer's save
     * (which also applies `.editorconfig`): a file nobody opened, edited
     * unseen, keeps the shape it had — not one rewritten line per line.
     */
    public static function asWritten(Document $document): string
    {
        return self::marked(self::inEnding($document->getText(), $document->eol), $document->hasByteOrderMark);
    }

    /**
     * $text, held as a document holds it, written in the shape $bytes have:
     * their ending, and their byte-order mark if they open with one. For text
     * that goes back to whoever sent $bytes — an agent's proposal, kept,
     * returns as it came, not as the buffer holds it.
     */
    public static function inShapeOf(string $text, string $bytes): string
    {
        return self::marked(self::inEnding($text, self::ending($bytes)), \str_starts_with($bytes, Document::BOM));
    }

    // @mago-expect lint:no-boolean-flag-parameter -- the mark is the one fact the call carries
    private static function marked(string $bytes, bool $mark): string
    {
        return $mark ? Document::BOM.$bytes : $bytes;
    }

    /** $text, held with `\n` breaks, as a file ending its lines with $eol writes it. */
    public static function inEnding(string $text, string $eol): string
    {
        return "\n" === $eol ? $text : \str_replace("\n", replace: $eol, subject: $text);
    }
}
