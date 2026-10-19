<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid\Theme;

/**
 * Pinned `assignWithDepth` (MIT; resources/licenses/mermaid.txt) over decoded configuration:
 * \stdClass is a JavaScript object, a list is an array. Null sources never assign, and a scalar
 * never replaces an object or null, exactly as `typeof value === 'object'` decides upstream.
 */
final class MermaidMerge
{
    public static function assign(mixed $destination, mixed $source, int $depth = 2): mixed
    {
        $list = \is_array($source) && \array_is_list($source);
        $replace = !$list && (null === $destination || $depth <= 0);
        if (!$list && !$replace && $destination instanceof \stdClass && $source instanceof \stdClass) {
            // @mago-expect analysis:mixed-assignment -- Configuration values are JSON-schema values.
            foreach (JsObject::entries($source) as $key => $value) {
                self::property($destination, $key, $value, $depth);
            }
        }

        return match (true) {
            $list => self::arrays($destination, $source, $depth),
            $replace => $destination instanceof \stdClass && $source instanceof \stdClass ? self::shallow($destination, $source) : $source,
            default => $destination,
        };
    }

    /**
     * lodash `merge` as the pinned `cleanAndMerge` uses it: objects merge at every depth, arrays
     * merge by index, and any other source value, null included, replaces the destination.
     */
    public static function deep(mixed $destination, mixed $source): mixed
    {
        return match (true) {
            $source instanceof \stdClass => self::deepObject(JsObject::object($destination), $source),
            \is_array($source) && \array_is_list($source) => self::deepList(\is_array($destination) && \array_is_list($destination) ? $destination : [], $source),
            default => $source,
        };
    }

    private static function deepObject(\stdClass $merged, \stdClass $source): \stdClass
    {
        // @mago-expect analysis:mixed-assignment -- Configuration values are JSON-schema values.
        foreach (JsObject::entries($source) as $key => $value) {
            JsObject::set($merged, $key, self::deep(JsObject::get($merged, $key), $value));
        }

        return $merged;
    }

    /**
     * @param list<mixed> $merged
     * @param list<mixed> $source
     * @return list<mixed>
     */
    private static function deepList(array $merged, array $source): array
    {
        // @mago-expect analysis:mixed-assignment -- Configuration values are JSON-schema values.
        foreach ($source as $index => $value) {
            $merged[$index] = self::deep($merged[$index] ?? null, $value);
        }

        return $merged;
    }

    /** A structural copy of one object. */
    public static function object(\stdClass $value): \stdClass
    {
        return (object) \array_map(self::copy(...), JsObject::values($value));
    }

    /** A structural copy: JavaScript objects stay objects and lists stay lists. */
    public static function copy(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \stdClass => (object) \array_map(self::copy(...), JsObject::values($value)),
            \is_array($value) => \array_map(self::copy(...), $value),
            default => $value,
        };
    }

    /** @param list<mixed> $source */
    private static function arrays(mixed $destination, array $source, int $depth): mixed
    {
        if (!\is_array($destination) || !\array_is_list($destination)) {
            // @mago-expect analysis:mixed-assignment -- Each array item is merged in turn.
            foreach ($source as $item) {
                // @mago-expect analysis:mixed-assignment -- Each array item merges into whatever value results.
                $destination = self::assign($destination, $item, $depth);
            }

            return $destination;
        }
        // @mago-expect analysis:mixed-assignment -- Array items are JSON-schema values.
        foreach ($source as $item) {
            if (\in_array($item, $destination, strict: true)) {
                continue;
            }
            $destination[] = $item;
        }

        return $destination;
    }

    private static function property(\stdClass $destination, string $key, mixed $value, int $depth): void
    {
        // @mago-expect analysis:mixed-assignment -- Configuration values are JSON-schema values.
        $current = JsObject::get($destination, $key);
        $structured = $value instanceof \stdClass || \is_array($value);
        if ($structured) {
            // @mago-expect analysis:mixed-assignment -- An absent or undefined destination starts empty, as upstream.
            $current ??= \is_array($value) ? [] : new \stdClass();
        }
        $currentStructured = $current instanceof \stdClass || \is_array($current);
        // typeof null is 'object': null never assigns, and a scalar never replaces null or an object.
        $assign = null !== $value && ($structured ? $currentStructured : !JsObject::has($destination, $key) || (null !== $current && !$currentStructured));
        if ($assign) {
            JsObject::set($destination, $key, $structured ? self::assign($current, $value, $depth - 1) : $value);
        }
    }

    private static function shallow(\stdClass $destination, \stdClass $source): \stdClass
    {
        // @mago-expect analysis:mixed-assignment -- Object.assign copies any value.
        foreach (JsObject::entries($source) as $key => $value) {
            JsObject::set($destination, $key, $value);
        }

        return $destination;
    }
}
