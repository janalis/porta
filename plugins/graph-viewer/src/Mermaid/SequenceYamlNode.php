<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Classify a normalized YAML node without decoding or walking its contents. */
final class SequenceYamlNode
{
    /** @return array{'empty'|'str'|'seq'|'map', int} node kind and content offset */
    // @mago-expect lint:no-boolean-flag-parameter -- A property-only line permits the node at the same indent; an inline value does not.
    public static function read(string $yaml, int $offset, int $indent, bool $ownLine): array
    {
        $start = $offset;
        $offset = self::content($yaml, $offset);
        $char = $yaml[$offset] ?? '';
        $empty = '' === $char || \str_contains(',]}', $char);
        $newline = \strpos($yaml, needle: "\n", offset: $start);
        if (false !== $newline && $newline < $offset) {
            $line = $offset;
            while ($line > 0 && "\n" !== $yaml[$line - 1]) {
                --$line;
            }
            $childIndent = $offset - $line;
            $indentless = '-' === $char && \ctype_space($yaml[$offset + 1] ?? "\n") && !self::listItem($yaml, $start);
            if ($childIndent < $indent || (!$ownLine && !$indentless && $childIndent === $indent)) {
                $empty = true;
            }
        }
        $mapping = 1 === \preg_match(
            <<<'REGEX'
                /\G(?:\x22(?:\\[^\r\n]|[^\x22\\\r\n])*\x22[\t ]*:|\x27(?:\x27\x27|[^\x27\r\n])*\x27[\t ]*:|(?:(?![\t ]#)[^\x22\x27\r\n,:\[\]{}|>])+:[\t \r\n])/D
                REGEX,
            $yaml,
            offset: $offset,
        );

        return [
            match (true) {
                $empty => 'empty',
                '!' === $char => 'str',
                '[' === $char, '-' === $char && \ctype_space($yaml[$offset + 1] ?? "\n") => 'seq',
                '{' === $char, '?' === $char && \ctype_space($yaml[$offset + 1] ?? "\n"), $mapping => 'map',
                default => 'str',
            },
            $offset,
        ];
    }

    /** @return array{'empty'|'str'|'seq'|'map', int} */
    public static function flow(string $yaml, int $offset, int $indent, MermaidStatement $statement): array
    {
        [$kind, $body] = self::read($yaml, $offset, $indent, ownLine: false);
        $char = $yaml[$body] ?? '';
        $newline = \strpos($yaml, needle: "\n", offset: $offset);
        if (false !== $newline && $newline < $body) {
            $line = $body;
            while ($line > 0 && "\n" !== $yaml[$line - 1]) {
                --$line;
            }
            if (\strspn($yaml, characters: ' ', offset: $line) <= $indent) {
                $kind = 'empty';
            }
        }
        $next = $yaml[$body + 1] ?? "\n";
        $invalid = match ($kind) {
            'empty' => '' !== $char && !\str_contains(',]}', $char),
            'seq' => '[' !== $char,
            'map' => '{' !== $char,
            default => \in_array($char, ['*', '|', '>'], strict: true) || (\in_array($char, ['-', '?', ':'], strict: true) && (\ctype_space($next) || \str_contains(',[]{}', $next))),
        };
        if ($invalid) {
            throw $statement->error('Invalid sequence participant YAML configuration');
        }

        return [$kind, $body];
    }

    private static function listItem(string $yaml, int $offset): bool
    {
        $end = $offset;
        while ($offset > 0 && "\n" !== $yaml[$offset - 1]) {
            --$offset;
        }
        $offset += \strspn($yaml, characters: " \t", offset: $offset);
        $item = '-' === ($yaml[$offset] ?? '') && \ctype_space($yaml[$offset + 1] ?? "\n");
        $text = new MermaidConfigurationText();
        $text->advance("\n");
        for (; $offset < $end; ++$offset) {
            if (!$text->advance($yaml[$offset]) || ':' !== $yaml[$offset] || !\ctype_space($yaml[$offset + 1] ?? "\n")) {
                continue;
            }
            $item = false;
            break;
        }

        return $item;
    }

    private static function content(string $yaml, int $offset): int
    {
        $length = \strlen($yaml);
        while ($offset < $length) {
            if (\ctype_space($yaml[$offset])) {
                ++$offset;
                continue;
            }
            if ('#' !== $yaml[$offset]) {
                break;
            }
            $end = \strpos($yaml, needle: "\n", offset: $offset);
            $offset = false === $end ? $length : $end + 1;
        }

        return $offset;
    }
}
