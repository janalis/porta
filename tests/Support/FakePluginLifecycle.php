<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Plugin\Lifecycle\PluginLifecycleOps;

/** Records every live plugin operation; fixtures drive the query answers. */
final class FakePluginLifecycle implements PluginLifecycleOps
{
    /** @var list<string> */
    public array $activated = [];

    /** The result the next activateInstalled() returns (a failure message or null). */
    public ?string $activateResult = null;

    /** @var list<string> */
    public array $deactivated = [];

    /** @var list<array{0: string, 1: bool}> */
    public array $toggled = [];

    public int $refreshed = 0;

    /** @var list<string> */
    public array $names = [];

    /** @var list<string> */
    public array $disabledNames = [];

    public function activateInstalled(string $file): ?string
    {
        $this->activated[] = $file;

        return $this->activateResult;
    }

    public function deactivate(string $name): void
    {
        $this->deactivated[] = $name;
    }

    public function enable(string $name): void
    {
        $this->toggled[] = [$name, true];
    }

    public function disable(string $name): void
    {
        $this->toggled[] = [$name, false];
    }

    /** @var list<string> plugins whose setting change triggered a provider reconfigure check */
    public array $providerSettingChanged = [];

    /** @var list<array{string, string, bool|int|string}> plugin, key and new value, as the Settings page reported them */
    public array $pluginSettingChanged = [];

    public function onProviderSettingChanged(string $name): void
    {
        $this->providerSettingChanged[] = $name;
    }

    public function onPluginSettingChanged(string $name, string $key, bool|int|string $value): void
    {
        $this->pluginSettingChanged[] = [$name, $key, $value];
        $this->onProviderSettingChanged($name);
    }

    public function isDisabled(string $name): bool
    {
        return \in_array($name, $this->disabledNames, strict: true);
    }

    public function listedNames(): array
    {
        return $this->names;
    }

    public function refreshView(): void
    {
        ++$this->refreshed;
    }
}
