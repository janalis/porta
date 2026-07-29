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
}
