<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Unlike Langium strings, C4's pinned Jison strings do not process backslash escapes. */
final class C4Arguments
{
    /** @return list<array{name: ?string, value: string}> */
    public static function parse(MermaidStatement $statement): array
    {
        $start = (int) \strpos($statement->text, needle: '(') + 1;
        $text = \substr($statement->text, $start, length: -1);
        if ('' === \trim($text)) {
            throw $statement->error('C4 calls require arguments');
        }
        $parts = [];
        $quote = false;
        $offset = 0;
        $length = \strlen($text);
        for ($i = 0; $i < $length; ++$i) {
            if ('"' === $text[$i]) {
                $quote = !$quote;
            }
            if (!$quote && ',' === $text[$i]) {
                $parts[] = self::argument(\substr($text, $offset, length: $i - $offset), $statement, $start + $offset);
                $offset = $i + 1;
            }
        }
        $parts[] = self::argument(\substr($text, $offset), $statement, $start + $offset);
        if (\count($parts) > 4096) {
            throw $statement->error('C4 call exceeds the argument limit');
        }

        return $parts;
    }

    /** @return array{name: ?string, value: string} */
    private static function argument(string $text, MermaidStatement $statement, int $offset): array
    {
        $offset += \strlen($text) - \strlen(\ltrim($text));
        $text = \trim($text);
        $match = [];
        if (1 === \preg_match('/^\$([^=]+)\s*=\s*"([^"]+)"$/', $text, $match)) {
            return ['name' => \trim($match[1]), 'value' => \trim($match[2])];
        }
        if (\str_starts_with($text, '$') || (\str_contains($text, '"') && 1 !== \preg_match('/^"[^"]*"$/', $text))) {
            throw $statement->error('Invalid C4 argument', $offset);
        }

        return ['name' => null, 'value' => \str_starts_with($text, '"') ? \trim(\substr($text, offset: 1, length: -1)) : $text];
    }

    /** @param list<array{name: ?string, value: string}> $arguments
     * @param list<string> $fields
     * @return array<string, string>
     */
    public static function bind(array $arguments, array $fields): array
    {
        $values = [];
        foreach ($arguments as $index => $argument) {
            $key = $argument['name'] ?? $fields[$index] ?? null;
            if (null !== $key) {
                $values[$key] = $argument['value'];
            }
        }

        return $values;
    }
}
