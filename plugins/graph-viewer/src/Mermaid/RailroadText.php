<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Railroad's quoted strings decode only newline, carriage return and tab escapes. */
final class RailroadText
{
    public const string STRING = '"(?:[^"\\\\]++|\\\\.)*"|\'(?:[^\'\\\\]++|\\\\.)*\'';

    public static function quoted(string $raw, string $mode): string
    {
        $value = \substr($raw, offset: 1, length: -1);
        if ('abnf' === $mode) {
            return $value;
        }

        return (
            \preg_replace_callback(
                '/\\\\(.)/s',
                static fn (array $match): string => match ($match[1]) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    default => $match[1],
                },
                $value,
            ) ?? $value
        );
    }
}
