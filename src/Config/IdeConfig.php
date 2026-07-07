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
        'tabWidth' => 4,
        'indentStyle' => 'auto',
        'inlineDiagnostics' => true,
        'relativeLineNumbers' => false,
        'autoPairs' => true,
        'editorConfig' => true,
    ];

    /**
     * @param array<string, list<string>>  $keymap
     * @param array<string, int|bool|string> $settings resolved settings (defaults merged)
     * @param list<string>                  $warnings
     */
    private function __construct(
        public readonly array $keymap,
        public readonly array $settings,
        public readonly array $warnings,
    ) {}

    public static function load(string $rootDir): self
    {
        $file = $rootDir.'/.porta.json';
        if (!is_file($file)) {
            return new self([], self::DEFAULT_SETTINGS, []);
        }

        $config = JsonFile::read($file);
        if (null === $config) {
            return new self([], self::DEFAULT_SETTINGS, ['.porta.json is not valid JSON']);
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
            // Coerce to the default's type.
            $settings[$key] = match (\gettype(self::DEFAULT_SETTINGS[$key])) {
                'boolean' => (bool) $value,
                'integer' => max(1, (int) $value),
                default => (string) $value,
            };
        }

        return new self($keymap, $settings, $warnings);
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
