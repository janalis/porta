<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Plugin\Runtime\ToolInstallOffers;

/**
 * A {@see ToolInstallOffers} that answers from a fixed table.
 *
 * Declaring nothing is the interesting default: a plugin with no external tool
 * has nothing to wait for, so every caller must read "no offer" as ready
 * rather than as missing.
 */
final class FakeToolInstallOffers implements ToolInstallOffers
{
    /** @param array<string, array{tool: string, available: bool, via: list<array{0: string, 1: string}>, describe: string}> $offers by plugin name */
    public function __construct(
        private array $offers = [],
    ) {}

    /** Declare $plugin's tool, and whether it is there. */
    public function declare(string $plugin, string $tool, bool $available, string $manager = 'self', string $package = 'install', string $describe = ''): void
    {
        $this->offers[$plugin] = ['tool' => $tool, 'available' => $available, 'via' => [[$manager, $package]], 'describe' => $describe];
    }

    #[\Override]
    public function installOffer(string $plugin): ?array
    {
        return $this->offers[$plugin] ?? null;
    }
}
