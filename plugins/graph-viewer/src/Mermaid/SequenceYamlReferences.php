<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Project collection references without walking ignored graphs, including cycles. */
final class SequenceYamlReferences
{
    /**
     * The original definitions remain for syntax/tag/duplicate-key validation and
     * scalar anchors nested inside them. Collection references cannot become a
     * valid alias/type string: retaining an empty collection preserves that fact.
     */
    public static function project(string $yaml, MermaidStatement $statement, ?string $generatedComment = null): string
    {
        if (!\str_contains($yaml, '*')) {
            return $yaml;
        }
        $text = new MermaidConfigurationText();
        $text->advance("\n");
        $slots = new SequenceYamlSlots();
        $context = new SequenceYamlFlowContext($yaml, $slots, $text);
        $anchors = [];
        $definitions = [];
        $bindings = [];
        $properties = [];
        $keyStrings = [];
        $keyBytes = 0;
        $tails = [];
        $parts = [];
        $copied = 0;
        $count = 0;
        $length = \strlen($yaml);
        for ($offset = 0; $offset < $length; ++$offset) {
            $char = $yaml[$offset];
            if (\array_key_exists($offset, $tails)) {
                $parts[] = \substr($yaml, $copied, $offset - $copied).\str_repeat('}', $tails[$offset]);
                $copied = $offset;
            }
            $depth = $slots->depth;
            $structural = $text->advance($char, $context->nodeIndent);
            $slots->advance($yaml, $offset, $structural, $text->opensAnchor, $text->opensQuote, $text->opensTag);
            $context->advance($offset, $structural, $depth);
            if ($offset < $copied) {
                continue;
            }
            $boundary = self::boundary($yaml, $offset);
            if ($text->opensAnchor && $boundary) {
                self::anchor($yaml, $offset, $slots, $context, $statement, $anchors, $definitions, $properties);
            }
            if (!$structural || '*' !== $char || !$boundary) {
                continue;
            }
            $match = [];
            if (1 !== \preg_match('/\G\*([^\s,\[\]{}]+)/', $yaml, $match, offset: $offset)) {
                continue;
            }
            $end = $offset + \strlen($match[0]);
            $bindings[$offset] = $definitions[$match[1]] ?? null;
            $key = SequenceYamlKeys::locate($yaml, $offset, $end, $slots, $statement);
            $generated = null !== $generatedComment && \str_starts_with(\substr($yaml, $end), ' #'.$generatedComment);
            if (!$generated && ($anchors[$match[1]] ?? false) && (null !== $key || $slots->valueNode) && ++$count > 128) {
                throw $statement->error('Invalid sequence participant YAML configuration');
            }
            if (null !== $key) {
                if (!\array_key_exists($match[1], $anchors)) {
                    throw $statement->error('Invalid sequence participant YAML configuration');
                }
                $value = self::keyString($yaml, $definitions[$match[1]], $offset, $bindings, $properties, $keyStrings, $statement);
                if (null !== $value && $key->objectKey) {
                    $value = '"[object Object]":';
                }
                if (null !== $value) {
                    $keyBytes += \strlen($value);
                    if ($keyBytes > 262_144) {
                        throw $statement->error('Invalid sequence participant YAML configuration');
                    }
                    $parts[] = \substr($yaml, $copied, $key->start - $copied).(null === $key->pairEnd ? '' : '{').$value;
                    if (null !== $key->pairEnd) {
                        $tails[$key->pairEnd] = ($tails[$key->pairEnd] ?? 0) + 1;
                    }
                    $copied = $key->end;
                }
                continue;
            }
            if (!($anchors[$match[1]] ?? false) || !$slots->valueNode) {
                continue;
            }
            $parts[] = \substr($yaml, $copied, $offset - $copied).'[]';
            $copied = $end;
        }
        $parts[] = \substr($yaml, $copied).\str_repeat('}', $tails[$length] ?? 0);

        return \implode('', $parts);
    }

    private static function boundary(string $yaml, int $offset): bool
    {
        return \str_contains(" \t\r\n[{,?-", $yaml[$offset - 1] ?? "\n") || (':' === ($yaml[$offset - 1] ?? '') && \in_array($yaml[$offset - 2] ?? '', ['"', "'"], strict: true));
    }

    /**
     * @param array<string, bool> $anchors
     * @param array<string, array{int, int, bool}> $definitions
     * @param array<int, array{int, int, bool}> $properties
     */
    private static function anchor(
        string $yaml,
        int $offset,
        SequenceYamlSlots $slots,
        SequenceYamlFlowContext $context,
        MermaidStatement $statement,
        array &$anchors,
        array &$definitions,
        array &$properties,
    ): void {
        $match = [];
        if (1 === \preg_match('/\G&([^\s,\[\]{}]+)/', $yaml, $match, offset: $offset)) {
            $start = $offset + \strlen($match[0]);
            $indent = $slots->depth > 0 ? $context->indent : $context->nodeIndent;
            [$kind] = $slots->depth > 0
                ? SequenceYamlNode::flow($yaml, $start, $indent, $statement)
                : SequenceYamlNode::read($yaml, $start, $indent, ownLine: false);
            $anchors[$match[1]] = \in_array($kind, ['seq', 'map'], strict: true);
            $definitions[$match[1]] = [$start, $indent, false];
            $properties[$offset] = $definitions[$match[1]];
        }
    }

    /**
     * @param array{int, int, bool} $definition
     * @param array<int, array{int, int, bool}|null> $bindings
     * @param array<int, array{int, int, bool}> $properties
     * @param array<int, ?string> $cache
     */
    private static function keyString(string $yaml, array $definition, int $use, array $bindings, array $properties, array &$cache, MermaidStatement $statement): ?string
    {
        if (\array_key_exists($definition[0], $cache)) {
            return $cache[$definition[0]];
        }
        [$kind, $offset] = SequenceYamlNode::read($yaml, $definition[0], $definition[1], $definition[2]);
        $complete = true;
        $value = SequenceYamlScalar::keyValue($yaml, $definition[0], $definition[1], $definition[2], $statement);
        if ('seq' === $kind) {
            [$value, $complete] = SequenceYamlArrayKey::read($yaml, $offset, $use, $bindings, $properties, $statement);
        }
        $encoded = null === $value ? null : MermaidUtf16::quote($value).':';
        if ($complete) {
            $cache[$definition[0]] = $encoded;
        }

        return $encoded;
    }
}
