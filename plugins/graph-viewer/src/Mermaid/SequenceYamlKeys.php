<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Locate alias keys and preserve block/flow explicit-key semantics. */
final class SequenceYamlKeys
{
    public static function locate(string $yaml, int $start, int $end, SequenceYamlSlots $slots, MermaidStatement $statement): ?SequenceYamlKey
    {
        return $slots->depth > 0
            ? self::flow($yaml, $start, $end, $slots->collection, $slots->previous, $slots->question, $statement)
            : self::block($yaml, $start, $end, $slots);
    }

    public static function flow(string $yaml, int $start, int $end, string $collection, string $previous, ?int $question, MermaidStatement $statement): ?SequenceYamlKey
    {
        if (null === $question && !\in_array($previous, ['[', '{', ','], strict: true)) {
            return null;
        }
        $cursor = $end;
        $length = \strlen($yaml);
        $break = false;
        while ($cursor < $length) {
            $char = $yaml[$cursor];
            if (\str_contains(" \t\r\n", $char)) {
                $break = $break || \in_array($char, ["\r", "\n"], strict: true);
                ++$cursor;
                continue;
            }
            if ('#' === $char) {
                $newline = \strpos($yaml, needle: "\n", offset: $cursor);
                $cursor = false === $newline ? $length : $newline + 1;
                $break = false !== $newline || $break;
                continue;
            }
            break;
        }
        $colon = ':' === ($yaml[$cursor] ?? '');
        if ($colon && $break && null === $question) {
            throw $statement->error('Invalid sequence participant YAML configuration');
        }
        $empty = !$colon && ('{' === $collection || null !== $question) && \in_array($yaml[$cursor] ?? '', [',', '}', ']'], strict: true);

        return match (true) {
            $colon && (!$break || null !== $question) => new SequenceYamlKey($question ?? $start, $cursor + 1, '[' === $collection ? self::pairEnd($yaml, $cursor + 1) : null),
            $empty => new SequenceYamlKey($question ?? $start, $end, '[' === $collection ? $cursor : null),
            default => null,
        };
    }

    public static function pairEnd(string $yaml, int $start): int
    {
        $text = new MermaidConfigurationText();
        $text->advance("\n");
        $depth = 0;
        $length = \strlen($yaml);
        $offset = $start;
        while ($offset < $length) {
            $char = $yaml[$offset];
            if ($text->advance($char)) {
                if (0 === $depth && \in_array($char, [',', ']'], strict: true)) {
                    break;
                }
                $depth += match ($char) {
                    '[', '{' => 1,
                    ']', '}' => -1,
                    default => 0,
                };
            }
            ++$offset;
        }

        return $offset;
    }

    private static function block(string $yaml, int $start, int $end, SequenceYamlSlots $slots): ?SequenceYamlKey
    {
        if (null === $slots->question && !$slots->keyNode) {
            return null;
        }
        $cursor = $end;
        $break = false;
        $indent = 0;
        $length = \strlen($yaml);
        while ($cursor < $length) {
            $char = $yaml[$cursor];
            if (\in_array($char, [' ', "\t"], strict: true)) {
                ++$indent;
                ++$cursor;
                continue;
            }
            if (null !== $slots->question && \in_array($char, ["\r", "\n"], strict: true)) {
                $break = true;
                $indent = 0;
                ++$cursor;
                continue;
            }
            if (null !== $slots->question && '#' === $char) {
                $newline = \strpos($yaml, needle: "\n", offset: $cursor);
                $cursor = false === $newline ? $length : $newline + 1;
                $break = false !== $newline || $break;
                $indent = 0;
                continue;
            }
            break;
        }
        $colon = ':' === ($yaml[$cursor] ?? '');
        $explicit = null !== $slots->question;
        $normal = $colon && (!$explicit || ($break && $indent === $slots->questionIndent)) && \str_contains(" \t\r\n", $yaml[$cursor + 1] ?? "\n");
        $empty = $explicit && !$colon && ($cursor === $length || ($break && $indent <= $slots->questionIndent));

        return match (true) {
            $normal => new SequenceYamlKey($slots->question ?? $start, $cursor + 1),
            $colon && $explicit && !$break && \str_contains(" \t\r\n", $yaml[$cursor + 1] ?? "\n") => new SequenceYamlKey($slots->question, $cursor + 1, objectKey: true),
            $empty => new SequenceYamlKey($slots->question ?? $start, $end),
            default => null,
        };
    }
}
