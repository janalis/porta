<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** PHP object iteration preserves JSON source order, including integer-like property names. */
final class UsecaseJson
{
    public static function decode(MermaidStatement $statement): \stdClass
    {
        try {
            // @mago-expect analysis:mixed-assignment -- JSON is narrowed to an object immediately below.
            $value = \json_decode($statement->text, associative: false, depth: 128, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw $statement->error('Invalid use-case JSON object');
        }
        if (!$value instanceof \stdClass) {
            throw $statement->error('Use-case JSON roots must be objects');
        }

        return $value;
    }

    /** @return \Generator<int, null, mixed, list<array{key: string, value: string}>> */
    public static function rows(\stdClass $object, MermaidStatement $statement): \Generator
    {
        $rows = [];
        $work = self::walk($object, '', $statement, $rows);
        yield from $work;

        return $rows;
    }

    /** @param list<array{key: string, value: string}> $rows
     * @return \Generator<int, null, mixed, void>
     */
    private static function walk(mixed $value, string $path, MermaidStatement $statement, array &$rows): \Generator
    {
        if (\is_array($value) && [] !== $value) {
            $scalar = !\array_any($value, static fn (mixed $item): bool => \is_array($item) || $item instanceof \stdClass);
            // @mago-expect analysis:mixed-assignment -- Recursive JSON leaves are narrowed by scalar() or walk().
            foreach ($value as $index => $item) {
                if ($scalar) {
                    self::append($rows, 0 === $index ? $path : '', self::scalar($item), $statement);
                    yield null;
                    continue;
                }
                yield from self::walk($item, $path.'['.$index.']', $statement, $rows);
                yield null;
            }

            return;
        }
        if ($value instanceof \stdClass && [] !== \get_object_vars($value)) {
            // @mago-expect analysis:mixed-assignment -- JSON object values are recursively narrowed by walk().
            foreach (\get_object_vars($value) as $key => $child) {
                yield from self::walk($child, '' === $path ? (string) $key : $path.'.'.$key, $statement, $rows);
            }

            return;
        }
        $text = match (true) {
            \is_array($value) => '[]',
            $value instanceof \stdClass => '{}',
            default => self::scalar($value),
        };
        self::append($rows, $path, $text, $statement);
        yield null;
    }

    /** @param list<array{key: string, value: string}> $rows */
    private static function append(array &$rows, string $key, string $value, MermaidStatement $statement): void
    {
        if (\count($rows) >= MermaidGraph::MAX_ITEMS) {
            throw $statement->error('Use-case JSON table exceeds the row limit');
        }
        $rows[] = ['key' => MermaidText::decode($key), 'value' => MermaidText::decode($value)];
    }

    private static function scalar(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }

        return match ($value) {
            null => 'null',
            true => 'true',
            false => 'false',
            default => self::number(\is_int($value) || \is_float($value) ? (float) $value : 0.0),
        };
    }

    private static function number(float $number): string
    {
        // Every numeric JSON leaf is an ECMAScript double, including integers above 2^53.
        return match (true) {
            0.0 === $number => '0',
            !\is_finite($number) => $number > 0 ? 'Infinity' : '-Infinity',
            default => self::finite($number),
        };
    }

    private static function finite(float $number): string
    {
        $encoded = \json_encode($number, flags: \JSON_THROW_ON_ERROR);
        $parts = [];
        if (1 !== \preg_match('/^(-?)(\d)(?:\.(\d+))?e([+-]?\d+)$/i', $encoded, $parts)) {
            return $encoded;
        }

        return self::exponent($parts);
    }

    /** @param array<array-key, string> $parts */
    private static function exponent(array $parts): string
    {
        $fraction = $parts[3] ?? '';
        $digits = $parts[2].('0' === $fraction ? '' : $fraction);
        $exponent = (int) $parts[4];
        if ($exponent >= -6 && $exponent < 21) {
            $point = $exponent + 1;
            $plain = $point <= 0
                ? '0.'.\str_repeat('0', -$point).$digits
                : \substr(\str_pad($digits, length: $point, pad_string: '0'), offset: 0, length: $point).($point < \strlen($digits) ? '.'.\substr($digits, $point) : '');

            return $parts[1].$plain;
        }
        $mantissa = $parts[2].('' === $fraction || '0' === $fraction ? '' : '.'.$fraction);

        return $parts[1].$mantissa.'e'.($exponent >= 0 ? '+' : '').$exponent;
    }
}
