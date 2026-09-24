<?php

declare(strict_types=1);

namespace Porta\Lsp;

/**
 * Hover documentation as a reader sees it rather than as markdown source: the
 * emphasis marks, inline-code backticks, heading hashes and link targets that
 * servers send are markup the terminal cannot render, and left in they are
 * noise between the words. Code blocks keep their lines, indented two columns
 * so they stand apart from the prose.
 */
final class HoverMarkdown
{
    public static function plain(string $markdown): string
    {
        $out = [];
        $inCode = false;
        foreach (\explode("\n", $markdown) as $line) {
            if (1 === \preg_match('/^\s*```/', $line)) {
                $inCode = !$inCode;

                continue;
            }
            $out[] = $inCode ? '  '.$line : self::prose($line);
        }

        // Blank runs collapse to one; the edges lose blank LINES only, so a
        // first code line keeps its indent.
        return \rtrim(\trim((string) \preg_replace("/\n{3,}/", replacement: "\n\n", subject: \implode("\n", $out)), characters: "\n"));
    }

    private static function prose(string $line): string
    {
        if (1 === \preg_match('/^\s*(-{3,}|\*{3,}|_{3,})\s*$/', $line)) {
            return ''; // a rule separates; the blank line says the same
        }
        // Up to three leading spaces mean nothing in markdown prose; four or
        // more are an indented code block, kept as they are.
        $line = (string) \preg_replace('/^ {1,3}(?! )/', replacement: '', subject: $line);
        $line = (string) \preg_replace('/^#{1,6}\s+/', replacement: '', subject: $line);
        $line = (string) \preg_replace('/\[([^\]]*)\]\([^)]*\)/', replacement: '$1', subject: $line);
        $line = (string) \preg_replace('/(\*\*|__)(.+?)\1/', replacement: '$2', subject: $line);

        return \str_replace('`', replace: '', subject: $line);
    }
}
