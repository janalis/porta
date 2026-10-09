<?php

declare(strict_types=1);

namespace Porta\Plugin\Runtime;

use Porta\Config\ConfigWarning;

/**
 * Puts a set of discovered plugins into load order, and says which of them
 * cannot load at all.
 *
 * Dependencies are declared by NAME in {@see PluginManifest::$requires}, which
 * is why the manifests are read before any of the code is: a plugin has to be
 * activated after the ones it needs, and a plugin whose need is absent is
 * better skipped with a message than loaded into a half-there world.
 *
 * A CYCLE is reported rather than resolved: there is no correct order for one,
 * and picking an arbitrary member to go first would make the bug intermittent.
 * Every plugin in the cycle is skipped.
 */
final class PluginOrder
{
    /**
     * @param list<PluginManifest> $manifests in discovery (precedence) order
     *
     * @return array{order: list<PluginManifest>, skipped: array<string, ConfigWarning>} the loadable ones, and why each other one is not
     */
    public static function resolve(array $manifests): array
    {
        $byName = [];
        foreach ($manifests as $manifest) {
            $byName[$manifest->name] ??= $manifest;
        }

        $order = [];
        $skipped = [];
        $state = []; // name => 'visiting' | 'done'
        // By the manifest's own name, never the array key: a name all in
        // digits is an int key, and an int is a TypeError at visit(string).
        foreach ($byName as $manifest) {
            self::visit($manifest->name, $byName, $state, $order, $skipped);
        }

        return ['order' => $order, 'skipped' => $skipped];
    }

    /**
     * Depth-first: a plugin is appended after everything it requires. Returns
     * whether it (and therefore anything depending on it) can load at all.
     *
     * @param array<string, PluginManifest> $byName
     * @param array<string, string>         $state
     * @param list<PluginManifest>          $order
     * @param array<string, ConfigWarning>  $skipped
     */
    private static function visit(string $name, array $byName, array &$state, array &$order, array &$skipped): bool
    {
        // A cycle marks the node done AND skipped, so the check below answers false for it.
        if ('visiting' === ($state[$name] ?? null)) {
            $skipped[$name] = new ConfigWarning('Plugin "%name%" is part of a dependency cycle', ['%name%' => $name]);
            $state[$name] = 'done';
        }
        if ('done' === ($state[$name] ?? null)) {
            return !\array_key_exists($name, $skipped);
        }

        $state[$name] = 'visiting';
        $manifest = $byName[$name];
        $failure = null;
        foreach ($manifest->requires as $required) {
            $failure = match (true) {
                !\array_key_exists($required, $byName) => new ConfigWarning('Plugin "%name%" needs "%required%", which is not installed or is disabled', [
                    '%name%' => $name,
                    '%required%' => $required,
                ]),
                !self::visit($required, $byName, $state, $order, $skipped) => $skipped[$name] ?? new ConfigWarning('Plugin "%name%" needs "%required%", which could not load', [
                    '%name%' => $name,
                    '%required%' => $required,
                ]),
                default => null,
            };
            if (null !== $failure) {
                $skipped[$name] = $failure;

                break;
            }
        }

        // Nothing marks a node skipped and then lets it reach here: the cycle
        // branch marks a node that is mid-loop further up the stack, and that
        // loop bails on the false it gets back.
        $state[$name] = 'done';
        if (null === $failure) {
            $order[] = $manifest;
        }

        return null === $failure;
    }
}
