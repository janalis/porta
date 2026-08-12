<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

/**
 * The single blessed reader of private state in tests — src carries no
 * test-only observability getters; the few assertions that need internal
 * state read it through here (directly for one-off sites, via typed wrappers
 * like {@see EditorState} for the hot concepts).
 */
final class Probe
{
    /** Read a private/protected property, walking parent classes (trait-in-parent props included). */
    public static function get(object $object, string $property): mixed
    {
        for ($ref = new \ReflectionObject($object); false !== $ref; $ref = $ref->getParentClass()) {
            if ($ref->hasProperty($property)) {
                return $ref->getProperty($property)->getValue($object);
            }
        }

        throw new \LogicException(\sprintf('No property $%s on %s', $property, $object::class));
    }

    /** Walk a chain of private properties where every hop must be an object (throws where it is not). */
    public static function object(object $object, string ...$path): object
    {
        foreach ($path as $property) {
            // @mago-expect analysis:mixed-assignment — get() is untyped by nature; the guard below narrows.
            $value = self::get($object, $property);
            if (!\is_object($value)) {
                throw new \LogicException(\sprintf('$%s on %s is not an object', $property, $object::class));
            }
            $object = $value;
        }

        return $object;
    }

    /** Write a private/protected property, walking parent classes like {@see get()}. */
    public static function set(object $object, string $property, mixed $value): void
    {
        for ($ref = new \ReflectionObject($object); false !== $ref; $ref = $ref->getParentClass()) {
            if (!$ref->hasProperty($property)) {
                continue;
            }
            $ref->getProperty($property)->setValue($object, $value);

            return;
        }

        throw new \LogicException(\sprintf('No property $%s on %s', $property, $object::class));
    }

    /** Invoke a private/protected method, walking parent classes like {@see get()}. */
    public static function invoke(object $object, string $method, mixed ...$args): mixed
    {
        for ($ref = new \ReflectionObject($object); false !== $ref; $ref = $ref->getParentClass()) {
            if ($ref->hasMethod($method)) {
                return $ref->getMethod($method)->invokeArgs($object, $args);
            }
        }

        throw new \LogicException(\sprintf('No method %s() on %s', $method, $object::class));
    }

    /**
     * Invoke a private static method.
     *
     * @param class-string $class
     */
    public static function invokeStatic(string $class, string $method, mixed ...$args): mixed
    {
        return new \ReflectionMethod($class, $method)->invokeArgs(null, $args);
    }

    /**
     * Read a private static property or class constant.
     *
     * @param class-string $class
     */
    public static function getStatic(string $class, string $member): mixed
    {
        $ref = new \ReflectionClass($class);
        if ($ref->hasProperty($member) && $ref->getProperty($member)->isStatic()) {
            return $ref->getProperty($member)->getValue();
        }
        if ($ref->hasConstant($member)) {
            return $ref->getConstant($member);
        }

        throw new \LogicException(\sprintf('No static $%s or constant %s on %s', $member, $member, $class));
    }

    /**
     * Write a private static property — process-global state a test perturbs
     * and must put back (the plugin autoloader's prefix map).
     *
     * @param class-string $class
     */
    public static function setStatic(string $class, string $property, mixed $value): void
    {
        new \ReflectionProperty($class, $property)->setValue(null, $value);
    }
}
