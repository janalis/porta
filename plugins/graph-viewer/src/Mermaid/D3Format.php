<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Mermaid\Theme\JsNumber;

/**
 * d3-format 3 (ISC; resources/licenses/d3-format.txt) under its default en-US locale: comma
 * grouping by threes, `$` currency, `.` decimals and the U+2212 minus sign.
 */
final readonly class D3Format
{
    private const string SPECIFIER = '/^(?:(.)?([<>=^]))?([+\-( ])?([$#])?(0)?(\d+)?(,)?(\.\d+)?(~)?([a-z%])?$/i';
    private const array PREFIXES = ['y', 'z', 'a', 'f', 'p', 'n', 'µ', 'm', '', 'k', 'M', 'G', 'T', 'P', 'E', 'Z', 'Y'];

    private function __construct(
        private string $fill,
        private string $align,
        private string $sign,
        private string $symbol,
        private bool $zero,
        private int $width,
        private bool $comma,
        private int $precision,
        private bool $trim,
        private string $type,
    ) {}

    /** @throws \InvalidArgumentException When the specifier is not a d3-format specifier. */
    public static function parse(string $specifier): self
    {
        $match = [];
        if (1 !== \preg_match(self::SPECIFIER, $specifier, $match)) {
            throw new \InvalidArgumentException('invalid format: '.$specifier);
        }
        [$fill, $align, $sign, $symbol] = [$match[1] ?? '', $match[2] ?? '', $match[3] ?? '', $match[4] ?? ''];
        [$zero, $width, $type] = [$match[5] ?? '', $match[6] ?? '', $match[10] ?? ''];
        $trim = '~' === ($match[9] ?? '');
        $comma = ',' === ($match[7] ?? '');
        $precision = '' === ($match[8] ?? '') ? null : (int) \substr($match[8], offset: 1);
        // `n` is grouped `g`; an unknown or absent type is a trimmed twelve-digit `g`.
        $general = 'n' === $type;
        $fallback = !$general && !\in_array($type, ['%', 'b', 'c', 'd', 'e', 'f', 'g', 'o', 'p', 'r', 's', 'X', 'x'], strict: true);
        $comma = $comma || $general;
        $trim = $trim || $fallback;
        $precision = $fallback ? $precision ?? 12 : $precision;
        $type = $general || $fallback ? 'g' : $type;
        $zero = '' !== $zero || ('0' === $fill && '=' === $align);
        $precision = match (true) {
            null === $precision => 6,
            \str_contains('gprs', $type) => \max(1, \min(21, $precision)),
            default => \max(0, \min(20, $precision)),
        };

        $fill = '' === $fill ? ' ' : $fill;
        $align = '' === $align ? '>' : $align;
        // @mago-expect analysis:impossible-condition -- mago reads the preg_match groups as empty; `08,d` zero-fills (D3FormatTest).
        [$fill, $align] = $zero ? ['0', '='] : [$fill, $align];

        return new self(
            $fill,
            $align,
            '' === $sign ? '-' : $sign,
            $symbol,
            $zero,
            (int) $width,
            $comma,
            $precision,
            $trim,
            $type,
        );
    }

    public function format(float $value): string
    {
        $prefix = match (true) {
            '$' === $this->symbol => '$',
            '#' === $this->symbol && \str_contains('boxX', $this->type) => '0'.\strtolower($this->type),
            default => '',
        };
        $suffix = '$' !== $this->symbol && \str_contains('%p', $this->type) ? '%' : '';
        if ('c' === $this->type) {
            // d3 writes a character value as JavaScript text, sign included, after the prefix.
            return $this->aligned('', $prefix, JsNumber::format($value).$suffix);
        }
        $negative = $value < 0 || -\INF === \fdiv(1.0, $value);
        [$digits, $exponent] = $this->digits(\abs($value));
        $digits = $this->trim ? self::trimmed($digits) : $digits;
        // d3 drops the sign of a value that formats as zero, unless `+` asks for it.
        $plain = \str_replace(',', replace: '', subject: $digits);
        $negative = $negative && ('+' === $this->sign || !\is_numeric($plain) || 0.0 !== (float) $plain);
        $sign = match (true) {
            $negative && '(' === $this->sign => '(',
            $negative => '−',
            '-' === $this->sign, '(' === $this->sign => '',
            default => $this->sign,
        };
        $unit = 's' === $this->type ? self::PREFIXES[8 + \intdiv(num1: $exponent, num2: 3)] : '';
        $valueSuffix = $unit.$suffix.($negative && '(' === $this->sign ? ')' : '');
        $valuePrefix = $sign.$prefix;

        return $this->aligned($digits, $valuePrefix, $valueSuffix);
    }

    private function aligned(string $digits, string $valuePrefix, string $valueSuffix): string
    {
        $split = \strspn($digits, characters: '0123456789');
        if (\str_contains('defgprs%', $this->type) && $split < \strlen($digits)) {
            $valueSuffix = \substr($digits, $split).$valueSuffix;
            $digits = \substr($digits, offset: 0, length: $split);
        }
        if ($this->comma && !$this->zero) {
            $digits = self::group($digits, \PHP_INT_MAX);
        }
        $length = \mb_strlen($valuePrefix.$digits.$valueSuffix);
        $padding = \str_repeat($this->fill, \max(0, $this->width - $length));
        if ($this->comma && $this->zero) {
            $digits = self::group($padding.$digits, '' === $padding ? \PHP_INT_MAX : $this->width - \mb_strlen($valueSuffix));
            $padding = '';
        }
        $half = \intdiv(num1: \strlen($padding), num2: 2);

        return match ($this->align) {
            '<' => $valuePrefix.$digits.$valueSuffix.$padding,
            '=' => $valuePrefix.$padding.$digits.$valueSuffix,
            '^' => \substr($padding, offset: 0, length: $half).$valuePrefix.$digits.$valueSuffix.\substr($padding, $half),
            default => $padding.$valuePrefix.$digits.$valueSuffix,
        };
    }

    /** @return array{string, int} the type's digits and, for SI prefixes, the prefix exponent */
    private function digits(float $value): array
    {
        $precision = $this->precision;
        $rounded = \round($value);

        return match ($this->type) {
            '%' => [self::fixed($value * 100, $precision), 0],
            'b' => [self::radix($rounded, 2), 0],
            'd' => [\number_format($rounded, decimals: 0, decimal_separator: '.', thousands_separator: ''), 0],
            'e' => [self::exponential($value, $precision), 0],
            'f' => [self::fixed($value, $precision), 0],
            'o' => [self::radix($rounded, 8), 0],
            'p' => [self::rounded($value * 100, $precision), 0],
            'r' => [self::rounded($value, $precision), 0],
            's' => self::prefixed($value, $precision),
            'X' => [\strtoupper(self::radix($rounded, 16)), 0],
            'x' => [self::radix($rounded, 16), 0],
            default => [self::precise($value, $precision), 0],
        };
    }

    /** JavaScript `toFixed`, which writes numbers from 1e21 up as `toString` does. */
    private static function fixed(float $value, int $precision): string
    {
        return \abs($value) >= 1e21 ? JsNumber::format($value) : \number_format($value, $precision, decimal_separator: '.', thousands_separator: '');
    }

    /** JavaScript `toString(base)` of a whole number; halving stays exact for the power-of-two bases. */
    private static function radix(float $value, int $base): string
    {
        $digits = '';
        do {
            $digit = (int) \fmod($value, $base);
            $digits = \substr('0123456789abcdef', $digit, length: 1).$digits;
            $value = ($value - $digit) / $base;
        } while ($value >= 1);

        return $digits;
    }

    /** JavaScript `toExponential`: one digit, the decimals, then `e±N`. */
    private static function exponential(float $value, int $precision): string
    {
        [$coefficient, $exponent] = self::parts($value, $precision + 1);
        $mantissa = \strlen($coefficient) > 1 ? $coefficient[0].'.'.\substr($coefficient, offset: 1) : $coefficient;

        return $mantissa.'e'.($exponent < 0 ? '-' : '+').\abs($exponent);
    }

    /** JavaScript `toPrecision`. */
    private static function precise(float $value, int $precision): string
    {
        [, $exponent] = self::parts($value, $precision);

        return $exponent < -6 || $exponent >= $precision ? self::exponential($value, $precision - 1) : self::fixed($value, \max(0, $precision - 1 - $exponent));
    }

    /**
     * d3 `formatDecimalParts`: the significant digits without a point, and the decimal exponent.
     *
     * @return array{string, int}
     */
    private static function parts(float $value, int $digits): array
    {
        [$mantissa, $exponent] = \explode('e', \sprintf('%.'.\max(0, $digits - 1).'e', $value));
        $coefficient = \str_replace('.', replace: '', subject: $mantissa);
        if (0 === $digits) {
            // d3 calls `toExponential()` for no digits, which keeps the shortest exact digits.
            $shortest = \str_replace('.', replace: '', subject: \explode('e', JsNumber::format($value))[0]);
            $coefficient = \rtrim(\ltrim($shortest, characters: '0'), characters: '0');
        }

        return ['' === $coefficient ? '0' : $coefficient, (int) $exponent];
    }

    /** d3 `formatRounded`: `toPrecision` digits written without an exponent. */
    private static function rounded(float $value, int $precision): string
    {
        [$coefficient, $exponent] = self::parts($value, $precision);

        return match (true) {
            $exponent < 0 => '0.'.\str_repeat('0', \max(0, -$exponent - 1)).$coefficient,
            \strlen($coefficient) > ($exponent + 1) => \substr($coefficient, offset: 0, length: $exponent + 1).'.'.\substr($coefficient, $exponent + 1),
            default => $coefficient.\str_repeat('0', \max(0, $exponent - \strlen($coefficient) + 1)),
        };
    }

    /**
     * d3 `formatPrefixAuto`: digits scaled to the nearest SI prefix.
     *
     * @return array{string, int}
     */
    private static function prefixed(float $value, int $precision): array
    {
        [$coefficient, $exponent] = self::parts($value, $precision);
        $prefix = \max(-8, \min(8, (int) \floor($exponent / 3))) * 3;
        $point = $exponent - $prefix + 1;
        $length = \strlen($coefficient);
        $digits = match (true) {
            $point === $length => $coefficient,
            $point > $length => $coefficient.\str_repeat('0', \max(0, $point - $length)),
            $point > 0 => \substr($coefficient, offset: 0, length: $point).'.'.\substr($coefficient, $point),
            default => '0.'.\str_repeat('0', \max(0, -$point)).self::parts($value, \max(0, $precision + $point - 1))[0],
        };

        return [$digits, $prefix];
    }

    /** d3 `formatTrim`: insignificant trailing zeros removed. */
    private static function trimmed(string $digits): string
    {
        $start = -1;
        $end = -1;
        for ($index = 1, $count = \strlen($digits); $index < $count; ++$index) {
            $character = $digits[$index];
            if ('.' === $character) {
                $start = $index;
                $end = $index;
                continue;
            }
            if ('0' === $character) {
                $start = 0 === $start ? $index : $start;
                $end = $index;
                continue;
            }
            if (!\ctype_digit($character)) {
                break;
            }
            $start = $start > 0 ? 0 : $start;
        }

        return $start > 0 ? \substr($digits, offset: 0, length: $start).\substr($digits, $end + 1) : $digits;
    }

    /** d3 `formatGroup` with the en-US grouping of three and `,`. */
    private static function group(string $digits, int $width): string
    {
        $groups = [];
        $index = \strlen($digits);
        $length = 0;
        while ($index > 0) {
            $size = ($length + 4) > $width ? \max(1, $width - $length) : 3;
            $index -= $size;
            $groups[] = \substr($digits, \max(0, $index), $size + \min(0, $index));
            $length += $size + 1;
            if ($length > $width) {
                break;
            }
        }

        return \implode(',', \array_reverse($groups));
    }
}
