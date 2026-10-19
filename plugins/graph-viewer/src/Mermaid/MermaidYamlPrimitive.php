<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** js-yaml 4.1 JSON-schema primitives; numeric values share JavaScript's double precision. */
final class MermaidYamlPrimitive
{
    private const string INTEGER = '/^[+-]?(?:0|0\d(?:[0-9_]*\d)?|[1-9](?:[0-9_]*\d)?|0b[01_]*[01]|0o[0-7_]*[0-7]|0x[0-9a-fA-F_]*[0-9a-fA-F])$/D';
    private const string FLOAT = '/^(?:[-+]?\d[0-9_]*(?:\.[0-9_]*)?(?:[eE][-+]?\d+)?|\.[0-9_]+(?:[eE][-+]?\d+)?|[-+]?\.(?:inf|Inf|INF)|\.(?:nan|NaN|NAN))$/D';

    public static function kind(string $text): string
    {
        foreach (['null', 'bool', 'int', 'float'] as $kind) {
            if (self::accepts($kind, $text)) {
                return $kind;
            }
        }

        return 'str';
    }

    public static function accepts(string $kind, ?string $text): bool
    {
        return match ($kind) {
            'str' => true,
            'null' => null === $text || \in_array($text, ['~', 'null', 'Null', 'NULL'], strict: true),
            'bool' => \in_array($text, ['true', 'True', 'TRUE', 'false', 'False', 'FALSE'], strict: true),
            'int' => null !== $text && 1 === \preg_match(self::INTEGER, $text),
            'float' => null !== $text && !\str_ends_with($text, '_') && 1 === \preg_match(self::FLOAT, $text),
            default => false,
        };
    }

    public static function canonical(string $text): string
    {
        return match (self::kind($text)) {
            'null' => 'null',
            'bool' => \in_array($text, ['true', 'True', 'TRUE'], strict: true) ? 'true' : 'false',
            'int', 'float' => self::number($text),
            default => '!!str '.$text,
        };
    }

    /** JavaScript converts resolved scalar mapping keys to strings. */
    public static function key(string $text): string
    {
        return match (self::kind($text)) {
            'null' => 'null',
            'bool' => self::canonical($text),
            'int', 'float' => self::numberKey($text),
            default => $text,
        };
    }

    private static function numberKey(string $text): string
    {
        $number = self::number($text);

        return match ($number) {
            '.nan' => 'NaN',
            '.inf' => 'Infinity',
            '-.inf' => '-Infinity',
            default => self::decimalKey($number),
        };
    }

    private static function decimalKey(string $number): string
    {
        $negative = \str_starts_with($number, '-');
        $parts = \explode('e', \ltrim($number, characters: '-'), limit: 2);
        $digits = \str_replace('.', replace: '', subject: $parts[0]);
        $point = \strcspn($parts[0], characters: '.') + (int) ($parts[1] ?? 0) - \strspn($digits, characters: '0');
        $digits = \rtrim(\ltrim($digits, characters: '0'), characters: '0');
        if ('' === $digits) {
            return '0';
        }
        $power = $point - 1;
        $value = match (true) {
            $point > 0 && $point <= 21 => self::wholeKey($digits, $point),
            $point <= 0 && $point > -6 => '0.'.\str_repeat('0', times: -$point).$digits,
            default => $digits[0].(\strlen($digits) > 1 ? '.'.\substr($digits, offset: 1) : '').'e'.($power >= 0 ? '+' : '').$power,
        };

        return ($negative ? '-' : '').$value;
    }

    private static function wholeKey(string $digits, int $point): string
    {
        return \strlen($digits) > $point
            ? \substr($digits, offset: 0, length: $point).'.'.\substr($digits, $point)
            : \str_pad($digits, $point, pad_string: '0', pad_type: \STR_PAD_RIGHT);
    }

    private static function number(string $text): string
    {
        $clean = \strtolower(\str_replace('_', replace: '', subject: $text));
        $body = \ltrim($clean, characters: '+-');
        if (\in_array($body, ['.nan', '.inf'], strict: true)) {
            return match (true) {
                '.nan' === $body => '.nan',
                \str_starts_with($clean, '-') => '-.inf',
                default => '.inf',
            };
        }
        /** @var numeric-string $decimal Decimal forms are validated before normalization. */
        $decimal = $clean;
        $number = match (true) {
            \str_starts_with($body, '0b') => (float) \bindec(\substr($body, offset: 2)),
            \str_starts_with($body, '0o') => (float) \octdec(\substr($body, offset: 2)),
            \str_starts_with($body, '0x') => (float) \hexdec(\substr($body, offset: 2)),
            default => (float) $decimal,
        };
        if (\str_starts_with($clean, '-') && \str_starts_with($body, '0')) {
            $number = -\abs($number);
        }

        return match (true) {
            \is_finite($number) => \json_encode($number, flags: \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION),
            $number < 0 => '-.inf',
            default => '.inf',
        };
    }
}
