<?php

declare(strict_types=1);

namespace Porta\Support;

/**
 * Tokenizes a user-configured command string into argv, honoring quotes so a
 * binary under a path with spaces survives: '…' is literal, "…" allows the
 * \" and \\ escapes, adjacent quoted/bare runs join into one token, and an
 * unterminated quote runs to the end of the string — a config seam degrades,
 * it never throws.
 *
 * One tokenizer for the three seams that accept a command string (the LSP
 * server, the debug adapter, the per-language run command), so the
 * space-in-path bug cannot be fixed in one and live on in the others.
 */
final class Argv
{
    /** @return list<string> */
    public static function split(string $command): array
    {
        $parts = [];
        $current = '';
        $inToken = false; // tracked apart from $current, so a quoted '' still counts as an argument
        $quote = null;
        $length = \strlen($command);
        for ($i = 0; $i < $length; ++$i) {
            $char = $command[$i];
            if (null !== $quote) {
                if ('"' === $quote && '\\' === $char && ($i + 1) < $length && ('"' === $command[$i + 1] || '\\' === $command[$i + 1])) {
                    $current .= $command[++$i];
                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                    continue;
                }
                $current .= $char;
                continue;
            }
            if (1 === preg_match('/\s/', $char)) {
                if ($inToken) {
                    $parts[] = $current;
                    $current = '';
                    $inToken = false;
                }
                continue;
            }
            if ("'" === $char || '"' === $char) {
                $quote = $char;
                $inToken = true;
                continue;
            }
            $current .= $char;
            $inToken = true;
        }
        if ($inToken) {
            $parts[] = $current;
        }

        return $parts;
    }
}
