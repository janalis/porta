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
