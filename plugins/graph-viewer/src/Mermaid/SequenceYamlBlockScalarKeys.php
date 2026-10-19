<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Preserve JSON-schema key coercion before Symfony evaluates block mapping keys. */
final class SequenceYamlBlockScalarKeys
{
    public static function normalize(string $yaml, MermaidStatement $statement): string
    {
        if (!\str_contains($yaml, ':')) {
            return $yaml;
        }
        $text = new MermaidConfigurationText();
        $text->advance("\n");
        $slots = new SequenceYamlSlots();
        $context = new SequenceYamlFlowContext($yaml, $slots, $text);
        $parts = [];
        $copied = 0;
        $length = \strlen($yaml);
        for ($offset = 0; $offset < $length; ++$offset) {
            $char = $yaml[$offset];
            $depth = $slots->depth;
            $structural = $text->advance($char, $context->nodeIndent);
            $slots->advance($yaml, $offset, $structural, $text->opensAnchor, $text->opensQuote, $text->opensTag);
            $context->advance($offset, $structural, $depth);
            if ($depth > 0 || !$slots->keyNode || (!$structural && !$text->opensQuote && !$text->plain) || \ctype_space($char)) {
                continue;
            }
            if (self::excluded($yaml, $offset)) {
                continue;
            }
            $node = SequenceYamlScalar::blockKey($yaml, $offset, $statement);
            if (null === $node) {
                continue;
            }
            [$key, $end] = $node;
            $colon = $end + \strspn($yaml, characters: " \t", offset: $end);
            if (':' !== ($yaml[$colon] ?? '')) {
                if ($context->mappingKey) {
                    throw $statement->error('Invalid sequence participant YAML configuration');
                }
                continue;
            }
            if (!\ctype_space($yaml[$colon + 1] ?? "\n") || false !== \strpbrk(\substr($yaml, $offset, $end - $offset), characters: "\r\n")) {
                throw $statement->error('Invalid sequence participant YAML configuration');
            }
            $encoded = MermaidUtf16::quote($key);
            if ($text->opensQuote) {
                for ($cursor = $offset + 1; $cursor < $end; ++$cursor) {
                    $text->advance($yaml[$cursor]);
                }
            }
            $parts[] = \substr($yaml, $copied, $offset - $copied).$encoded;
            $copied = $end;
            $offset = $colon - 1;
        }
        $parts[] = \substr($yaml, $copied);

        return \implode('', $parts);
    }

    private static function excluded(string $yaml, int $offset): bool
    {
        $char = $yaml[$offset];
        $indicator = \in_array($char, ['-', '?'], strict: true) && \ctype_space($yaml[$offset + 1] ?? "\n");
        $document = \in_array(\substr($yaml, $offset, length: 3), ['---', '...'], strict: true) && \ctype_space($yaml[$offset + 3] ?? "\n");

        return $indicator || $document || \str_contains('[]{}!&*|>%,@`', $char);
    }
}
