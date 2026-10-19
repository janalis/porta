<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Dedent the actual block root without repairing invalid sibling indentation. */
final class SequenceYamlIndentation
{
    public static function block(string $body, MermaidStatement $statement): string
    {
        $text = new MermaidConfigurationText();
        $text->advance("\n");
        $slots = new SequenceYamlSlots();
        $lineStart = 0;
        $indent = null;
        $length = \strlen($body);
        for ($offset = 0; $offset < $length; ++$offset) {
            $char = $body[$offset];
            $depth = $slots->depth;
            $structural = $text->advance($char);
            $slots->advance($body, $offset, $structural, $text->opensAnchor, $text->opensQuote, $text->opensTag);
            if ("\n" === $char) {
                $lineStart = $offset + 1;
            }
            $node = $structural || $text->opensQuote || $text->opensAnchor || $text->plain;
            if ($depth > 0 || !$node || $slots->first !== $offset || self::documentLine($body, $offset)) {
                continue;
            }
            $column = \max(0, $offset - $lineStart);
            $indent ??= $column;
            if ($column < $indent) {
                throw $statement->error('Invalid sequence participant YAML configuration');
            }
        }

        return MermaidYamlScalars::block($body, $indent ?? 0);
    }

    private static function documentLine(string $body, int $offset): bool
    {
        return '%' === $body[$offset] || (\in_array(\substr($body, $offset, length: 3), ['---', '...'], strict: true) && \ctype_space($body[$offset + 3] ?? "\n"));
    }
}
