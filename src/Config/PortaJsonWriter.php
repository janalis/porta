<?php

declare(strict_types=1);

namespace Porta\Config;

use Porta\Support\JsonFile;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Writes settings back into one of the two config files, merging with and
 * preserving everything already there (including keys this build does not
 * know). Which file is a {@see ConfigScope} on every method — a type change on
 * the parameter that used to be the project root, rather than a second method
 * per verb. The read half is {@see IdeConfig}; the two share nothing but that
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
    public function __construct(
        private ConfigFiles $files,
        private Filesystem $fs = new Filesystem(),
    ) {}

    /**
     * Persist a single setting into .porta.json, merging with and preserving
     * any existing content (including unknown keys). Returns true on success.
     */
    public function persistSetting(ConfigScope $scope, string $key, int|bool|string $value): bool
    {
        return $this->edit($scope, static function (array &$config) use ($key, $value): void {
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
                if ($key !== $successor) {
                    continue;
                }

                unset($settings[$legacy]);
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
    public function persistKeymap(ConfigScope $scope, string $action, array $keys): bool
    {
        return $this->edit($scope, static function (array &$config) use ($action, $keys): void {
            $keymap = self::section($config, 'keymap');
            $keymap[$action] = array_values($keys);
            $config['keymap'] = $keymap;
        });
    }

    /**
     * Drop an action's override so it goes back to the shipped default, and
     * take the whole `keymap` block with it once it holds nothing.
     *
     * The empty block is not left behind on purpose. An override is not only
     * a binding — it is a PIN: whatever this file says survives every future
     * change to the default, silently. A user who resets every action they
     * ever touched should end up with a file that says nothing about the
     * keymap, not one that says `{}` and reads like a decision.
     *
     * Returns whether the file changed: false both when nothing bound the
     * action and when the write failed, so the caller must know which it was
     * BEFORE calling (see {@see \Porta\Settings\SettingsKeymapController::resetBinding()}).
     */
    public function removeKeymap(ConfigScope $scope, string $action): bool
    {
        return $this->edit($scope, static function (array &$config) use ($action): void {
            $keymap = self::section($config, 'keymap');
            unset($keymap[$action]);
            if ([] === $keymap) {
                unset($config['keymap']);

                return;
            }

            $config['keymap'] = $keymap;
        });
    }

    /** Persist a project/global plugin as enabled by dropping it from the `disabledPlugins` list. */
    public function persistPluginEnabled(ConfigScope $scope, string $plugin): bool
    {
        return $this->persistPluginMembership($scope, 'disabledPlugins', $plugin, member: false);
    }

    /** Persist a project/global plugin as disabled by adding it to the `disabledPlugins` list. */
    public function persistPluginDisabled(ConfigScope $scope, string $plugin): bool
    {
        return $this->persistPluginMembership($scope, 'disabledPlugins', $plugin, member: true);
    }

    /** Persist a bundled plugin as enabled by adding it to the `enabledPlugins` list (the effective list materialized first). */
    public function persistBundledEnabled(ConfigScope $scope, string $plugin): bool
    {
        return $this->persistPluginMembership($scope, 'enabledPlugins', $plugin, member: true, default: $this->bundledBelow());
    }

    /** Persist a bundled plugin as disabled by dropping it from the `enabledPlugins` list (the effective list materialized first). */
    public function persistBundledDisabled(ConfigScope $scope, string $plugin): bool
    {
        return $this->persistPluginMembership($scope, 'enabledPlugins', $plugin, member: false, default: $this->bundledBelow());
    }

    /**
     * The `enabledPlugins` list already in effect BELOW the file being
     * written, which is what a FIRST write to that file has to start from.
     *
     * `enabledPlugins` is the one block where an explicit list REPLACES rather
     * than merges, so materialising the wrong starting point does not merely
     * lose a preference — it silently reverses one. Starting from the constant
     * default while a user's global file says `["git", "todo"]` turns "disable
     * git in this project" into "enable the three bundled plugins they had
     * turned off, everywhere in this project".
     *
     * One path serves both scopes, and a `Global === $scope` special case was
     * measured as an equivalent mutant rather than a guard: this is consulted
     * only when the TARGET file has no list of its own, and for a global write
     * that means the global file has none — so reading it back yields exactly
     * the constant that sits below it.
     *
     * @return list<string>
     */
    private function bundledBelow(): array
    {
        $global = $this->files->path(ConfigScope::Global);
        $config = (null === $global ? null : JsonFile::read($global)) ?? [];

        return \array_key_exists('enabledPlugins', $config)
            ? IdeConfig::stringList($config['enabledPlugins'])
            : IdeConfig::DEFAULT_ENABLED_PLUGINS;
    }

    /**
     * Remove a setting from .porta.json so it follows the product's default
     * again.
     *
     * Deliberately not "write the default": today the two are the same VALUE,
     * but a default that later moves would leave anyone who reset pinned to
     * the old one forever, silently. `lineNumbers` and DEFAULT_ENABLED_PLUGINS
     * have both moved. An absent key tracks the product; a written one freezes
     * it — which is the whole reason {@see \Porta\Plugin\Api\LegacyCoreSettings}
     * exists to repair.
     */
    public function resetSetting(ConfigScope $scope, string $key): bool
    {
        return $this->edit($scope, static function (array &$config) use ($key): void {
            $settings = self::section($config, 'settings');
            unset($settings[$key]);
            $config['settings'] = $settings;
            if ([] === $settings) {
                unset($config['settings']);
            }
        });
    }

    /** The same, for a plugin's own `plugins.<name>.<key>`. */
    public function resetPluginSetting(ConfigScope $scope, string $plugin, string $key): bool
    {
        return $this->edit($scope, static function (array &$config) use ($plugin, $key): void {
            $plugins = self::section($config, 'plugins');
            $entry = \is_array($plugins[$plugin] ?? null) ? $plugins[$plugin] : [];
            unset($entry[$key]);
            $plugins[$plugin] = $entry;
            if ([] === $entry) {
                unset($plugins[$plugin]);
            }
            $config['plugins'] = $plugins;
            if ([] === $plugins) {
                unset($config['plugins']);
            }
        });
    }

    /** Persist one plugin-defined setting value under the top-level `plugins.<name>.<key>`. */
    public function persistPluginSetting(ConfigScope $scope, string $plugin, string $key, bool|int|string $value): bool
    {
        return $this->edit($scope, static function (array &$config) use ($plugin, $key, $value): void {
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
    public function persistHighlighter(ConfigScope $scope, ?string $language, string $id): bool
    {
        return $this->edit($scope, static function (array &$config) use ($language, $id): void {
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
    public function persistLanguageSetting(ConfigScope $scope, string $language, string $key, int|string $value): bool
    {
        return $this->edit($scope, static function (array &$config) use ($language, $key, $value): void {
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
    public function persistLanguageDebug(ConfigScope $scope, string $language, string $key, string|array|null $value): bool
    {
        return $this->edit($scope, static function (array &$config) use ($language, $key, $value): void {
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
    public function persistLspServer(ConfigScope $scope, string $languageId, string $command): bool
    {
        return $this->edit($scope, static function (array &$config) use ($languageId, $command): void {
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
    private function persistPluginMembership(ConfigScope $scope, string $key, string $plugin, bool $member, array $default = []): bool
    {
        return $this->edit($scope, static function (array &$config) use ($key, $plugin, $member, $default): void {
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
     * Move an unreadable config out of the way, keeping its bytes.
     *
     * `<file>.invalid` rather than `.bak`, because `.bak` reads like a copy of
     * something that worked. A previous one is overwritten: the interesting file
     * is the one the user just lost, and keeping a chain of them would turn one
     * typo into a litter of dead files in a repository.
     *
     * Failure is ignored on purpose. This runs to make a destructive write less
     * destructive, and a rename that cannot happen must not also stop the
     * setting from being saved — that would strand the user for the sake of a
     * courtesy.
     */
    private function setAside(string $file): void
    {
        set_error_handler(static fn (): bool => true);
        rename($file, $file.'.invalid');
        restore_error_handler();
    }

    /**
     * The read-modify-write every persist* shares: load the scope's file
     * (missing or unparseable reads as empty), hand the decoded config to
     * $mutate by reference, write it back. Spelled once here rather than eight
     * times.
     *
     * False when the scope has no file at all — a global write in an
     * environment with neither PORTA_HOME nor HOME. Every caller already reads
     * false as "the write failed" and flashes accordingly, so nothing new has
     * to learn about the case.
     *
     * @param \Closure(array<array-key, mixed>): void $mutate
     */
    private function edit(ConfigScope $scope, \Closure $mutate): bool
    {
        $file = $this->files->path($scope);
        if (null === $file) {
            return false;
        }
        // A malformed file and an absent one both decode to null, and treating
        // them alike meant the next settings change started from an empty array
        // and wrote back a config holding only the key just toggled — theme,
        // language, plugin list, LSP config, per-language indents, all gone from
        // one click in Settings.
        //
        // Writing anyway is the RIGHT call and stays: refusing would strand the
        // user, because the Settings view offers no way to repair their JSON and
        // every change would fail until they found a text editor. What is wrong
        // is losing the file. It is moved aside first, so a one-character typo
        // costs a rename rather than everything the user had configured.
        //
        // The out-parameter is what tells the two cases apart: JsonFile leaves
        // it untouched for a file that is simply not there.
        $reason = null;
        $config = JsonFile::read($file, $reason);
        if (null === $config && null !== $reason) {
            $this->setAside($file);
        }
        $config ??= [];
        $mutate($config);
        // An edit that leaves nothing behind should not CONJURE a config file
        // to hold it. Resetting the only key a fresh project ever set, or
        // enabling a plugin that some other layer disables, both end here with
        // an empty array — and writing `[]` litters the repository with a file
        // the user never asked for and would then commit. Nothing to persist
        // is not a failure, so this reports success.
        if ([] === $config && !is_file($file)) {
            return true;
        }

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
