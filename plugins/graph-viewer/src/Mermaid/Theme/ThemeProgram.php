<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid\Theme;

/**
 * Runs the statements extracted from a pinned Mermaid theme class with JavaScript semantics:
 * falsy fallbacks, `+` concatenation, optional chaining and strict-mode property writes.
 * Programs are bundled data from tests/fixtures/mermaid/themes/extract.mjs, never user input;
 * the values they compute are JavaScript values, so this class works on `mixed` by design.
 */
final class ThemeProgram
{
    /** @var array<string, mixed> */
    private array $locals = [];

    /** @param list<mixed> $updateColors */
    public function __construct(
        private readonly array $updateColors,
    ) {}

    /** @param list<mixed> $statements */
    public function run(array $statements, \stdClass $self): void
    {
        // @mago-expect analysis:mixed-assignment -- Program nodes are checked by node() before use.
        foreach ($statements as $statement) {
            $this->statement(self::node($statement), $self);
        }
    }

    public function updateColors(\stdClass $self): void
    {
        $this->run($this->updateColors, $self);
    }

    public static function truthy(mixed $value): bool
    {
        return match (true) {
            \is_bool($value) => $value,
            \is_int($value), \is_float($value) => 0.0 !== (float) $value && !\is_nan((float) $value),
            \is_string($value) => '' !== $value,
            default => null !== $value && JsUndefined::Value !== $value,
        };
    }

    public static function number(mixed $value): float
    {
        return match (true) {
            \is_int($value), \is_float($value) => (float) $value,
            \is_bool($value) => $value ? 1.0 : 0.0,
            null === $value => 0.0,
            \is_string($value) => self::numeric(\trim($value)),
            default => \NAN,
        };
    }

    public static function string(mixed $value): string
    {
        return match (true) {
            \is_string($value) => $value,
            \is_int($value), \is_float($value) => JsNumber::format((float) $value),
            \is_bool($value) => $value ? 'true' : 'false',
            null === $value => 'null',
            JsUndefined::Value === $value => 'undefined',
            \is_array($value) => \implode(',', \array_map(static fn (mixed $item): string => null === $item || JsUndefined::Value === $item ? '' : self::string($item), $value)),
            default => '[object Object]',
        };
    }

    private static function numeric(string $text): float
    {
        return match (true) {
            '' === $text => 0.0,
            1 === \preg_match('/^[+-]?Infinity$/', $text) => \str_starts_with($text, '-') ? -\INF : \INF,
            1 === \preg_match('/^[+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?$/', $text) => self::decimal($text),
            1 === \preg_match('/^0x[0-9a-f]+$/i', $text) => (float) \hexdec($text),
            default => \NAN,
        };
    }

    private static function decimal(string $text): float
    {
        // @mago-expect analysis:invalid-type-cast -- The pattern above matched a decimal literal.
        return (float) $text;
    }

    /** @param list<mixed> $node */
    private function statement(array $node, \stdClass $self): void
    {
        $handler = match ($node[0]) {
            'block' => fn (): mixed => $this->run(self::node($node[1]), $self),
            'let' => fn (): mixed => $this->locals[self::text($node[1])] = $this->expression(self::node($node[2]), $self),
            'set' => fn (): mixed => $this->assign(self::node($node[1]), $this->expression(self::node($node[2]), $self), $self),
            'if' => fn (): mixed => $this->branch($node, $self),
            'for' => fn (): mixed => $this->loop($node, $self),
            'each' => fn (): mixed => $this->each($node, $self),
            'updateColors' => fn (): mixed => $this->updateColors($self),
            default => throw new \UnexpectedValueException('Unknown theme statement '.self::string($node[0])),
        };
        $handler();
    }

    /** @param list<mixed> $node */
    private function branch(array $node, \stdClass $self): void
    {
        // @mago-expect analysis:mixed-assignment -- Theme programs compute JavaScript values.
        $taken = self::truthy($this->expression(self::node($node[1]), $self)) ? $node[2] : $node[3];
        if (null !== $taken) {
            $this->statement(self::node($taken), $self);
        }
    }

    /** @param list<mixed> $node */
    private function loop(array $node, \stdClass $self): void
    {
        $this->statement(self::node($node[1]), $self);
        $counter = self::text($node[3]);
        while (self::truthy($this->expression(self::node($node[2]), $self))) {
            $this->statement(self::node($node[4]), $self);
            $this->locals[$counter] = self::number($this->locals[$counter] ?? JsUndefined::Value) + 1;
        }
    }

    /** @param list<mixed> $node */
    private function each(array $node, \stdClass $self): void
    {
        // @mago-expect analysis:mixed-assignment -- Theme programs compute JavaScript values.
        $items = $this->expression(self::node($node[1]), $self);
        if (!\is_array($items)) {
            throw new \InvalidArgumentException('forEach requires an array');
        }
        // @mago-expect analysis:mixed-assignment -- Array items are JavaScript values.
        foreach ($items as $index => $item) {
            $this->locals[self::text($node[2])] = $item;
            $this->locals[self::text($node[3])] = (float) $index;
            $this->statement(self::node($node[4]), $self);
        }
    }

    /** @param list<mixed> $target */
    private function assign(array $target, mixed $value, \stdClass $self): void
    {
        if ('id' === $target[0]) {
            $this->locals[self::text($target[1])] = $value;

            return;
        }
        // @mago-expect analysis:mixed-assignment -- Theme programs compute JavaScript values.
        $object = $this->expression(self::node($target[1]), $self);
        if (!$object instanceof \stdClass) {
            throw new \InvalidArgumentException('Cannot set a property on '.\get_debug_type($object));
        }
        $key = self::string($this->expression(self::node($target[2]), $self));
        if (JsUndefined::Value === $value) {
            JsObject::remove($object, $key);

            return;
        }
        JsObject::set($object, $key, $value);
    }

    /** @param list<mixed> $node */
    private function expression(array $node, \stdClass $self): mixed
    {
        $operand = fn (int $index): mixed => $this->expression(self::node($node[$index]), $self);

        return match ($node[0]) {
            'lit' => $node[1],
            'this' => $self,
            'id' => \array_key_exists(self::text($node[1]), $this->locals) ? $this->locals[self::text($node[1])] : throw new \UnexpectedValueException('Unknown theme local '.self::text($node[1])),
            'get' => $this->read($node, $self),
            'length' => self::length($operand(1)),
            'bin' => self::binary(self::text($node[1]), $operand(2), $operand(3)),
            'or' => self::logical($operand(1), static fn (mixed $left): bool => !self::truthy($left), static fn (): mixed => $operand(2)),
            'and' => self::logical($operand(1), self::truthy(...), static fn (): mixed => $operand(2)),
            'nullish' => self::logical($operand(1), self::absent(...), static fn (): mixed => $operand(2)),
            'cond' => self::truthy($operand(1)) ? $operand(2) : $operand(3),
            'unary' => self::unary(self::text($node[1]), $operand(2)),
            'call' => self::call(self::text($node[1]), $this->items(self::node($node[2]), $self)),
            'object' => $this->object(self::node($node[1]), $self),
            'array' => $this->items(self::node($node[1]), $self),
            default => throw new \UnexpectedValueException('Unknown theme expression '.self::string($node[0])),
        };
    }

    /** @param list<mixed> $node */
    private function read(array $node, \stdClass $self): mixed
    {
        // @mago-expect analysis:mixed-assignment -- Theme programs compute JavaScript values.
        $object = $this->expression(self::node($node[1]), $self);

        // Optional chaining stops at null and undefined before evaluating the key.
        return 1 === $node[3] && self::absent($object) ? JsUndefined::Value : self::property($object, $this->expression(self::node($node[2]), $self));
    }

    /**
     * @param list<mixed> $nodes
     * @return list<mixed>
     */
    private function items(array $nodes, \stdClass $self): array
    {
        return \array_map(fn (mixed $item): mixed => $this->expression(self::node($item), $self), $nodes);
    }

    /** @param list<mixed> $properties */
    private function object(array $properties, \stdClass $self): \stdClass
    {
        $object = new \stdClass();
        // @mago-expect analysis:mixed-assignment -- Program nodes are checked by node() before use.
        foreach ($properties as $property) {
            $property = self::node($property);
            // @mago-expect analysis:mixed-assignment -- Theme programs compute JavaScript values.
            $value = $this->expression(self::node($property[1]), $self);
            if (JsUndefined::Value !== $value) {
                JsObject::set($object, self::text($property[0]), $value);
            }
        }

        return $object;
    }

    /**
     * The right operand runs only when the left one asks for it, as JavaScript short-circuits.
     *
     * @param \Closure(mixed): bool $continues
     * @param \Closure(): mixed $right
     */
    private static function logical(mixed $left, \Closure $continues, \Closure $right): mixed
    {
        return $continues($left) ? $right() : $left;
    }

    private static function absent(mixed $value): bool
    {
        return null === $value || JsUndefined::Value === $value;
    }

    private static function property(mixed $object, mixed $key): mixed
    {
        $name = self::string($key);
        $index = \is_int($key) || \is_float($key) ? (int) $key : null;

        return match (true) {
            self::absent($object) => throw new \InvalidArgumentException('Cannot read properties of '.self::string($object)),
            $object instanceof \stdClass => JsObject::has($object, $name) ? JsObject::get($object, $name) : JsUndefined::Value,
            \is_array($object) && null !== $index && (float) $index === self::number($key) && \array_key_exists($index, $object) => $object[$index],
            default => JsUndefined::Value,
        };
    }

    private static function length(mixed $value): mixed
    {
        return match (true) {
            \is_array($value) => (float) \count($value),
            \is_string($value) => (float) \strlen((string) \mb_convert_encoding($value, to_encoding: 'UTF-16LE', from_encoding: 'UTF-8')) / 2,
            default => self::property($value, 'length'),
        };
    }

    private static function binary(string $operator, mixed $left, mixed $right): mixed
    {
        $text = static fn (mixed $value): bool => \is_string($value) || \is_array($value) || $value instanceof \stdClass;
        [$a, $b] = [self::number($left), self::number($right)];

        return match ($operator) {
            '+' => $text($left) || $text($right) ? self::string($left).self::string($right) : $a + $b,
            '===' => self::same($left, $right),
            '!==' => !self::same($left, $right),
            '<', '>', '<=', '>=' => self::compare($operator, $left, $right),
            '-' => $a - $b,
            '*' => $a * $b,
            '/' => \fdiv($a, $b),
            '%' => \fmod($a, $b),
            default => throw new \UnexpectedValueException('Unknown theme operator '.$operator),
        };
    }

    private static function compare(string $operator, mixed $left, mixed $right): bool
    {
        [$a, $b] = [self::number($left), self::number($right)];
        $strings = \is_string($left) && \is_string($right);
        $order = $strings ? \strcmp($left, $right) <=> 0 : $a <=> $b;

        return (
            (\is_nan($a) || \is_nan($b)) && !$strings
                ? false
                : match ($operator) {
                    '<' => $order < 0,
                    '>' => $order > 0,
                    '<=' => $order <= 0,
                    default => $order >= 0,
                }
        );
    }

    private static function same(mixed $left, mixed $right): bool
    {
        $numeric = static fn (mixed $value): bool => \is_int($value) || \is_float($value);

        return $numeric($left) && $numeric($right) ? self::number($left) === self::number($right) : $left === $right;
    }

    private static function unary(string $operator, mixed $value): mixed
    {
        return match ($operator) {
            '-' => -self::number($value),
            '+' => self::number($value),
            default => !self::truthy($value),
        };
    }

    /** @param list<mixed> $arguments */
    private static function call(string $function, array $arguments): mixed
    {
        $argument = static fn (int $index): mixed => $arguments[$index] ?? JsUndefined::Value;

        return match ($function) {
            'adjust' => Khroma::adjust($argument(0), self::channels($argument(1))),
            'darken' => Khroma::darken($argument(0), self::number($argument(1))),
            'lighten' => Khroma::lighten($argument(0), self::number($argument(1))),
            'invert' => Khroma::invert($argument(0)),
            'isDark' => Khroma::isDark($argument(0)),
            'mkBorder' => Khroma::adjust($argument(0), (object) ['s' => -40, 'l' => self::truthy($argument(1)) ? 10 : -10]),
            'rgba' => \is_int($argument(0)) || \is_float($argument(0))
                ? Khroma::rgba(self::number($argument(0)), self::number($argument(1)), self::number($arguments[2] ?? 0), self::number($arguments[3] ?? 1))
                : Khroma::change($argument(0), ['a' => self::number($argument(1))]),
            default => throw new \UnexpectedValueException('Unknown theme function '.$function),
        };
    }

    private static function channels(mixed $value): \stdClass
    {
        return $value instanceof \stdClass ? $value : throw new \InvalidArgumentException('adjust requires channel amounts');
    }

    /** @return list<mixed> */
    private static function node(mixed $value): array
    {
        return \is_array($value) && \array_is_list($value) ? $value : throw new \UnexpectedValueException('Malformed theme program');
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : throw new \UnexpectedValueException('Malformed theme program');
    }
}
