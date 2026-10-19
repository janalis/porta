<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Original item ranges retain reference bindings and construction-time prefixes. */
final class SequenceYamlItems
{
    /** @return \Generator<int, array{int, int, int}, mixed, int> item start/end/indent; final collection end */
    public static function read(string $yaml, int $start): \Generator
    {
        return '[' === $yaml[$start] ? self::flow($yaml, $start) : self::block($yaml, $start);
    }

    private static function indent(string $yaml, int $offset): int
    {
        while ($offset > 0 && "\n" !== $yaml[$offset - 1]) {
            --$offset;
        }

        return \strspn($yaml, characters: " \t", offset: $offset);
    }

    /** @return \Generator<int, array{int, int, int}, mixed, int> */
    private static function flow(string $yaml, int $start): \Generator
    {
        $text = new MermaidConfigurationText();
        $text->advance("\n");
        $first = $start + 1;
        $depth = 0;
        $length = \strlen($yaml);
        for ($offset = $first; $offset < $length; ++$offset) {
            $char = $yaml[$offset];
            if (!$text->advance($char)) {
                continue;
            }
            if (0 === $depth && \in_array($char, [',', ']'], strict: true)) {
                yield [$first, $offset, self::indent($yaml, $first)];
                $first = $offset + 1;
                if (']' === $char) {
                    return $offset + 1;
                }
            }
            $depth += match ($char) {
                '[', '{' => 1,
                ']', '}' => -1,
                default => 0,
            };
        }
        yield [$first, $length, self::indent($yaml, $first)];

        return $length;
    }

    /** @return \Generator<int, array{int, int, int}, mixed, int> */
    private static function block(string $yaml, int $start): \Generator
    {
        $indent = self::indent($yaml, $start);
        $first = $start + 1;
        $length = \strlen($yaml);
        $next = \strpos($yaml, needle: "\n", offset: $start);
        while (false !== $next && ($next + 1) < $length) {
            $line = $next + 1;
            $offset = $line + \strspn($yaml, characters: " \t", offset: $line);
            $char = $yaml[$offset] ?? '';
            $column = $offset - $line;
            $item = '-' === $char && \ctype_space($yaml[$offset + 1] ?? "\n");
            if (!\in_array($char, ['', '#', "\r", "\n"], strict: true)) {
                if ($column < $indent || ($column === $indent && !$item)) {
                    yield [$first, $line, $indent];

                    return $line;
                }
                if ($column === $indent) {
                    yield [$first, $line, $indent];
                    $first = $offset + 1;
                }
            }
            $next = \strpos($yaml, needle: "\n", offset: $line);
        }
        yield [$first, $length, $indent];

        return $length;
    }
}
