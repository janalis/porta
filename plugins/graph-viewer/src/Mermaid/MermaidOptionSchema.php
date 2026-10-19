<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Mermaid\Theme\JsObject;

/**
 * Every pinned configuration option, its kind and default (resources/mermaid-options.json, from
 * the 12.1.0 config interfaces and `defaultConfig`). An authored key the pinned build does not
 * define, or a value of the wrong kind, is a located error; a defined key is accepted whether or
 * not it changes the native picture.
 */
final readonly class MermaidOptionSchema
{
    public const string RESOURCE = __DIR__.'/../../resources/mermaid-options.json';

    /**
     * @param array<string, array{kind: mixed, default?: mixed}> $root
     * @param array<string, array<string, array{kind: mixed, default?: mixed}>> $families
     */
    private function __construct(
        private array $root,
        private array $families,
    ) {}

    public static function bundled(): self
    {
        /** @var array{root: array<string, array{kind: mixed, default?: mixed}>, families: array<string, array<string, array{kind: mixed, default?: mixed}>>} $schema Pinned bundled data. */
        $schema = \json_decode((string) \file_get_contents(self::RESOURCE), associative: true, flags: \JSON_THROW_ON_ERROR);

        return new self($schema['root'], $schema['families']);
    }

    /**
     * @param list<string> $own Section keys a family parser checks with its own rules.
     * @throws MermaidSyntaxError When a key is unknown or a value has the wrong kind.
     */
    public function validate(string $key, mixed $value, MermaidStatement $statement, array $own = []): void
    {
        $family = $this->families[$key] ?? null;
        if (null === $family) {
            self::check($this->root[$key]['kind'] ?? null, $value, $key, $statement);

            return;
        }
        if (!$value instanceof \stdClass) {
            throw $statement->error('Mermaid configuration sections must be mappings');
        }
        // @mago-expect analysis:mixed-assignment -- Each option is checked against its kind.
        foreach (JsObject::entries($value) as $name => $option) {
            if (\in_array($name, $own, strict: true)) {
                continue;
            }
            self::check($family[$name]['kind'] ?? null, $option, $key.'.'.$name, $statement);
        }
    }

    /** The pinned default of a root option, or null when the build defines none. */
    public function rootDefault(string $key): mixed
    {
        return $this->root[$key]['default'] ?? null;
    }

    /** The pinned default of a family option, or null when the build defines none. */
    public function default(string $family, string $key): mixed
    {
        return $this->families[$family][$key]['default'] ?? null;
    }

    /**
     * @param mixed $kind A kind name, the allowed values of an enumeration, or nested kinds by key;
     *                    null for a key the pinned build does not define.
     */
    private static function check(mixed $kind, mixed $value, string $path, MermaidStatement $statement): void
    {
        // As pinned, a key the build does not define is ignored, not refused.
        if (null === $kind) {
            return;
        }
        if (\is_array($kind) && !\array_is_list($kind)) {
            if (!$value instanceof \stdClass) {
                throw $statement->error('Mermaid configuration option '.$path.' must be a mapping');
            }
            // @mago-expect analysis:mixed-assignment -- Each nested value is checked against its kind.
            foreach (JsObject::entries($value) as $key => $nested) {
                self::check($kind[$key] ?? null, $nested, $path.'.'.$key, $statement);
            }

            return;
        }
        if (!self::accepts($kind, $value)) {
            throw $statement->error('Mermaid configuration option '.$path.' must be '.self::describe($kind));
        }
    }

    private static function accepts(mixed $kind, mixed $value): bool
    {
        // @mago-expect analysis:mixed-assignment -- Numeric text reads as its number; the kind narrows the rest.
        $value = MermaidOptions::numeric($value);
        $number = (\is_int($value) || \is_float($value)) && \is_finite((float) $value);

        return match (true) {
            \is_array($kind) => \in_array($value, $kind, strict: true),
            'boolean' === $kind => \is_bool($value),
            'number' === $kind => $number,
            'size' === $kind => $number || \is_string($value),
            'string' === $kind => \is_string($value),
            'list' === $kind => \is_array($value) && \array_is_list($value),
            'map' === $kind => $value instanceof \stdClass,
            default => true,
        };
    }

    private static function describe(mixed $kind): string
    {
        return match (true) {
            \is_array($kind) => 'one of '.\implode(', ', \array_map(static fn (mixed $value): string => \json_encode($value, \JSON_THROW_ON_ERROR), $kind)),
            'boolean' === $kind => 'true or false',
            'number' === $kind => 'a number',
            'size' === $kind => 'a number or text',
            'string' === $kind => 'text',
            'list' === $kind => 'a list',
            default => 'a mapping',
        };
    }
}
