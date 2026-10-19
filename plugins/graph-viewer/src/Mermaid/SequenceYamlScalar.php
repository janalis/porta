<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Inline;
use Symfony\Component\Yaml\Yaml;

/** Bounded YAML scalar ranges, folding and explicit JSON-schema value decoding. */
final class SequenceYamlScalar
{
    /** Convert scalar/map bindings; sequences require their own conversion. */
    public static function keyValue(string $yaml, int $start, int $indent, bool $ownLine, MermaidStatement $statement): ?string
    {
        return self::key($yaml, $start, $indent, $ownLine, nullText: 'null', statement: $statement);
    }

    /** Null array elements stringify as empty text; quoted "null" remains literal. */
    public static function elementValue(string $yaml, int $start, int $indent, bool $ownLine, MermaidStatement $statement): ?string
    {
        return self::key($yaml, $start, $indent, $ownLine, nullText: '', statement: $statement);
    }

    private static function key(string $yaml, int $start, int $indent, bool $ownLine, string $nullText, MermaidStatement $statement): ?string
    {
        [$kind, $offset] = SequenceYamlNode::read($yaml, $start, $indent, $ownLine);
        if (\in_array($kind, ['seq', 'map'], strict: true)) {
            return 'map' === $kind ? '[object Object]' : null;
        }
        $forced = 1 === \preg_match('/\G!!str(?=\s|$)/', $yaml, offset: $offset);
        if ($forced) {
            [$kind, $offset] = SequenceYamlNode::read($yaml, $offset + 5, $indent, $ownLine);
        }
        $value = 'empty' === $kind ? $nullText : null;
        if ($forced && 'empty' === $kind) {
            $value = '';
        }
        if ('str' === $kind) {
            $quoted = \in_array($yaml[$offset] ?? '', ['"', "'", '|', '>'], strict: true);
            [$content] = $quoted || $forced
                ? self::tagged($yaml, $offset, $statement, blockIndent: $indent)
                : self::atom($yaml, $offset, flow: 1, indent: \PHP_INT_MAX) ?? [null, $offset];
            $quoted = \in_array($yaml[$offset] ?? '', ['"', "'", '|', '>'], strict: true);
            $value = match (true) {
                $forced || $quoted => $content ?? '',
                'null' === MermaidYamlPrimitive::kind($content ?? '') => $nullText,
                default => MermaidYamlPrimitive::key($content ?? ''),
            };
        }

        return $value;
    }

    /**
     * @param list<string> $collections
     * @return array{int, int, string}|null scalar byte range and canonical YAML
     */
    public static function normalize(string $yaml, int $start, array $collections, MermaidStatement $statement, ?int $flowIndent = null, ?int $blockIndent = null): ?array
    {
        $flow = \count($collections);
        $indent = $blockIndent ?? self::indent($yaml, $start);
        $offset = self::valueStart($yaml, $start, $flow > 0 ? -1 : $indent);
        $anchor = [];
        if (null !== $offset && 1 === \preg_match('/\G&[^\s,\[\]{}]+/', $yaml, $anchor, offset: $offset)) {
            $offset = self::valueStart($yaml, $offset + \strlen($anchor[0]), $flowIndent ?? $indent);
        }
        $char = null === $offset ? '' : $yaml[$offset] ?? '';
        $following = null === $offset ? '' : $yaml[$offset + 1] ?? "\n";
        $nested = 0 === $flow && null !== $offset && (self::indent($yaml, $offset) > $indent || \in_array($yaml[$start - 1] ?? '', ['-', '?'], strict: true));
        $pair = '?' === $char && '[' === \array_last($collections) && \in_array($yaml[$start - 1] ?? '', ['[', ','], strict: true);
        $sequence = ($nested || $pair) && \in_array($char, ['-', '?'], strict: true) && \ctype_space($following);
        if (
            ([] !== $anchor && \in_array($char, ['&', '*'], strict: true))
            || ('' !== $char && \str_contains('%@`', $char))
            || (!$sequence && \in_array($char, ['-', '?'], strict: true) && (\ctype_space($following) || ($flow > 0 && \str_contains(',[]{}', $following))))
        ) {
            throw $statement->error('Invalid sequence participant YAML configuration');
        }
        $indicator = $sequence || \str_contains('\'"!*&|>{[}]%,@`', $char);
        $atom = null === $offset || $indicator ? null : self::atom($yaml, $offset, $flow, $indent, $flowIndent);
        $colon = null !== $atom && ':' === ($yaml[$atom[1]] ?? '');
        $mapping = $nested || '[' === \array_last($collections) || '?' === ($yaml[$start - 1] ?? '');
        if ($colon && !$mapping) {
            throw $statement->error('Invalid sequence participant YAML configuration');
        }
        if (null === $offset || null === $atom || $colon) {
            return null;
        }
        [$text, $end] = $atom;
        $canonical = 'str' === MermaidYamlPrimitive::kind($text)
            ? \json_encode($text, flags: \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE)
            : MermaidYamlPrimitive::canonical($text);
        $canonical .= \preg_replace('/[^\r\n]/', replacement: '', subject: \substr($yaml, $offset, $end - $offset)) ?? '';

        return [$offset, $end, $canonical];
    }

    private static function valueStart(string $yaml, int $offset, int $indent): ?int
    {
        $offset = self::spaces($yaml, $offset);
        if (!\in_array($yaml[$offset] ?? '', ["\r", "\n", '#'], strict: true)) {
            return $offset;
        }
        $line = self::afterComments($yaml, $offset);

        return null !== $line && $line[1] > $indent ? $line[0] : null;
    }

    /** @return array{int, int, int}|null */
    private static function afterComments(string $yaml, int $offset): ?array
    {
        do {
            if ('#' === $yaml[$offset]) {
                $newline = \strpos($yaml, needle: "\n", offset: $offset);
                if (false === $newline) {
                    return null;
                }
                $offset = $newline;
            }
            $line = self::nextLine($yaml, $offset);
            $offset = $line[0] ?? 0;
        } while (null !== $line && '#' === $yaml[$offset]);

        return $line;
    }

    private static function spaces(string $yaml, int $offset): int
    {
        while (\in_array($yaml[$offset] ?? '', ["\t", ' '], strict: true)) {
            ++$offset;
        }

        return $offset;
    }

    /** @return array{string, int}|null scalar content and first unconsumed byte */
    private static function atom(string $yaml, int $start, int $flow, int $indent, ?int $flowIndent = null): ?array
    {
        $cursor = $start;
        $capture = $start;
        $end = $start;
        $parts = [];
        $length = \strlen($yaml);
        while ($cursor < $length) {
            $char = $yaml[$cursor];
            if (self::stop($yaml, $cursor, $flow)) {
                break;
            }
            if (\str_contains("\r\n", $char)) {
                $next = self::nextLine($yaml, $cursor);
                if (null === $next || (null === $flowIndent ? $next[1] <= $indent : $next[1] < $flowIndent) || self::stop($yaml, $next[0], $flow)) {
                    break;
                }
                $parts[] = \substr($yaml, $capture, $end - $capture);
                $parts[] = 1 === $next[2] ? ' ' : \str_repeat("\n", times: \max(0, $next[2] - 1));
                $cursor = $next[0];
                $capture = $cursor;
                $end = $cursor;
                continue;
            }
            if (!\in_array($char, ["\t", ' '], strict: true)) {
                $end = $cursor + 1;
            }
            ++$cursor;
        }
        $parts[] = \substr($yaml, $capture, $end - $capture);
        $text = \implode('', $parts);

        return '' === $text ? null : [$text, $end];
    }

    private static function stop(string $yaml, int $offset, int $flow): bool
    {
        $char = $yaml[$offset];
        $next = $yaml[$offset + 1] ?? "\n";

        return (
            ($flow > 0 && \str_contains(',[]{}', $char))
            || (':' === $char && (\ctype_space($next) || ($flow > 0 && \str_contains(',[]{}', $next))))
            || ('#' === $char && (0 === $offset || \ctype_space($yaml[$offset - 1])))
        );
    }

    private static function indent(string $yaml, int $offset): int
    {
        while ($offset > 0 && "\n" !== $yaml[$offset - 1]) {
            --$offset;
        }

        return \strspn($yaml, characters: "\t ", offset: $offset);
    }

    /** @return array{int, int, int}|null next nonempty line's byte offset, indentation and line-break count */
    private static function nextLine(string $yaml, int $offset): ?array
    {
        $breaks = 0;
        $length = \strlen($yaml);
        $indent = 0;
        while ($offset < $length && \ctype_space($yaml[$offset])) {
            $indent += ' ' === $yaml[$offset] ? 1 : 0;
            if ("\n" === $yaml[$offset] || ("\r" === $yaml[$offset] && "\n" !== ($yaml[$offset + 1] ?? ''))) {
                ++$breaks;
                $indent = 0;
            }
            ++$offset;
        }

        return $offset < $length ? [$offset, $indent, $breaks] : null;
    }

    /** @return array{?string, int} content without its explicit tag, and first unconsumed byte */
    public static function tagged(string $yaml, int $start, MermaidStatement $statement, ?int $flowIndent = null, ?int $blockIndent = null): array
    {
        return match ($yaml[$start] ?? '') {
            '"', "'" => self::quotedValue($yaml, $start, $statement),
            '|', '>' => self::block($yaml, $start, $statement, $blockIndent),
            default => self::atom($yaml, $start, flow: null === $flowIndent ? 0 : 1, indent: $blockIndent ?? self::indent($yaml, $start), flowIndent: $flowIndent) ?? [null, $start],
        };
    }

    /** @return array{string, int} */
    public static function quoted(string $yaml, int $start, MermaidStatement $statement): array
    {
        $cursor = new MermaidConfigurationText();
        $cursor->advance("\n");
        $end = $start;
        $length = \strlen($yaml);
        while ($end < $length && !$cursor->advance($yaml[$end])) {
            ++$end;
        }
        try {
            /** @var string $value A successfully decoded quoted scalar is always a string. */
            $value = Inline::parse(\substr($yaml, $start, $end - $start), flags: Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException) {
            throw $statement->error('Invalid sequence participant YAML configuration');
        }

        return [$value, $end];
    }

    /** @return array{string, int} */
    private static function quotedValue(string $yaml, int $start, MermaidStatement $statement): array
    {
        [$value, $end] = self::quoted($yaml, $start, $statement);
        $tail = self::spaces($yaml, $end);
        if ($tail < \strlen($yaml) && !\str_contains("\r\n,]}#", $yaml[$tail])) {
            throw $statement->error('Invalid sequence participant YAML configuration');
        }

        return [$value, $end];
    }

    /** @return array{string, int}|null JavaScript key text and first separator byte in flow-out context */
    public static function blockKey(string $yaml, int $start, MermaidStatement $statement): ?array
    {
        $quoted = \in_array($yaml[$start] ?? '', ['"', "'"], strict: true);
        $node = $quoted ? self::quoted($yaml, $start, $statement) : self::atom($yaml, $start, flow: 0, indent: \PHP_INT_MAX);
        if (null === $node) {
            return null;
        }
        [$text, $end] = $node;

        return [MermaidUtf16::pairs($quoted ? $text : MermaidYamlPrimitive::key($text)), $end];
    }

    /** @return array{string, int} */
    private static function block(string $yaml, int $start, MermaidStatement $statement, ?int $parentIndent): array
    {
        $indent = $parentIndent ?? self::indent($yaml, $start);
        $end = \strpos($yaml, needle: "\n", offset: $start);
        $end = false === $end ? \strlen($yaml) : $end + 1;
        $length = \strlen($yaml);
        while ($end < $length) {
            $stop = \strpos($yaml, needle: "\n", offset: $end);
            $stop = false === $stop ? $length : $stop + 1;
            $line = \substr($yaml, $end, $stop - $end);
            if ('' !== \trim($line) && \strspn($line, characters: "\t ") <= $indent) {
                break;
            }
            $end = $stop;
        }
        $body = \substr($yaml, $start, $end - $start);
        $body = \preg_replace('/^[\t ]{'.$indent.'}/m', replacement: '', subject: $body) ?? $body;
        try {
            /** @var \stdClass&object{value: string} $value The synthetic mapping has one block-scalar string. */
            $value = Yaml::parse('value: '.$body, flags: Yaml::PARSE_OBJECT_FOR_MAP | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE | Yaml::PARSE_EXCEPTION_ON_ALIAS);
        } catch (ParseException) {
            throw $statement->error('Invalid sequence participant YAML configuration');
        }

        return [$value->value, $end];
    }
}
