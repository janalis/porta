<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Metadata has a small typed grammar; duplicate properties retain their declaration order. */
final class UsecaseMetadata
{
    /** @return list<array{key: string, value: string|bool, statement: MermaidStatement}> */
    public static function parse(MermaidStatement $statement): array
    {
        $text = $statement->text;
        $length = \strlen($text) - 1;
        $position = 2;
        $values = [];
        while ($position < $length) {
            $position += \strspn($text, characters: " \t\r\n", offset: $position);
            if ($position >= $length) {
                break;
            }
            $at = $position;
            $match = [];
            $keyPattern = '/\G('.UsecaseLabels::QUOTED.'|'.UsecaseLabels::IDENTIFIER.')\s*:\s*('.UsecaseLabels::QUOTED.'|'.UsecaseLabels::IDENTIFIER.')/';
            if (1 !== \preg_match($keyPattern, $text, $match, offset: $position)) {
                throw $statement->error('Invalid use-case metadata property', $position);
            }
            self::token($match[1], $statement);
            if (!\in_array($match[2], ['true', 'false'], strict: true)) {
                self::token($match[2], $statement);
            }
            $key = self::plain($match[1]);
            $raw = $match[2];
            $value = match ($raw) {
                'true' => true,
                'false' => false,
                default => self::plain($raw),
            };
            $position += \strlen($match[0]);
            $values[] = ['key' => $key, 'value' => $value, 'statement' => $statement->slice($at, $position - $at)];
            $spaces = \strspn($text, characters: " \t\r\n", offset: $position);
            $gap = \substr($text, $position, $spaces);
            $position += $spaces;
            if ($position >= $length) {
                break;
            }
            if (',' === $text[$position]) {
                ++$position;
                continue;
            }
            if (!\str_contains($gap, "\n") && !\str_contains($gap, "\r")) {
                throw $statement->error('Expected a comma or newline between use-case metadata properties', $position);
            }
        }

        return $values;
    }

    private static function token(string $text, MermaidStatement $statement): void
    {
        if (\str_starts_with($text, '"`')) {
            throw $statement->error('Use-case metadata requires plain strings');
        }
        if ('"' !== $text[0] && "'" !== $text[0]) {
            UsecaseLabels::identifier($text, $statement);
        }
    }

    private static function plain(string $text): string
    {
        return '"' === $text[0] || "'" === $text[0] ? \substr($text, offset: 1, length: -1) : $text;
    }
}
