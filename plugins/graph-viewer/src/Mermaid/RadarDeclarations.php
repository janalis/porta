<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Comma lists with quoted display labels and bounded finite non-negative data entries. */
final class RadarDeclarations
{
    public const string ID = '\w(?:[-\w]*\w)?';
    private const string LABEL = '(?:\s*\[\s*("(?:[^"\\\\]++|\\\\.)*"|\'(?:[^\'\\\\]++|\\\\.)*\')\s*\])?';

    /** @return list<array{name: string, label: string, origin: int, entries: list<array{axis: ?string, value: float, origin: int}>}> */
    public static function parse(MermaidStatement $statement): array
    {
        $curve = \str_starts_with($statement->text, 'curve');
        $offset = $curve ? 5 : 4;
        $items = [];
        $length = \strlen($statement->text);
        while ($offset < $length) {
            $match = [];
            $pattern = '~\G\s*('.self::ID.')'.self::LABEL.($curve ? '\s*\{([^{}]*)\}' : '').'~us';
            if (1 !== \preg_match($pattern, $statement->text, $match, offset: $offset)) {
                throw $statement->error('Expected a named radar axis or curve', $offset);
            }
            $origin = self::origin($statement, $offset + \strspn($match[0], characters: " \t\r\n"));
            $label = '' === ($match[2] ?? '') ? $match[1] : self::label($match[2]);
            $bodyOffset = $offset + \strlen($match[0]) - \strlen($match[3] ?? '') - 1;
            $entries = $curve ? self::entries($match[3], $statement, $bodyOffset) : [];
            $items[] = ['name' => $match[1], 'label' => $label, 'origin' => $origin, 'entries' => $entries];
            if (\count($items) > MermaidGraph::MAX_ITEMS) {
                throw $statement->error('Diagram exceeds the radar declaration limit');
            }
            $offset += \strlen($match[0]);
            $offset += \strspn($statement->text, characters: " \t\r\n", offset: $offset);
            if ($offset < $length) {
                if (',' !== $statement->text[$offset]) {
                    throw $statement->error('Expected a comma between radar declarations', $offset);
                }
                ++$offset;
            }
        }

        return $items;
    }

    /** @return list<array{axis: ?string, value: float, origin: int}> */
    private static function entries(string $body, MermaidStatement $statement, int $offset): array
    {
        $body = \preg_replace_callback('/%%[^\r\n]*/', static fn (array $match): string => \str_repeat(' ', \strlen($match[0])), $body) ?? $body;
        $entries = [];
        foreach (\explode(',', $body) as $part) {
            $match = [];
            if (1 !== \preg_match('~^\s*(?:('.self::ID.')\s*:?\s+|('.self::ID.')\s*:\s*)?([0-9]+(?:\.[0-9]+)?)\s*$~u', $part, $match)) {
                throw $statement->error('Expected complete non-negative radar values or axis references', $offset);
            }
            $number = self::number($match[3], $statement);
            $axis = '' !== $match[1] ? $match[1] : $match[2];
            $axis = '' === $axis ? null : $axis;
            if ([] !== $entries && (null === $entries[0]['axis']) !== (null === $axis)) {
                throw $statement->error('Radar curves cannot mix ordered and referenced entries', $offset);
            }
            $entries[] = ['axis' => $axis, 'value' => $number, 'origin' => self::origin($statement, $offset + \strspn($part, characters: " \t\r\n"))];
            if (\count($entries) > MermaidGraph::MAX_ITEMS) {
                throw $statement->error('Diagram exceeds the radar entry limit');
            }
            $offset += \strlen($part) + 1;
        }

        return $entries;
    }

    private static function origin(MermaidStatement $statement, int $offset): int
    {
        return $statement->line + \substr_count($statement->text, needle: "\n", offset: 0, length: $offset);
    }

    public static function number(string $text, MermaidStatement $statement): float
    {
        if (!\is_numeric($text) || 1 !== \preg_match('/^\d+(?:\.\d+)?$/D', $text) || !\is_finite((float) $text)) {
            throw $statement->error('Radar numbers must be complete finite non-negative decimals');
        }

        return (float) $text;
    }

    private static function label(string $text): string
    {
        $body = \substr($text, offset: 1, length: -1);
        $body = \preg_replace('/\\\\([\\\\"\'])/', replacement: '$1', subject: $body) ?? $body;

        return MermaidText::decode($body);
    }
}
