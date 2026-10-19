<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Preserve plain flow keys and detect duplicates even when their values are null. */
final class SequenceYamlFlowKeys
{
    public static function normalize(string $yaml, MermaidStatement $statement): string
    {
        if (false === \strpbrk($yaml, characters: '{[')) {
            return $yaml;
        }
        $text = new MermaidConfigurationText();
        $text->advance("\n");
        $slots = new SequenceYamlSlots();
        $context = new SequenceYamlFlowContext($yaml, $slots, $text);
        $keys = [];
        $questions = [];
        $parts = [];
        $tails = [];
        $copied = 0;
        $length = \strlen($yaml);
        for ($offset = 0; $offset < $length; ++$offset) {
            if (\array_key_exists($offset, $tails)) {
                $parts[] = \substr($yaml, $copied, $offset - $copied).\str_repeat('}', $tails[$offset]);
                $copied = $offset;
            }
            $char = $yaml[$offset];
            $depth = $slots->depth;
            $structural = $text->advance($char, $context->nodeIndent);
            $slots->advance($yaml, $offset, $structural, $text->opensAnchor, $text->opensQuote, $text->opensTag);
            $context->advance($offset, $structural, $depth);
            if ($slots->depth > $depth) {
                $keys[$slots->depth] = [];
            }
            if ($slots->depth < $depth) {
                unset($keys[$depth], $questions[$depth]);
            }
            $head = self::head($slots, $questions);
            if (0 === $slots->depth || !$head || \ctype_space($char)) {
                continue;
            }
            if ($structural && '?' === $char && \ctype_space($yaml[$offset + 1] ?? "\n")) {
                $questions[$slots->depth] = $offset;
                continue;
            }
            if (!$structural && !$text->opensQuote && !$text->opensAnchor && !$text->plain) {
                continue;
            }
            if (!self::candidate($char, $structural || $text->plain, $text)) {
                unset($questions[$slots->depth]);
                continue;
            }
            $entry = self::read($yaml, $offset, $text, $questions[$slots->depth] ?? null, $statement, $context->indent);
            if (null === $entry) {
                continue;
            }
            [$key, $end, $cursor, $colon] = $entry;
            $explicit = \array_key_exists($slots->depth, $questions);
            $pair = '[' === $slots->collection;
            if ($pair && !$colon && !$explicit) {
                $context->skipRange($offset + 1, $end);
                $offset = \max($offset, $end - 1);
                continue;
            }
            if (!$pair) {
                self::record($key, $keys[$slots->depth], $statement);
            }
            $encoded = MermaidUtf16::quote($key);
            $start = $questions[$slots->depth] ?? $offset;
            unset($questions[$slots->depth]);
            if ($pair) {
                $tail = SequenceYamlKeys::pairEnd($yaml, $colon ? $cursor + 1 : $end);
                $tails[$tail] = ($tails[$tail] ?? 0) + 1;
            }
            $parts[] = \substr($yaml, $copied, $start - $copied).($pair ? '{' : '').$encoded.($colon ? '' : ':');
            $copied = $colon ? $cursor : $end;
            $context->skipRange($offset + 1, $copied);
            $offset = \max($offset, $copied - 1);
        }
        $parts[] = \substr($yaml, $copied).\str_repeat('}', $tails[$length] ?? 0);

        return \implode('', $parts);
    }

    /** @param array<string|int, bool> $keys */
    private static function record(string $key, array &$keys, MermaidStatement $statement): void
    {
        if (\array_key_exists($key, $keys)) {
            throw $statement->error('Invalid sequence participant YAML configuration');
        }
        $keys[$key] = true;
    }

    /** @param array<int, int> $questions */
    private static function head(SequenceYamlSlots $slots, array $questions): bool
    {
        return \in_array($slots->previous, ['[', '{', ','], strict: true) || \array_key_exists($slots->depth, $questions);
    }

    private static function candidate(string $char, bool $structural, MermaidConfigurationText $text): bool
    {
        return $text->opensQuote || ($structural && !\str_contains('[]{}!&*?|>%,@`', $char));
    }

    /** @return array{string, int, int, bool}|null resolved key, scalar end, separator offset and colon presence */
    private static function read(string $yaml, int $offset, MermaidConfigurationText $text, ?int $question, MermaidStatement $statement, int $flowIndent): ?array
    {
        $quoted = $text->opensQuote;
        [$value, $end] = $quoted
            ? self::quoted($yaml, $offset, $text, $statement)
            : SequenceYamlScalar::tagged($yaml, $offset, $statement, flowIndent: $flowIndent);
        if (null === $value) {
            return null;
        }
        $cursor = $end;
        while (\str_contains(" \t\r\n", $yaml[$cursor] ?? "\0")) {
            ++$cursor;
        }
        $colon = ':' === ($yaml[$cursor] ?? '');
        if ($colon && null === $question && false !== \strpbrk(\substr($yaml, $offset, $cursor - $offset), characters: "\r\n")) {
            throw $statement->error('Invalid sequence participant YAML configuration');
        }

        return [MermaidUtf16::pairs($quoted ? $value : MermaidYamlPrimitive::key($value)), $end, $cursor, $colon];
    }

    /** @return array{string, int} */
    private static function quoted(string $yaml, int $start, MermaidConfigurationText $text, MermaidStatement $statement): array
    {
        [$value, $end] = SequenceYamlScalar::quoted($yaml, $start, $statement);
        for ($offset = $start + 1; $offset < $end; ++$offset) {
            $text->advance($yaml[$offset]);
        }

        return [$value, $end];
    }
}
