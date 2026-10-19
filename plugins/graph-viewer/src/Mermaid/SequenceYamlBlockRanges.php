<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Index explicit key extents once; nested keys must not rescan their subtrees. */
final class SequenceYamlBlockRanges
{
    /** @return array<int, int> question offset to first following sibling line */
    public static function read(string $yaml): array
    {
        $text = new MermaidConfigurationText();
        $text->advance("\n");
        $slots = new SequenceYamlSlots();
        $context = new SequenceYamlFlowContext($yaml, $slots, $text);
        $lineStart = 0;
        $open = [];
        $ranges = [];
        $length = \strlen($yaml);
        for ($offset = 0; $offset < $length; ++$offset) {
            $char = $yaml[$offset];
            $depth = $slots->depth;
            $structural = $text->advance($char, $context->nodeIndent);
            $slots->advance($yaml, $offset, $structural, $text->opensAnchor, $text->opensQuote, $text->opensTag);
            $context->advance($offset, $structural, $depth);
            if ("\n" === $char) {
                $lineStart = $offset + 1;
            }
            $node = $structural || $text->opensAnchor || $text->opensQuote || $text->plain;
            if ($depth > 0 || !$node || \ctype_space($char)) {
                continue;
            }
            $column = $offset - $lineStart;
            if ($slots->first === $offset) {
                while ([] !== $open && \array_last($open)[1] >= $column) {
                    [$start] = \array_pop($open);
                    $ranges[$start] = $lineStart;
                }
            }
            if ($structural && $slots->keyNode && '?' === $char && \ctype_space($yaml[$offset + 1] ?? "\n")) {
                $open[] = [$offset, $column];
            }
        }
        foreach ($open as [$start]) {
            $ranges[$start] = $length;
        }

        return $ranges;
    }
}
