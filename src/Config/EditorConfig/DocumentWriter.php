<?php

declare(strict_types=1);

namespace Porta\Config\EditorConfig;

use Porta\Editor\Document;

/**
 * Produces the on-disk bytes for a {@see Document} under its resolved
 * EditorConfig, applying the save-time rules — `trim_trailing_whitespace`,
 * `end_of_line` and `insert_final_newline` — without ever mutating the live
 * buffer (so cursor, undo history and LSP state are untouched). With an all-null
 * config the output is byte-identical to {@see Document::getText()}, preserving
 * the editor's default always-LF behaviour.
 */
final class DocumentWriter
{
    public static function render(Document $document, ResolvedEditorConfig $config): string
    {
        $lines = $document->getLines();
        if (true === $config->trimTrailingWhitespace) {
            $lines = array_map(static fn (string $line): string => rtrim($line, characters: " \t"), $lines);
        }

        // An .editorconfig that names an ending wins; without one the FILE's own
        // ending is written back. Defaulting to LF rewrote every line of a
        // CRLF file the first time it was saved — a whole-file diff nobody
        // asked for, on every Windows-authored file in a repository.
        $eol = match ($config->eol) {
            'crlf' => "\r\n",
            'cr' => "\r",
            'lf' => "\n",
            default => $document->eol(),
        };
        $text = implode($eol, $lines);

        if (true === $config->insertFinalNewline && !str_ends_with($text, $eol)) {
            $text .= $eol;
        }
        if (false === $config->insertFinalNewline) {
            while ('' !== $text && str_ends_with($text, $eol)) {
                $text = substr($text, offset: 0, length: -\strlen($eol));
            }
        }

        return $text;
    }
}
