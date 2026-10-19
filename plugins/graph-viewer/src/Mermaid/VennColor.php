<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** CSS RGB/alpha values are separated so cell colors stay six-digit truecolor. */
final class VennColor
{
    /** @return array{color: string, alpha: float} */
    public static function parse(string $key, string $value, MermaidStatement $statement): array
    {
        $value = \trim($value);
        $match = [];

        return match (true) {
            'transparent' === \strtolower($value) => ['color' => '#000000', 'alpha' => 0.0],
            1 === \preg_match('/^#([0-9a-f]{4}|[0-9a-f]{8})$/i', $value, $match) => self::hex($match[1]),
            1 === \preg_match('/^rgba?\(([^)]+)\)$/i', $value, $match) => self::rgb($value, $match[1], $statement),
            default => ['color' => MermaidColor::parse($key, $value, $statement, 'Venn'), 'alpha' => 1.0],
        };
    }

    /** @return array{color: string, alpha: float} */
    private static function hex(string $digits): array
    {
        $digits = \strtolower($digits);
        if (4 === \strlen($digits)) {
            $digits = \implode('', \array_map(static fn (string $digit): string => $digit.$digit, \str_split($digits)));
        }

        return ['color' => '#'.\substr($digits, offset: 0, length: 6), 'alpha' => \hexdec(\substr($digits, offset: -2)) / 255];
    }

    /** @return array{color: string, alpha: float} */
    private static function rgb(string $value, string $channels, MermaidStatement $statement): array
    {
        $parts = \array_map(\trim(...), \explode(',', $channels));
        $expected = \str_starts_with(\strtolower($value), 'rgba') ? 4 : 3;
        if (\count($parts) !== $expected || \array_any($parts, static fn (string $part): bool => !\is_numeric($part))) {
            throw $statement->error('Invalid Venn RGB color');
        }
        $numbers = \array_map(\floatval(...), $parts);
        foreach (\array_slice($numbers, offset: 0, length: 3) as $channel) {
            if (!\is_finite($channel) || $channel < 0 || $channel > 255) {
                throw $statement->error('Venn RGB channels must be between zero and 255');
            }
        }
        $alpha = $numbers[3] ?? 1.0;
        if (!\is_finite($alpha) || $alpha < 0 || $alpha > 1) {
            throw $statement->error('Venn alpha must be between zero and one');
        }

        return ['color' => \sprintf('#%02x%02x%02x', (int) \round($numbers[0]), (int) \round($numbers[1]), (int) \round($numbers[2])), 'alpha' => $alpha];
    }

    public static function alpha(string $color, float $opacity): string
    {
        return 'none' === $color || 1.0 === $opacity ? $color : \sprintf('%s%02x', $color, (int) \round(255 * $opacity));
    }
}
