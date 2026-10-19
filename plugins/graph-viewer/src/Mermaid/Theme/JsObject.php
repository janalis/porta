<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid\Theme;

/**
 * Property access on decoded configuration, where keys are authored data. Mermaid's merging and
 * sanitizing walk arbitrary keys, so the computed member names are confined to this class.
 */
final class JsObject
{
    public static function get(\stdClass $object, string $key): mixed
    {
        // @mago-expect analysis:string-member-selector -- Configuration keys are authored data.
        return \property_exists($object, $key) ? $object->{$key} : null;
    }

    public static function has(\stdClass $object, string $key): bool
    {
        return \property_exists($object, $key);
    }

    public static function set(\stdClass $object, string $key, mixed $value): void
    {
        // @mago-expect analysis:string-member-selector -- Configuration keys are authored data.
        $object->{$key} = $value;
    }

    public static function remove(\stdClass $object, string $key): void
    {
        // @mago-expect analysis:string-member-selector -- Configuration keys are authored data.
        unset($object->{$key});
    }

    /**
     * Each property with its name as text: an array would turn names like "12" into integers.
     *
     * @return \Generator<string, mixed>
     */
    public static function entries(\stdClass $object): \Generator
    {
        // @mago-expect analysis:mixed-assignment -- Configuration values are JSON-schema values.
        foreach (\get_object_vars($object) as $key => $value) {
            yield (string) $key => $value;
        }
    }

    /** @return array<array-key, mixed> The properties for spreading or mapping into a new object. */
    public static function values(\stdClass $object): array
    {
        return \get_object_vars($object);
    }

    public static function object(mixed $value): \stdClass
    {
        return $value instanceof \stdClass ? $value : new \stdClass();
    }
}
