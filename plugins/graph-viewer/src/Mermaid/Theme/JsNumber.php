<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid\Theme;

/** ECMAScript Number::toString: shortest round-trip digits, decimal from 1e-7 up to 1e21. */
final class JsNumber
{
    public static function format(float $value): string
    {
        return match (true) {
            \is_nan($value) => 'NaN',
            \is_infinite($value) => $value > 0 ? 'Infinity' : '-Infinity',
            0.0 === $value => '0',
            default => ($value < 0 ? '-' : '').self::finite(\abs($value)),
        };
    }

    private static function finite(float $value): string
    {
        [$digits, $exponent] = self::shortest($value);
        $count = \strlen($digits);
        $power = $exponent - 1;

        // `$exponent` places the decimal point after that many digits, as ECMAScript's n.
        return match (true) {
            $count <= $exponent && $exponent <= 21 => $digits.\str_repeat('0', \max(0, $exponent - $count)),
            0 < $exponent && $exponent <= 21 => \substr($digits, offset: 0, length: $exponent).'.'.\substr($digits, $exponent),
            -6 < $exponent && $exponent <= 0 => '0.'.\str_repeat('0', \max(0, -$exponent)).$digits,
            default => (1 === $count ? $digits : $digits[0].'.'.\substr($digits, offset: 1)).'e'.($power < 0 ? '-' : '+').\abs($power),
        };
    }

    /** @return array{non-empty-string, int} Significant digits without trailing zeros, and the decimal exponent. */
    private static function shortest(float $value): array
    {
        // PHP's serialize_precision -1 already prints the shortest round-trip representation.
        $text = \strtolower(\json_encode($value, flags: \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION));
        $parts = \explode('e', $text, limit: 2);
        $power = \count($parts) > 1 ? (int) $parts[1] : 0;
        [$whole, $fraction] = \array_pad(\explode('.', $parts[0], limit: 2), length: 2, value: '');
        $digits = \ltrim($whole.$fraction, characters: '0');
        $exponent = \strlen($whole) + $power - (\strlen($whole.$fraction) - \strlen($digits));
        $digits = \rtrim($digits, characters: '0');

        return ['' === $digits ? '0' : $digits, $exponent];
    }
}
