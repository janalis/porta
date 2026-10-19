<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Adapt js-yaml's permissive flow-line indentation to Symfony without flattening scalars. */
final class SequenceYamlFlowLines
{
    public static function normalize(string $yaml, MermaidStatement $statement): string
    {
        $text = new MermaidConfigurationText();
        $text->advance("\n");
        $slots = new SequenceYamlSlots();
        $context = new SequenceYamlFlowContext($yaml, $slots, $text);
        $lineStart = 0;
        $baseline = 0;
        $padding = 0;
        $copied = 0;
        $parts = [];
        $length = \strlen($yaml);
        for ($offset = 0; $offset < $length; ++$offset) {
            if ($offset === $lineStart && $slots->depth > 0) {
                $spaces = \strspn($yaml, characters: ' ', offset: $offset);
                $extra = \max(0, $baseline - $spaces);
                $padding += $extra;
                if ($padding > 262_144) {
                    throw $statement->error('Invalid sequence participant YAML configuration');
                }
                $parts[] = \substr($yaml, $copied, $offset - $copied).\str_repeat(' ', $extra);
                $copied = $offset;
            }
            $depth = $slots->depth;
            $char = $yaml[$offset];
            $structural = $text->advance($char, $context->nodeIndent);
            $slots->advance($yaml, $offset, $structural, $text->opensAnchor, $text->opensQuote, $text->opensTag);
            $context->advance($offset, $structural, $depth);
            if (0 === $depth && $slots->depth > 0) {
                $baseline = \strspn($yaml, characters: ' ', offset: $lineStart) + 2;
            }
            if ("\n" === $char) {
                $lineStart = $offset + 1;
            }
        }
        $parts[] = \substr($yaml, $copied);

        return \implode('', $parts);
    }
}
