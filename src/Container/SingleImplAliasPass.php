<?php

declare(strict_types=1);

namespace Porta\Container;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Auto-derives the single-implementation interface aliases that were once
 * hand-written in config/services.php: for every `Porta\` interface implemented
 * by exactly one non-abstract service, alias the interface to that service, so
 * autowiring an interface-typed dependency resolves to its sole implementation.
 *
 * An interface with two-plus implementing services (e.g. `FloatingRegions`,
 * `SyntaxHighlightProviderSource`) is left alone — its intended binding is a
 * deliberate choice and stays an explicit `alias()` in services.php, and a
 * silently-derived ambiguity would be a bug. Cross-vendor interfaces (PSR,
 * Symfony, Tempest) are out of scope — only `Porta\` interfaces are considered.
 *
 * Runs before the autowire pass (a higher `TYPE_BEFORE_OPTIMIZATION` priority),
 * so the aliases exist when interface-typed arguments are resolved.
 */
final class SingleImplAliasPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        /** @var array<string, list<string>> $implementorsByInterface */
        $implementorsByInterface = [];
        foreach ($container->getDefinitions() as $id => $definition) {
            $class = $definition->getClass();
            if ($definition->isAbstract() || null === $class || !class_exists($class)) {
                continue;
            }
            foreach (class_implements($class) ?: [] as $interface) {
                if (str_starts_with($interface, 'Porta\\')) {
                    $implementorsByInterface[$interface][] = $id;
                }
            }
        }

        foreach ($implementorsByInterface as $interface => $implementors) {
            if (1 !== \count($implementors)) {
                continue; // ambiguous → the intended binding stays an explicit alias
            }
            if ($container->hasDefinition($interface) || $container->hasAlias($interface)) {
                continue; // already wired (an explicit alias, or a same-named service)
            }
            $container->setAlias($interface, $implementors[0]);
        }
    }
}
