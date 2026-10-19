<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Langium STRING conversion: unknown escapes discard their backslash too. */
final class MermaidQuotedString
{
    public const string PATTERN = '"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'';

    public static function decode(string $quoted): string
    {
        $raw = \substr($quoted, offset: 1, length: -1);

        return (
            \preg_replace_callback(
                '/\\\\(.)/s',
                static fn (array $escape): string => match ($escape[1]) {
                    'b' => "\x08",
                    'f' => "\f",
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    'v' => "\v",
                    '0' => "\0",
                    default => $escape[1],
                },
                $raw,
            ) ?? $raw
        );
    }
}
