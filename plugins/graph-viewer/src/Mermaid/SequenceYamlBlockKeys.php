<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Preserve literal block-key graphs as values before their bounded key coercion. */
final class SequenceYamlBlockKeys
{
    /** @return array{string, ?string} YAML and the generated-reference comment marker */
    public static function normalize(string $yaml, MermaidStatement $statement): array
    {
        if (false === \strpbrk($yaml, characters: '?[{!&')) {
            return [$yaml, null];
        }
        $ranges = SequenceYamlBlockRanges::read($yaml);
        $text = new MermaidConfigurationText();
        $text->advance("\n");
        $slots = new SequenceYamlSlots();
        $context = new SequenceYamlFlowContext($yaml, $slots, $text);
        $lineStart = 0;
        $marker = null;
        $counter = 0;
        $padding = 0;
        $inserts = [];
        $claimed = [];
        $active = [];
        $parts = [];
        $copied = 0;
        $length = \strlen($yaml);
        for ($offset = 0; $offset < $length; ++$offset) {
            if (\array_key_exists($offset, $inserts)) {
                $parts[] = \substr($yaml, $copied, $offset - $copied).$inserts[$offset];
                $copied = $offset;
            }
            $char = $yaml[$offset];
            $depth = $slots->depth;
            $structural = $text->advance($char, $context->nodeIndent);
            $slots->advance($yaml, $offset, $structural, $text->opensAnchor, $text->opensQuote, $text->opensTag);
            $context->advance($offset, $structural, $depth);
            if ("\n" === $char) {
                $lineStart = $offset + 1;
            }
            if ($offset < $copied || \array_key_exists($offset, $claimed) || $depth > 0 || (!$structural && !$text->opensAnchor && !$text->opensTag) || !$slots->keyNode) {
                continue;
            }
            $property = $text->opensAnchor || $text->opensTag;
            if ($property && !$context->mappingKey) {
                SequenceYamlBlockKey::validateValue($yaml, $offset, $statement);
                continue;
            }
            $key = $property
                ? SequenceYamlBlockKey::property($yaml, $offset, $lineStart, $statement)
                : SequenceYamlBlockKey::read($yaml, $offset, $lineStart, $ranges[$offset] ?? $length, $statement);
            if (null === $key) {
                continue;
            }
            if ($property) {
                $claimed[$key->node->body] = true;
            }
            self::accept($key, $active, $padding, $statement);
            $marker ??= SequenceYamlSymbols::marker($yaml, stem: '__porta_block_key_');
            $index = $counter++;
            $anchor = $key->node->anchor ?? $marker.'a'.$index;
            $prefix = '"'.$marker.'k'.$index.'": '.(null === $key->node->anchor ? '&'.$anchor.' ' : '').$key->node->properties;
            if ($key->node->body >= $key->end) {
                $prefix .= "\n";
            }
            $parts[] = \substr($yaml, $copied, $offset - $copied).$prefix;
            $copied = \min($key->node->body, $key->end);
            if (null !== $key->blockColumn) {
                $inserts[$key->node->body] = ($inserts[$key->node->body] ?? '')."\n".\str_repeat(' ', $key->blockColumn);
            }
            $suffix = self::suffix($yaml, $key, $anchor, $marker);
            $inserts[$key->end] = $suffix.($inserts[$key->end] ?? '');
        }
        $parts[] = \substr($yaml, $copied).($inserts[$length] ?? '');

        return [\implode('', $parts), $marker];
    }

    /** @param list<int> $active */
    private static function accept(SequenceYamlBlockKey $key, array &$active, int &$padding, MermaidStatement $statement): void
    {
        while ([] !== $active && \array_last($active) <= $key->start) {
            \array_pop($active);
        }
        if (\count($active) >= 128) {
            throw $statement->error('Invalid sequence participant YAML configuration');
        }
        $padding += $key->padding();
        if ($padding > 262_144) {
            throw $statement->error('Invalid sequence participant YAML configuration');
        }
        $active[] = $key->end;
    }

    private static function suffix(string $yaml, SequenceYamlBlockKey $key, string $anchor, string $marker): string
    {
        $indent = \str_repeat(' ', $key->column);
        $reference = '? *'.$anchor.' #'.$marker;

        return $key->explicit
            ? ($key->end > 0 && "\n" === $yaml[$key->end - 1] ? '' : "\n").$indent.$reference."\n"
            : "\n".$indent.$reference."\n".$indent;
    }
}
