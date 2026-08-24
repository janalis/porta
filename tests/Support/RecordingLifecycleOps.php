<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Plugin\Lifecycle\PluginLifecycleOps;

/**
 * A lifecycle that records instead of acting — for asserting that an API path
 * (e.g. {@see \Porta\Plugin\Api\PluginApi::changeSetting()}) reaches the
 * lifecycle hooks, without dragging the real enable/install machinery into a
 * unit test.
 */
final class RecordingLifecycleOps implements PluginLifecycleOps
{
    /** @var list<array{0: string, 1: string, 2: bool|int|string}> */
    public array $settingChanges = [];

    /** @var list<string> */
    public array $providerChanges = [];

    #[\Override]
    public function onPluginSettingChanged(string $name, string $key, bool|int|string $value): void
    {
        $this->settingChanges[] = [$name, $key, $value];
    }

    #[\Override]
    public function onProviderSettingChanged(string $name): void
    {
        $this->providerChanges[] = $name;
    }

    #[\Override]
    public function activateInstalled(string $file): ?string
    {
        return null;
    }

    #[\Override]
    public function deactivate(string $name): void {}

    #[\Override]
    public function disable(string $name): void {}

    #[\Override]
    public function enable(string $name): void {}

    #[\Override]
    public function isDisabled(string $name): bool
    {
        return false;
    }

    #[\Override]
    public function listedNames(): array
    {
        return [];
    }

    #[\Override]
    public function refreshView(): void {}
}
