<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Ide;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * The test-side successor of the old `Ide::resolve()`: tests reach a running
 * IDE's services through THIS seam, so src carries no test-only resolution
 * API. Semantics are a 1:1 clone — the `porta.ide` locator first (the public
 * boundary), the container as fallback for the synthetics
 * (Renderer/Tui/BidiTui/IdeConfig, `set()` by Ide's constructor and therefore
 * `get()`-able even though their definitions are private).
 *
 * The single reflection point into Ide (its private `$container`, read via
 * {@see Probe}) is deliberate and blessed: one seam here beats N test-only
 * getters in src.
 */
final class Services
{
    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public static function resolve(Ide $ide, string $class): object
    {
        $container = Probe::object($ide, 'container');
        \assert($container instanceof ContainerInterface, description: 'the Ide holds the compiled container');
        $locator = $container->get('porta.ide');
        \assert($locator instanceof ServiceLocator, description: 'porta.ide is the ServiceLocator boundary');
        /** @var object $service the locator serves objects; the assert below pins the class */
        $service = $locator->has($class) ? $locator->get($class) : $container->get($class);
        \assert($service instanceof $class, description: 'the locator entry matches the requested type');

        return $service;
    }
}
