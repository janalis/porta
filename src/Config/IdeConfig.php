<?php

declare(strict_types=1);

namespace Porta\Config;

use Porta\Support\JsonFile;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Project configuration loaded from .porta.json:
 *
 *   {
 *       "lsp": "php phpactor.phar language-server",
 *       "keymap": {
 *           "duplicate_line": ["ctrl+d"],
 *           "quit": ["ctrl+shift+q"]
 *       }
 *   }
 *
 * Keymap entries override the built-in defaults for the given action;
 * unknown actions are collected in warnings so the UI can surface them.
 */
final class IdeConfig
{
    /**
     * Editor/UI settings with their defaults. The `settings` block of
     * .porta.json overrides these (unknown keys are warned about).
     *
     * @var array<string, int|bool|string>
     */
    public const DEFAULT_SETTINGS = [
        'language' => 'en',
        'theme' => 'dark',
        'inlineDiagnostics' => true,
        'relativeLineNumbers' => false,
        'autoPairs' => true,
        'formatOnSave' => false,
        'cursorShape' => 'bar',
        'tabBarPosition' => 'top',
        'gitShowBranch' => true,
        'gitGutterMarks' => true,
        'gitBlameDefault' => false,
        'gitSignCommits' => false,
        'gitNoVerify' => false,
        'gitSignoff' => false,
        'gitRefreshInterval' => 2,
        'gitPath' => '',
    ];

    /**
     * Setting keys that used to exist: silently skipped at load so a stale
     * value in an older .porta.json never warns. `editorConfig` was the
     * "Respect .editorconfig" toggle — .editorconfig is always honored now,
     * superseded per language by the indent settings.
     */
    private const LEGACY_SETTINGS = ['editorConfig'];

    /** The per-language indent override keys and their allowed values. */
    private const INDENT_STYLES = ['auto', 'spaces', 'tabs'];

    /**
     * @param array<string, list<string>>                                                                                                    $keymap
     * @param array<string, int|bool|string>                                                                                                 $settings   resolved settings (defaults merged)
     * @param array<string, array{indentStyle?: string, indentSize?: int|string, debug?: array{adapter?: string, launch?: array<string, mixed>}}> $languages  per-language overrides
     * @param array<string, string>                                                                                                          $lspServers per-language LSP command overrides (map form)
     * @param array<string, array<string, bool|int|string>>                                                                                  $plugins    raw per-plugin setting values (coerced at read by PluginSettings)
     * @param list<string>                                                                                                                   $disabledPlugins plugin names skipped at boot (bundled plugins are disableable, not removable)
     * @param list<string>                                                                                                                   $warnings
     */
    private function __construct(
        public readonly array $keymap,
        public readonly array $settings,
        public readonly array $languages,
        public readonly array $lspServers,
        public readonly array $plugins,
        public readonly array $disabledPlugins,
        public readonly array $warnings,
    ) {}

    public static function load(string $rootDir): self
    {
        $file = $rootDir.'/.porta.json';
        if (!is_file($file)) {
            return new self([], self::DEFAULT_SETTINGS, [], [], [], [], []);
        }

        $config = JsonFile::read($file);
        if (null === $config) {
            return new self([], self::DEFAULT_SETTINGS, [], [], [], [], ['.porta.json is not valid JSON']);
        }

        $keymap = [];
        $warnings = [];
        /** @var array<string, string|int|list<string>> $keymapConfig */
        $keymapConfig = $config['keymap'] ?? [];
        foreach ($keymapConfig as $action => $keys) {
            // Namespaced ids ("plugin:command") belong to plugins registered at
            // runtime — they bypass the catalog check and bind if the plugin loads.
            if ((ActionCatalog::ACTIONS[$action] ?? null) === null && !str_contains($action, ':')) {
                $warnings[] = \sprintf('Unknown keymap action "%s" in .porta.json', $action);
                continue;
            }
            $keys = \is_string($keys) ? [$keys] : $keys;
            if (!\is_array($keys)) {
                $warnings[] = \sprintf('Keymap action "%s" must map to a key or list of keys', $action);
                continue;
            }
            $keymap[$action] = array_values(array_map('strval', $keys));
        }

        $settings = self::DEFAULT_SETTINGS;
        /** @var array<string, scalar> $settingsConfig */
        $settingsConfig = $config['settings'] ?? [];
        foreach ($settingsConfig as $key => $value) {
            if (\in_array($key, self::LEGACY_SETTINGS, strict: true)) {
                continue;
            }
            if (!\array_key_exists($key, self::DEFAULT_SETTINGS)) {
                $warnings[] = \sprintf('Unknown setting "%s" in .porta.json', $key);
                continue;
            }
            // Coerce to the default's type (bool, int, or string).
            $default = self::DEFAULT_SETTINGS[$key];
            $settings[$key] = match (true) {
                \is_bool($default) => (bool) $value,
                \is_int($default) => (int) $value,
                default => (string) $value,
            };
        }

        /** @var array<string, mixed> $languagesConfig */
        $languagesConfig = \is_array($config['languages'] ?? null) ? $config['languages'] : [];
        $languages = self::parseLanguages($languagesConfig, $warnings);
        $lspServers = self::parseLspServers($config['lsp'] ?? null);
        $plugins = self::parsePluginSettings(\is_array($config['plugins'] ?? null) ? $config['plugins'] : [], $warnings);
        $disabledPlugins = [];
        if (\is_array($config['disabledPlugins'] ?? null)) {
            $disabledPlugins = array_values(array_map(strval(...), array_filter($config['disabledPlugins'], is_scalar(...))));
        }

        return new self($keymap, $settings, $languages, $lspServers, $plugins, $disabledPlugins, $warnings);
    }

    /**
     * The raw per-plugin setting values from the top-level `plugins` block.
     * Values stay uncoerced — descriptors are only known once plugins load;
     * {@see \Porta\Plugin\PluginSettings} coerces at read time.
     *
     * @param array<array-key, mixed> $config
     * @param list<string>            $warnings
     *
     * @return array<string, array<string, bool|int|string>>
     */
    private static function parsePluginSettings(array $config, array &$warnings): array
    {
        $plugins = [];
        /** @var mixed $values */
        foreach ($config as $plugin => $values) {
            if (!\is_array($values)) {
                $warnings[] = \sprintf('Plugin settings for "%s" must be an object', $plugin);
                continue;
            }
            /** @var mixed $value */
            foreach ($values as $key => $value) {
                if (!\is_scalar($value)) {
                    continue;
                }

                $plugins[(string) $plugin][(string) $key] = \is_float($value) ? (string) $value : $value;
            }
        }

        return $plugins;
    }

    /**
     * Read the per-language `lsp` map ({languageId: command}) into command
     * strings for the settings screen. A single-command form (string / argv
     * list) can't hold per-language entries, so it yields nothing here — the
     * settings UI shows those languages as unset and converts to a map on edit.
     *
     * @return array<string, string>
     */
    private static function parseLspServers(mixed $lsp): array
    {
        if (!\is_array($lsp) || [] === $lsp || array_is_list($lsp)) {
            return [];
        }

        $servers = [];
        foreach (array_keys($lsp) as $language) {
            $servers[(string) $language] = self::commandString($lsp[$language]);
        }

        return $servers;
    }

    /** A raw `lsp` value (string or argv list) as a single command string. */
    private static function commandString(mixed $command): string
    {
        if (\is_array($command)) {
            return implode(' ', array_map(strval(...), $command));
        }

        return \is_string($command) ? $command : '';
    }

    /**
     * Validate the `languages` block: known language ids, `indentStyle` in
     * {auto,spaces,tabs}, `indentSize` = 'auto' or a positive int. Invalid
     * entries are dropped with a warning.
     *
     * @param array<array-key, mixed> $config
     * @param list<string>            $warnings
     *
     * @return array<string, array{indentStyle?: string, indentSize?: int|string, debug?: array{adapter?: string, launch?: array<string, mixed>}}>
     */
    private static function parseLanguages(array $config, array &$warnings): array
    {
        $languages = [];
        foreach (array_keys($config) as $lang) {
            $lang = (string) $lang;
            if (!LanguageCatalog::isKnown($lang)) {
                $warnings[] = \sprintf('Unknown language "%s" in .porta.json', $lang);
                continue;
            }
            $entry = self::languageEntry($config[$lang]);
            if (null === $entry) {
                $warnings[] = \sprintf('Language "%s" must map to an object of indent settings', $lang);
                continue;
            }
            if ([] !== $entry) {
                $languages[$lang] = $entry;
            }
        }

        return $languages;
    }

    /**
     * Extract the valid overrides from one language's raw value, or null when
     * it is not an object. Invalid sub-values are dropped like invalid indent
     * values are.
     *
     * @return array{indentStyle?: string, indentSize?: int|string, debug?: array{adapter?: string, launch?: array<string, mixed>}}|null
     */
    private static function languageEntry(mixed $props): ?array
    {
        if (!\is_array($props)) {
            return null;
        }

        $entry = [];
        $style = self::indentStyleValue($props['indentStyle'] ?? null);
        if (null !== $style) {
            $entry['indentStyle'] = $style;
        }
        $size = self::indentSizeValue($props['indentSize'] ?? null);
        if (null !== $size) {
            $entry['indentSize'] = $size;
        }
        $debug = self::debugValue($props['debug'] ?? null);
        if ([] !== $debug) {
            $entry['debug'] = $debug;
        }

        return $entry;
    }

    /**
     * The valid parts of a language's `debug` block: `adapter` (command string
     * or argv list) and `launch` (a JSON object merged into the DAP launch
     * request). Anything invalid is dropped.
     *
     * @return array{adapter?: string, launch?: array<string, mixed>}
     */
    private static function debugValue(mixed $debug): array
    {
        if (!\is_array($debug)) {
            return [];
        }
        $entry = [];
        $adapter = self::commandString($debug['adapter'] ?? null);
        if ('' !== $adapter) {
            $entry['adapter'] = $adapter;
        }
        /** @var mixed $launch */
        $launch = $debug['launch'] ?? null;
        if (\is_array($launch) && !array_is_list($launch)) {
            /** @var array<string, mixed> $launch */
            $entry['launch'] = $launch;
        }

        return $entry;
    }

    private static function indentStyleValue(mixed $value): ?string
    {
        return \is_string($value) && \in_array($value, self::INDENT_STYLES, strict: true) ? $value : null;
    }

    private static function indentSizeValue(mixed $value): int|string|null
    {
        if ('auto' === $value) {
            return 'auto';
        }
        if (\is_int($value) && $value >= 1) {
            return $value;
        }

        return \is_string($value) && 1 === preg_match('/^\d+$/', $value) ? max(1, (int) $value) : null;
    }

    /**
     * Persist a single setting into .porta.json, merging with and preserving
     * any existing content (including unknown keys). Returns true on success.
     */
    public static function persistSetting(string $rootDir, string $key, int|bool|string $value): bool
    {
        $file = $rootDir.'/.porta.json';
        $config = JsonFile::read($file) ?? [];
        $settings = \is_array($config['settings'] ?? null) ? $config['settings'] : [];
        $settings[$key] = $value;
        $config['settings'] = $settings;

        return self::write($file, $config);
    }

    /**
     * Persist an action's key binding into .porta.json's `keymap` block, merging
     * with and preserving any existing content (other actions, unknown keys).
     * The override takes effect next launch, when the keymap is merged into the
     * live keybindings. Returns true on success.
     *
     * @param list<string> $keys
     */
    public static function persistKeymap(string $rootDir, string $action, array $keys): bool
    {
        $file = $rootDir.'/.porta.json';
        $config = JsonFile::read($file) ?? [];
        $keymap = \is_array($config['keymap'] ?? null) ? $config['keymap'] : [];
        $keymap[$action] = array_values($keys);
        $config['keymap'] = $keymap;

        return self::write($file, $config);
    }

    /**
     * Persist one per-language indent override into .porta.json's `languages`
     * block, merging with and preserving existing content. Returns true on
     * success.
     */
    /** Persist a plugin as enabled by dropping it from the top-level `disabledPlugins` list. */
    public static function persistPluginEnabled(string $rootDir, string $plugin): bool
    {
        $file = $rootDir.'/.porta.json';
        $config = JsonFile::read($file) ?? [];

        return self::writeDisabledPlugins($file, $config, self::disabledWithout($config, $plugin));
    }

    /** Persist a plugin as disabled by adding it to the top-level `disabledPlugins` list. */
    public static function persistPluginDisabled(string $rootDir, string $plugin): bool
    {
        $file = $rootDir.'/.porta.json';
        $config = JsonFile::read($file) ?? [];

        return self::writeDisabledPlugins($file, $config, [...self::disabledWithout($config, $plugin), $plugin]);
    }

    /**
     * The current `disabledPlugins` list, normalized to strings, minus $plugin.
     *
     * @param array<array-key, mixed> $config
     *
     * @return list<string>
     */
    private static function disabledWithout(array $config, string $plugin): array
    {
        $disabled = \is_array($config['disabledPlugins'] ?? null) ? array_values(array_map(strval(...), array_filter($config['disabledPlugins'], is_scalar(...)))) : [];

        return array_values(array_diff($disabled, [$plugin]));
    }

    /**
     * Write the `disabledPlugins` list back (dropping the key when empty).
     *
     * @param array<array-key, mixed> $config
     * @param list<string>            $disabled
     */
    private static function writeDisabledPlugins(string $file, array $config, array $disabled): bool
    {
        if ([] === $disabled) {
            unset($config['disabledPlugins']);
        }
        if ([] !== $disabled) {
            $config['disabledPlugins'] = $disabled;
        }

        return self::write($file, $config);
    }

    /** Persist one plugin-defined setting value under the top-level `plugins.<name>.<key>`. */
    public static function persistPluginSetting(string $rootDir, string $plugin, string $key, bool|int|string $value): bool
    {
        $file = $rootDir.'/.porta.json';
        $config = JsonFile::read($file) ?? [];
        $plugins = \is_array($config['plugins'] ?? null) ? $config['plugins'] : [];
        $entry = \is_array($plugins[$plugin] ?? null) ? $plugins[$plugin] : [];
        $entry[$key] = $value;
        $plugins[$plugin] = $entry;
        $config['plugins'] = $plugins;

        return self::write($file, $config);
    }

    public static function persistLanguageSetting(string $rootDir, string $language, string $key, int|string $value): bool
    {
        $file = $rootDir.'/.porta.json';
        $config = JsonFile::read($file) ?? [];
        $languages = \is_array($config['languages'] ?? null) ? $config['languages'] : [];
        $entry = \is_array($languages[$language] ?? null) ? $languages[$language] : [];
        $entry[$key] = $value;
        $languages[$language] = $entry;
        $config['languages'] = $languages;

        return self::write($file, $config);
    }

    /**
     * Persist one per-language debug override (`adapter` command string or
     * `launch` object) into .porta.json's `languages.<id>.debug` block. A null
     * / blank / empty value removes the key; an emptied `debug` block is
     * dropped (the language entry itself is preserved). True on success.
     *
     * @param string|array<string, mixed>|null $value
     */
    public static function persistLanguageDebug(string $rootDir, string $language, string $key, string|array|null $value): bool
    {
        $file = $rootDir.'/.porta.json';
        $config = JsonFile::read($file) ?? [];
        $languages = \is_array($config['languages'] ?? null) ? $config['languages'] : [];
        $entry = \is_array($languages[$language] ?? null) ? $languages[$language] : [];
        $debug = \is_array($entry['debug'] ?? null) ? $entry['debug'] : [];

        $removeKey = null === $value || '' === $value || [] === $value;
        if ($removeKey) {
            unset($debug[$key]);
        }
        if (!$removeKey) {
            $debug[$key] = $value;
        }

        if ([] === $debug) {
            unset($entry['debug']);
        }
        if ([] !== $debug) {
            $entry['debug'] = $debug;
        }
        $languages[$language] = $entry;
        $config['languages'] = $languages;

        return self::write($file, $config);
    }

    /**
     * Persist a per-language LSP server command into .porta.json's `lsp` map.
     * A blank command removes the language's entry (reverting to auto-detect);
     * an empty map drops the whole `lsp` block. A single-command form is
     * converted to a map (its command kept as the `*` catch-all). True on success.
     */
    public static function persistLspServer(string $rootDir, string $languageId, string $command): bool
    {
        $file = $rootDir.'/.porta.json';
        $config = JsonFile::read($file) ?? [];
        $map = self::lspMap($config['lsp'] ?? null);

        $command = trim($command);
        if ('' === $command) {
            unset($map[$languageId]);
        }
        if ('' !== $command) {
            $map[$languageId] = $command;
        }

        if ([] === $map) {
            unset($config['lsp']);
        }
        if ([] !== $map) {
            $config['lsp'] = $map;
        }

        return self::write($file, $config);
    }

    /**
     * Normalize any `lsp` value to a {languageId: command} map: a map is coerced
     * to command strings; a single command string/argv list becomes the `*`
     * catch-all; anything else is empty.
     *
     * @return array<string, string>
     */
    private static function lspMap(mixed $lsp): array
    {
        if (\is_array($lsp) && [] !== $lsp && array_is_list($lsp)) {
            return ['*' => self::commandString($lsp)];
        }
        if (\is_string($lsp) && '' !== $lsp) {
            return ['*' => $lsp];
        }
        if (!\is_array($lsp)) {
            return [];
        }

        $map = [];
        foreach (array_keys($lsp) as $language) {
            $map[(string) $language] = self::commandString($lsp[$language]);
        }

        return $map;
    }

    /**
     * Atomically write the merged config back, pretty-printed; false on IO error.
     *
     * @param array<array-key, mixed> $config
     */
    private static function write(string $file, array $config): bool
    {
        try {
            new Filesystem()->dumpFile(
                $file,
                json_encode($config, JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES).\PHP_EOL,
            );
        } catch (IOExceptionInterface) {
            return false;
        }

        return true;
    }
}
