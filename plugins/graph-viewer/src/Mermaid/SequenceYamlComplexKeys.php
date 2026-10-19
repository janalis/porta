<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Keep complex key nodes as validated values, then resolve their reference keys. */
final class SequenceYamlComplexKeys
{
    /** @return array{string, ?string} normalized YAML and generated-reference comment marker */
    public static function normalize(string $yaml, MermaidStatement $statement, ?string $generatedComment = null): array
    {
        if (false === \strpbrk($yaml, characters: '[&?!') && \substr_count($yaml, needle: '{') < 2) {
            return [$yaml, $generatedComment];
        }
        $text = new MermaidConfigurationText();
        $text->advance("\n");
        $slots = new SequenceYamlSlots();
        $context = new SequenceYamlFlowContext($yaml, $slots, $text);
        $marker = null;
        $counter = 0;
        $claimed = [];
        $inserts = [];
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
            $parent = $slots->collection;
            $structural = $text->advance($char, $context->nodeIndent);
            $slots->advance($yaml, $offset, $structural, $text->opensAnchor, $text->opensQuote, $text->opensTag);
            $context->advance($offset, $structural, $depth);
            if ($offset < $copied || \array_key_exists($offset, $claimed) || 0 === $depth) {
                continue;
            }
            $head = null !== $slots->question || \in_array($slots->previous, ['[', '{', ','], strict: true);
            $nodeStart = $text->opensAnchor || ($structural && \in_array($char, ['!', '[', '{'], strict: true));
            if (!$head || !$nodeStart) {
                continue;
            }
            $node = SequenceYamlKeyNode::read($yaml, $offset, $context->indent, $statement);
            $key = SequenceYamlKeys::flow($yaml, $offset, $node->end, $parent, $slots->previous, $slots->question, $statement);
            if (null === $key) {
                continue;
            }
            if (null === $slots->question && $key->end > $node->end && false !== \strpbrk(\substr($yaml, $offset, $node->end - $offset), characters: "\r\n")) {
                throw $statement->error('Invalid sequence participant YAML configuration');
            }
            $marker ??= SequenceYamlSymbols::marker($yaml, stem: '__porta_key_');
            $index = $counter++;
            $anchor = $node->anchor ?? $marker.'a'.$index;
            $shadow = '"'.$marker.'k'.$index.'": ';
            $wrapper = null === $key->pairEnd ? '' : '{';
            if ($key->start < $offset) {
                $parts[] = \substr($yaml, $copied, $key->start - $copied).$wrapper.$shadow;
                $copied = $key->start + 1;
                $shadow = '';
                $wrapper = '';
            }
            $properties = (null === $node->anchor ? '&'.$anchor.' ' : '').$node->properties;
            $parts[] = \substr($yaml, $copied, $offset - $copied).$wrapper.$shadow.$properties;
            $copied = $node->body;
            $claimed[$node->body] = true;
            $inserts[$node->end] = ($inserts[$node->end] ?? '').', ? *'.$anchor.' #'.($generatedComment ?? $marker)."\n ";
            if (null !== $key->pairEnd) {
                $inserts[$key->pairEnd] = ($inserts[$key->pairEnd] ?? '').'}';
            }
        }
        $parts[] = \substr($yaml, $copied).($inserts[$length] ?? '');

        return [\implode('', $parts), $generatedComment ?? $marker];
    }
}
