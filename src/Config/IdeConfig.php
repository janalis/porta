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
        'theme' => 'dark',
        'inlineDiagnostics' => true,
        'relativeLineNumbers' => false,
        'autoPairs' => true,
        'editorConfig' => true,
    ];

    /** The per-language indent override keys and their allowed values. */
    private const INDENT_STYLES = ['auto', 'spaces', 'tabs'];

    /**
     * @param array<string, list<string>>                                          $keymap
     * @param array<string, int|bool|string>                                       $settings  resolved settings (defaults merged)
     * @param array<string, array{indentStyle?: string, indentSize?: int|string}>  $languages per-language indent overrides
     * @param list<string>                                                         $warnings
     */
    private function __construct(
        public readonly array $keymap,
        public readonly array $settings,
        public readonly array $languages,
        public readonly array $warnings,
    ) {}

    public static function load(string $rootDir): self
    {
        $file = $rootDir.'/.porta.json';
        if (!is_file($file)) {
            return new self([], self::DEFAULT_SETTINGS, [], []);
        }

        $config = JsonFile::read($file);
        if (null === $config) {
            return new self([], self::DEFAULT_SETTINGS, [], ['.porta.json is not valid JSON']);
        }

        $keymap = [];
        $warnings = [];
        /** @var array<string, string|int|list<string>> $keymapConfig */
        $keymapConfig = $config['keymap'] ?? [];
        foreach ($keymapConfig as $action => $keys) {
            if (!isset(ActionCatalog::ACTIONS[$action])) {
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
            if (!\array_key_exists($key, self::DEFAULT_SETTINGS)) {
                $warnings[] = \sprintf('Unknown setting "%s" in .porta.json', $key);
                continue;
            }
            // Coerce to the default's type (bool or string; no int settings today).
            $settings[$key] = \is_bool(self::DEFAULT_SETTINGS[$key]) ? (bool) $value : (string) $value;
        }

        /** @var array<string, mixed> $languagesConfig */
        $languagesConfig = \is_array($config['languages'] ?? null) ? $config['languages'] : [];
        $languages = self::parseLanguages($languagesConfig, $warnings);

        return new self($keymap, $settings, $languages, $warnings);
    }

    /**
     * Validate the `languages` block: known language ids, `indentStyle` in
     * {auto,spaces,tabs}, `indentSize` = 'auto' or a positive int. Invalid
     * entries are dropped with a warning.
     *
     * @param array<string, mixed> $config
     * @param list<string>         $warnings
     *
     * @return array<string, array{indentStyle?: string, indentSize?: int|string}>
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
            } elseif ([] !== $entry) {
                $languages[$lang] = $entry;
            }
        }

        return $languages;
    }

    /**
     * Extract the valid indent overrides from one language's raw value, or null
     * when it is not an object.
     *
     * @return array{indentStyle?: string, indentSize?: int|string}|null
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

        return $entry;
    }

    private static function indentStyleValue(mixed $value): ?string
    {
        return \is_string($value) && \in_array($value, self::INDENT_STYLES, true) ? $value : null;
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
