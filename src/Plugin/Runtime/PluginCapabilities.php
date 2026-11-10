<?php

declare(strict_types=1);

namespace Porta\Plugin\Runtime;

use Porta\Plugin\Api\Capability;

/**
 * What plugins lend core ({@see \Porta\Plugin\Api\PluginApi::provide()}), in
 * plugin load order: committed when a plugin activates, taken back when it is
 * switched off. A consumer asks at each use, never keeps the answer, so a
 * plugin switched on or off live counts from the next use.
 */
final class PluginCapabilities
{
    /** @var list<array{plugin: string, capability: Capability, provider: \Closure}> */
    private array $providers = [];

    /** @param list<array{plugin: string, capability: Capability, provider: \Closure}> $providers */
    public function add(array $providers): void
    {
        $this->providers = [...$this->providers, ...$providers];
    }

    public function remove(string $plugin): void
    {
        $this->providers = \array_values(\array_filter(
            $this->providers,
            static fn (array $row): bool => $plugin !== $row['plugin'],
        ));
    }

    /** The closure the first plugin standing provides for $capability, or null when none does. */
    public function first(Capability $capability): ?\Closure
    {
        return \array_find($this->providers, static fn (array $row): bool => $capability === $row['capability'])['provider'] ?? null;
    }
}
