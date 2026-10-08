<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * Computes a single minimal LSP incremental content change between two
 * versions of a document by trimming their common prefix and suffix.
 *
 * This lets the IDE send `textDocument/didChange` deltas to a language server
 * that advertises incremental sync, instead of re-sending the whole file on
 * every keystroke — without instrumenting each editor edit path, since the
 * delta is derived from the before/after text.
 */
final class TextDiff
{
    /**
     * @return array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, rangeLength: int, text: string}
     *               an LSP TextDocumentContentChangeEvent describing old → new
     */
    public static function incrementalChange(string $old, string $new): array
    {
        $oldLen = \strlen($old);
        $newLen = \strlen($new);

        // Longest common prefix (in bytes).
        $prefix = 0;
        $max = \min($oldLen, $newLen);
        while ($prefix < $max && $old[$prefix] === $new[$prefix]) {
            ++$prefix;
        }
        // Don't split a multi-byte UTF-8 sequence: back up onto a lead byte.
        while ($prefix > 0 && $prefix < $newLen && 0x80 === (\ord($new[$prefix]) & 0xC0)) {
            --$prefix;
        }

        // Longest common suffix that does not overlap the prefix.
        $suffix = 0;
        $maxSuffix = $max - $prefix;
        while ($suffix < $maxSuffix && $old[$oldLen - 1 - $suffix] === $new[$newLen - 1 - $suffix]) {
            ++$suffix;
        }
        // Keep the suffix boundary on a UTF-8 lead byte.
        while ($suffix > 0 && 0x80 === (\ord($old[$oldLen - $suffix]) & 0xC0)) {
            --$suffix;
        }

        $startOffset = $prefix;
        $endOffset = $oldLen - $suffix;

        return [
            'range' => [
                'start' => self::offsetToPosition($old, $startOffset),
                'end' => self::offsetToPosition($old, $endOffset),
            ],
            'rangeLength' => $endOffset - $startOffset,
            'text' => \substr($new, $prefix, $newLen - $suffix - $prefix),
        ];
    }

    /**
     * Byte offset in $text → LSP position (0-based line, UTF-16 code-unit column).
     *
     * @return array{line: int, character: int}
     */
    private static function offsetToPosition(string $text, int $offset): array
    {
        $before = \substr($text, offset: 0, length: $offset);
        $line = \substr_count($before, needle: "\n");
        $lastNewline = \strrpos($before, needle: "\n");
        $lineStart = false === $lastNewline ? $before : \substr($before, $lastNewline + 1);
        $character = '' === $lineStart ? 0 : \strlen((string) \mb_convert_encoding($lineStart, to_encoding: 'UTF-16LE', from_encoding: 'UTF-8')) >> 1;

        return ['line' => $line, 'character' => $character];
    }
}
