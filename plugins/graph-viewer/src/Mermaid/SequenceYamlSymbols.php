<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Collision-free ASCII symbols also account for YAML escapes and continuations. */
final class SequenceYamlSymbols
{
    public static function marker(string $yaml, string $stem): string
    {
        // A marker must also be absent from escaped quoted scalars. Overestimate
        // their decoded text, including line continuations, without parsing any
        // collection or expanding an alias. Scalar folding cannot join this
        // space-free marker; anchors only refer to strings already in this view.
        $view =
            \preg_replace_callback(
                '/\\\\(?:x([0-9a-fA-F]{2})|u([0-9a-fA-F]{4})|U([0-9a-fA-F]{8})|(?:\r\n|[\r\n])[\t \r\n]*)/',
                self::escape(...),
                $yaml,
            ) ?? $yaml;
        $matches = [];
        \preg_match_all('/'.\preg_quote($stem, delimiter: '/').'(\d+)__/', $view, $matches);
        $used = \array_fill_keys($matches[1], value: true);
        $counter = 0;
        while (\array_key_exists($counter, $used)) {
            ++$counter;
        }

        return $stem.$counter.'__';
    }

    /** @param array<array-key, string> $match */
    private static function escape(array $match): string
    {
        $hex = $match[1] ?? '';
        if ('' === $hex) {
            $hex = $match[2] ?? '';
        }
        if ('' === $hex) {
            $hex = $match[3] ?? '';
        }
        if ('' === $hex) {
            return '';
        }
        $code = (int) \hexdec($hex);

        // Only ASCII can participate in our marker. Other code points break it.
        return $code <= 127 ? \chr($code) : '?';
    }
}
