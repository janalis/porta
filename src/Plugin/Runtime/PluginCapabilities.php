<?php

declare(strict_types=1);

namespace Porta\Plugin\Runtime;

use Porta\Plugin\Api\Capability;
use Porta\Ui\Graphics\RasterImage;

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

    /** How many times what is provided has changed: a consumer that keeps what it could not do retries when it moves. */
    private int $changes = 0;

    /** @param list<array{plugin: string, capability: Capability, provider: \Closure}> $providers */
    public function add(array $providers): void
    {
        if ([] !== $providers) {
            $this->providers = [...$this->providers, ...$providers];
            ++$this->changes;
        }
    }

    public function remove(string $plugin): void
    {
        $remaining = \array_values(\array_filter(
            $this->providers,
            static fn (array $row): bool => $plugin !== $row['plugin'],
        ));
        if ($remaining !== $this->providers) {
            $this->providers = $remaining;
            ++$this->changes;
        }
    }

    public function changes(): int
    {
        return $this->changes;
    }

    /**
     * Pictures made PNGs with the SVG converter a plugin lends, when one does
     * — the one {@see RasterImage} core draws with: the container's service,
     * built here because only this registry knows what is lent.
     */
    public function rasterImage(): RasterImage
    {
        return new RasterImage(lent: fn (): ?\Closure => $this->first(Capability::SvgRasterizer));
    }

    /** The closure the first plugin standing provides for $capability, or null when none does. */
    public function first(Capability $capability): ?\Closure
    {
        return \array_find($this->providers, static fn (array $row): bool => $capability === $row['capability'])['provider'] ?? null;
    }
}
