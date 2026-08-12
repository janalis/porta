<?php

declare(strict_types=1);

namespace Porta\Config;

use Porta\Support\JsonFile;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Writes settings back into a project's `.porta.json`, merging with and
 * preserving everything already there (including keys this build does not
 * know). The read half is {@see IdeConfig}; the two share nothing but that
 * file's value vocabulary ({@see IdeConfig::stringList()},
 * {@see IdeConfig::commandString()}, {@see IdeConfig::LEGACY_SETTINGS}) and no
 * state at all, which is why they are separate classes.
 *
 * Every public method is one edit: read the file, mutate one block, write it
 * back atomically, and answer whether that succeeded. Nothing here is cached —
 * a write always re-reads, so two settings pages editing different blocks
 * cannot clobber each other.
 *
 * A stateless shared service: consumers inject it (tests hand in a fake), and
 * the Filesystem behind the atomic write is a visible constructor input rather
 * than an inline construction the callers could never reach.
 */
final readonly class PortaJsonWriter
{
    private const string FILE = '/.porta.json';

    public function __construct(
        private Filesystem $fs = new Filesystem(),
    ) {}

    /**
     * Persist a single setting into .porta.json, merging with and preserving
     * any existing content (including unknown keys). Returns true on success.
     */
    public function persistSetting(string $rootDir, string $key, int|bool|string $value): bool
    {
        return $this->edit($rootDir, static function (array &$config) use ($key, $value): void {
            $settings = self::section($config, 'settings');
            $settings[$key] = $value;
            // Only core-obsoleted keys are stripped here. Stripping EVERY
            // legacy key (as this once did) destroyed values the git plugin's
            // one-time adoption had not read yet, and dropped
            // relativeLineNumbers before lineNumbers ever recorded the
            // migrated preference.
            foreach (IdeConfig::OBSOLETE_SETTINGS as $obsolete) {
                unset($settings[$obsolete]);
            }
            foreach (IdeConfig::SUPERSEDED_BY as $legacy => $successor) {
                if ($key === $successor) {
                    unset($settings[$legacy]);
                }
            }
            $config['settings'] = $settings;
        });
    }

    /**
     * Persist an action's key binding into .porta.json's `keymap` block, merging
     * with and preserving any existing content (other actions, unknown keys).
     * The override takes effect next launch, when the keymap is merged into the
     * live keybindings. Returns true on success.
     *
     * @param list<string> $keys
     */
    public function persistKeymap(string $rootDir, string $action, array $keys): bool
    {
        return $this->edit($rootDir, static function (array &$config) use ($action, $keys): void {
            $keymap = self::section($config, 'keymap');
            $keymap[$action] = array_values($keys);
            $config['keymap'] = $keymap;
        });
    }

    /** Persist a project/global plugin as enabled by dropping it from the `disabledPlugins` list. */
    public function persistPluginEnabled(string $rootDir, string $plugin): bool
    {
        return $this->persistPluginMembership($rootDir, 'disabledPlugins', $plugin, member: false);
    }

    /** Persist a project/global plugin as disabled by adding it to the `disabledPlugins` list. */
    public function persistPluginDisabled(string $rootDir, string $plugin): bool
    {
        return $this->persistPluginMembership($rootDir, 'disabledPlugins', $plugin, member: true);
    }

    /** Persist a bundled plugin as enabled by adding it to the `enabledPlugins` list (the absent-key default materialized first). */
    public function persistBundledEnabled(string $rootDir, string $plugin): bool
    {
        return $this->persistPluginMembership($rootDir, 'enabledPlugins', $plugin, member: true, default: IdeConfig::DEFAULT_ENABLED_PLUGINS);
    }

    /** Persist a bundled plugin as disabled by dropping it from the `enabledPlugins` list (the absent-key default materialized first). */
    public function persistBundledDisabled(string $rootDir, string $plugin): bool
    {
        return $this->persistPluginMembership($rootDir, 'enabledPlugins', $plugin, member: false, default: IdeConfig::DEFAULT_ENABLED_PLUGINS);
    }

    /** Persist one plugin-defined setting value under the top-level `plugins.<name>.<key>`. */
    public function persistPluginSetting(string $rootDir, string $plugin, string $key, bool|int|string $value): bool
    {
        return $this->edit($rootDir, static function (array &$config) use ($plugin, $key, $value): void {
            $plugins = self::section($config, 'plugins');
            $entry = \is_array($plugins[$plugin] ?? null) ? $plugins[$plugin] : [];
            $entry[$key] = $value;
            $plugins[$plugin] = $entry;
            $config['plugins'] = $plugins;
        });
    }

    /**
     * Write the top-level `highlight` block. An empty $id clears the entry —
     * "no preference" and "prefer nothing" are the same thing, and leaving an
     * empty string behind would look like a configured backend named "".
     */
    public function persistHighlighter(string $rootDir, ?string $language, string $id): bool
    {
        return $this->edit($rootDir, static function (array &$config) use ($language, $id): void {
            $highlight = self::section($config, 'highlight');

            if (null === $language) {
                $highlight['default'] = $id;
                if ('' === $id) {
                    unset($highlight['default']);
                }
            }

            if (null !== $language) {
                $languages = \is_array($highlight['languages'] ?? null) ? $highlight['languages'] : [];
                $languages[$language] = $id;
                if ('' === $id) {
                    unset($languages[$language]);
                }
                $highlight['languages'] = $languages;
                if ([] === $languages) {
                    unset($highlight['languages']);
                }
            }

            $config['highlight'] = $highlight;
            if ([] === $highlight) {
                unset($config['highlight']);
            }
        });
    }

    /**
     * Persist one per-language indent override into .porta.json's `languages`
     * block, merging with and preserving existing content. Returns true on
     * success.
     */
    public function persistLanguageSetting(string $rootDir, string $language, string $key, int|string $value): bool
    {
        return $this->edit($rootDir, static function (array &$config) use ($language, $key, $value): void {
            $languages = self::section($config, 'languages');
            $entry = \is_array($languages[$language] ?? null) ? $languages[$language] : [];
            $entry[$key] = $value;
            $languages[$language] = $entry;
            $config['languages'] = $languages;
        });
    }

    /**
     * Persist one per-language debug override (`adapter` command string or
     * `launch` object) into .porta.json's `languages.<id>.debug` block. A null
     * / blank / empty value removes the key; an emptied `debug` block is
     * dropped (the language entry itself is preserved). True on success.
     *
     * @param string|array<string, mixed>|null $value
     */
    public function persistLanguageDebug(string $rootDir, string $language, string $key, string|array|null $value): bool
    {
        return $this->edit($rootDir, static function (array &$config) use ($language, $key, $value): void {
            $languages = self::section($config, 'languages');
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
        });
    }

    /**
     * Persist a per-language LSP server command into .porta.json's `lsp` map.
     * A blank command removes the language's entry (reverting to auto-detect);
     * an empty map drops the whole `lsp` block. A single-command form is
     * converted to a map (its command kept as the `*` catch-all). True on success.
     */
    public function persistLspServer(string $rootDir, string $languageId, string $command): bool
    {
        return $this->edit($rootDir, static function (array &$config) use ($languageId, $command): void {
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
        });
    }

    /** Add ($member) or drop $plugin from a top-level string-list key, dropping the key when empty. */
    // @mago-expect lint:no-boolean-flag-parameter -- shared add/drop membership helper; the four public persist* methods are its split faces
    /**
     * @param list<string> $default the list an ABSENT key means (bundled
     *                              plugins default some names ON, so removing a
     *                              name from the default state must write the
     *                              default minus that name — and an empty
     *                              result must stay as an explicit `[]`, or the
     *                              unset key would flip the default back on)
     */
    private function persistPluginMembership(string $rootDir, string $key, string $plugin, bool $member, array $default = []): bool
    {
        return $this->edit($rootDir, static function (array &$config) use ($key, $plugin, $member, $default): void {
            $current = \array_key_exists($key, $config) ? IdeConfig::stringList($config[$key]) : $default;
            $without = array_values(array_diff($current, [$plugin]));
            $list = $member ? [...$without, $plugin] : $without;
            if ([] === $list && [] === $default) {
                unset($config[$key]);

                return;
            }
            $config[$key] = $list;
        });
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
            return ['*' => IdeConfig::commandString($lsp)];
        }
        if (\is_string($lsp) && '' !== $lsp) {
            return ['*' => $lsp];
        }
        if (!\is_array($lsp)) {
            return [];
        }

        $map = [];
        foreach (array_keys($lsp) as $language) {
            $map[(string) $language] = IdeConfig::commandString($lsp[$language]);
        }

        return $map;
    }

    /**
     * The read-modify-write every persist* shares: load the file (missing or
     * unparseable reads as empty), hand the decoded config to $mutate by
     * reference, write it back. Spelled once here rather than eight times.
     *
     * @param \Closure(array<array-key, mixed>): void $mutate
     */
    private function edit(string $rootDir, \Closure $mutate): bool
    {
        $file = $rootDir.self::FILE;
        $config = JsonFile::read($file) ?? [];
        $mutate($config);

        return $this->write($file, $config);
    }

    /**
     * A top-level block as an array, treating a missing or non-array value as empty.
     *
     * @param array<array-key, mixed> $config
     *
     * @return array<array-key, mixed>
     */
    private static function section(array $config, string $key): array
    {
        return \is_array($config[$key] ?? null) ? $config[$key] : [];
    }

    /**
     * Atomically write the merged config back, pretty-printed; false on IO error.
     *
     * @param array<array-key, mixed> $config
     */
    private function write(string $file, array $config): bool
    {
        return JsonFile::write($this->fs, $file, $config, trailingNewline: true);
    }
}
