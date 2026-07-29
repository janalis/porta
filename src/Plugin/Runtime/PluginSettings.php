<?php

declare(strict_types=1);

namespace Porta\Plugin\Runtime;

use Porta\Config\IdeConfig;
use Porta\Config\PortaJsonWriter;
use Porta\Plugin\Api\PluginApi;
use Porta\Settings\SettingsRow;
use Porta\Settings\SettingsRowKind;

/**
 * Committed plugin setting descriptors and their live values. Values persist
 * in `.porta.json` under `plugins.<name>.<key>` and are seeded from
 * {@see IdeConfig} before plugins load, so `PluginApi::setting()` works during
 * activate(). Raw config values are coerced to each descriptor's kind at read
 * time (the descriptors are unknown when the config is parsed). Descriptor
 * labels and hints come from third-party plugin code and are shown verbatim,
 * untranslated. {@see removeFor} drops a removed plugin's descriptors only —
 * persisted values survive a reinstall.
 *
 * @phpstan-type Descriptor array{key: string, label: string, kind: string, default: bool|int|string, options: list<string>|null, hint: string|null}
 */
final class PluginSettings
{
    /** @var array<string, array<string, Descriptor>> plugin => key => descriptor */
    private array $descriptors = [];

    /** @var array<string, array<string, bool|int|string>> live values (config-seeded, updated on change) */
    private array $values;

    /** @param array<string, array<string, bool|int|string>> $values IdeConfig's plugins block */
    public function __construct(
        private readonly string $rootDir,
        array $values,
    ) {
        $this->values = $values;
    }

    /** @param list<Descriptor> $descriptors a committed plugin's buffered setting descriptors */
    public function registerFor(string $plugin, array $descriptors): void
    {
        foreach ($descriptors as $descriptor) {
            $this->descriptors[$plugin][$descriptor['key']] = $descriptor;
        }
    }

    /** Drop a removed plugin's descriptors; its persisted values stay for a reinstall. */
    public function removeFor(string $plugin): void
    {
        unset($this->descriptors[$plugin]);
    }

    /** The raw (uncoerced) live value, or null when the user never set one. */
    public function raw(string $plugin, string $key): bool|int|string|null
    {
        return $this->values[$plugin][$key] ?? null;
    }

    /**
     * The settings-form rows for one plugin, keyed `pluginSetting:<key>`.
     *
     * @return list<SettingsRow>
     */
    public function rows(string $plugin): array
    {
        $rows = [];
        foreach ($this->descriptors[$plugin] ?? [] as $descriptor) {
            $rows[] = new SettingsRow(
                key: 'pluginSetting:'.$descriptor['key'],
                label: $descriptor['label'],
                kind: SettingsRowKind::from($descriptor['kind']),
                value: $this->value($plugin, $descriptor['key']),
                restart: false,
                options: $descriptor['options'],
                hint: $descriptor['hint'],
            );
        }

        return $rows;
    }

    /** The current value coerced to the descriptor's kind (its default when unset/unknown). */
    public function value(string $plugin, string $key): bool|int|string
    {
        $descriptor = $this->descriptors[$plugin][$key] ?? null;
        if (null === $descriptor) {
            return '';
        }

        return $this->coerce($descriptor, $this->raw($plugin, $key));
    }

    /** Apply an edit: coerce, keep live, persist into .porta.json. */
    public function change(string $plugin, string $key, bool|int|string $value): void
    {
        $descriptor = $this->descriptors[$plugin][$key] ?? null;
        if (null === $descriptor) {
            return;
        }
        $coerced = $this->coerce($descriptor, $value);
        $this->values[$plugin][$key] = $coerced;
        PortaJsonWriter::persistPluginSetting($this->rootDir, $plugin, $key, $coerced);
    }

    /** @param Descriptor $descriptor */
    private function coerce(array $descriptor, bool|int|string|null $value): bool|int|string
    {
        if (null === $value) {
            return $descriptor['default'];
        }

        return SettingsRowKind::from($descriptor['kind'])->coerce($value, $descriptor['default'], $descriptor['options'] ?? []);
    }
}
