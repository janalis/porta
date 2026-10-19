<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/**
 * Flat YAML mappings of scalars and flow lists of scalars, a list read as JavaScript's
 * `Array#toString` joins it; nested maps are rejected, as the pinned lexer rejects them.
 */
final class KanbanMetadata
{
    /** @return array<string, string> */
    public static function parse(string $text, MermaidStatement $statement): array
    {
        $match = [];
        if (1 !== \preg_match('/^@\{(.*)\}\s*(?:%%[^\n]*)?$/s', $text, $match)) {
            throw $statement->error('Invalid Kanban metadata block');
        }
        $body = $match[1];
        $fields = [];
        $offset = 0;
        $length = \strlen($body);
        $pattern = '/\G\s*([a-zA-Z][\w-]*)\s*:\s*("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\']|\'\')*\'|\[[^\[\]{}]*\]|[^,\n\r{}\[\]"\']+)[\t ]*(?:,|\r?\n|$)/';
        while ($offset < $length && '' !== \trim(\substr($body, $offset))) {
            if (1 !== \preg_match($pattern, $body, $match, offset: $offset)) {
                throw $statement->error('Kanban metadata requires a flat scalar mapping');
            }
            $key = $match[1];
            $value = self::value(\trim($match[2]), $statement);
            if (\array_key_exists($key, $fields)) {
                throw $statement->error('Duplicate Kanban metadata field');
            }
            $fields[$key] = MermaidText::decode($value);
            $offset += \strlen($match[0]);
        }
        if ('' !== ($fields['shape'] ?? '') && (\strtolower($fields['shape']) !== $fields['shape'] || \str_contains($fields['shape'], '_'))) {
            throw $statement->error('Kanban shape names must be lowercase');
        }

        return $fields;
    }

    private static function value(string $text, MermaidStatement $statement): string
    {
        if (!\str_starts_with($text, '[')) {
            return self::scalar($text, $statement);
        }
        $items = \trim(\substr($text, offset: 1, length: -1));
        $values = [];
        $pattern = '/\G\s*("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\']|\'\')*\'|[^,"\']+?)\s*(?:,|$)/';
        $match = [];
        $length = \strlen($items);
        for ($offset = 0; $offset < $length; $offset += \strlen($match[0])) {
            if (1 !== \preg_match($pattern, $items, $match, offset: $offset)) {
                throw $statement->error('Unsupported Kanban YAML scalar');
            }
            $values[] = self::scalar($match[1], $statement);
        }

        return \implode(',', $values);
    }

    private static function scalar(string $text, MermaidStatement $statement): string
    {
        if ('' === $text || 1 === \preg_match('/^(?:[!&*|>]|<<\s*:)/', $text)) {
            throw $statement->error('Unsupported Kanban YAML scalar');
        }

        return match (true) {
            \str_starts_with($text, "'") => \str_replace("''", replace: "'", subject: \substr($text, offset: 1, length: -1)),
            \str_starts_with($text, '"') => \strtr(\substr($text, offset: 1, length: -1), ['\\"' => '"', '\\\\' => '\\', '\\n' => "\n"]),
            default => $text,
        };
    }
}
