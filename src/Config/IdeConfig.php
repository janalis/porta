<?php

declare(strict_types=1);

namespace Porta\Config;

use Porta\Support\JsonFile;

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
        'lineNumbers' => 'absolute',
        'stickyScroll' => true,
        'indentGuides' => true,
        'breadcrumbs' => true,
        'autoPairs' => true,
        'formatOnSave' => false,
        'searchIncludeVendor' => false,
        'cursorShape' => 'bar',
        'tabBarPosition' => 'top',
    ];

    /**
     * Superseded setting keys: silently skipped at load, so a stale value in
     * an older .porta.json never warns. `editorConfig` was the "Respect
     * .editorconfig" toggle — .editorconfig is always honored now, superseded
     * per language by the indent settings.
     *
     * The eight `git*` keys are here because git became a bundled PLUGIN: they
     * live under `plugins.git.*` now, on that plugin's own settings page. The
     * plugin adopts whatever value the old key held, once, so silence here is
     * not "ignored" — it is "already read, by their new owner". The four
     * viewer-tool keys made the same move: `dotPath`/`graphRender`/`plantuml`
     * to `plugins.graph-viewer.*` (as dotPath/renderMode/plantumlPath) and
     * `svgConverter` to `plugins.image-viewer.svgConverter`.
     */
    public const array LEGACY_SETTINGS = [
        'editorConfig',
        'relativeLineNumbers',
        'gitShowBranch',
        'gitGutterMarks',
        'gitBlameDefault',
        'gitSignCommits',
        'gitNoVerify',
        'gitSignoff',
        'gitRefreshInterval',
        'gitPath',
        'dotPath',
        'graphRender',
        'svgConverter',
        'plantuml',
    ];

    /**
     * The stripped-on-write subset of {@see self::LEGACY_SETTINGS}
     * ({@see PortaJsonWriter::persistSetting}): only keys core itself
     * obsoleted. `editorConfig` carries no information any more and drops on
     * any write; a SUPERSEDED_BY key drops only when its successor is being
     * written, so the preference it still encodes is never destroyed before
     * the successor records it. The eight git* keys are deliberately in
     * neither list: their values belong to the git plugin's one-time adoption,
     * which may not have run yet (plugin disabled, project not reopened) —
     * stripping them on an unrelated write would destroy what it adopts.
     */
    public const array OBSOLETE_SETTINGS = ['editorConfig'];

    /** legacy key => the successor whose write retires it. */
    public const array SUPERSEDED_BY = ['relativeLineNumbers' => 'lineNumbers'];

    /**
     * Bundled plugins enabled when `.porta.json` has NO `enabledPlugins` key —
     * the viewers ship on so a fresh install opens images and graphs inline.
     * An explicit list (even an empty one) replaces this default entirely;
     * {@see PortaJsonWriter} materializes it before editing so a disable from
     * the default state persists "the default minus that name".
     */
    public const array DEFAULT_ENABLED_PLUGINS = ['image-viewer', 'graph-viewer', 'todo', 'local-history', 'git'];

    /**
     * The closed value sets of the choice settings, mirrored here as literals
     * because their owning enums live in namespaces this parser must not reach
     * (the container, and the plugins, do not exist yet). Each list is pinned
     * against its authority by IdeConfigTest, so drift fails a test instead of
     * silently accepting values the UI would never offer.
     *
     * @var array<string, list<string>>
     */
    public const array CHOICE_SETTINGS = [
        'language' => ['en', 'fr', 'de', 'es', 'it', 'ja', 'he'],
        'theme' => ['dark', 'light', 'high-contrast'],
        'lineNumbers' => ['off', 'absolute', 'relative'],
        'cursorShape' => ['bar', 'block', 'underline'],
        'tabBarPosition' => ['top', 'bottom', 'left', 'right'],
    ];

    /** An empty `highlight` block: no global default, no per-language overrides. */
    private const array NO_HIGHLIGHT = ['default' => '', 'languages' => []];

    /** The per-language indent override keys and their allowed values. */
    private const INDENT_STYLES = ['auto', 'spaces', 'tabs'];

    /**
     * @param array<string, list<string>>                                                                                                    $keymap
     * @param array<string, int|bool|string>                                                                                                 $settings   resolved settings (defaults merged)
     * @param array<string, array{indentStyle?: string, indentSize?: int|string, debug?: array{adapter?: string, launch?: array<string, mixed>}, runCommand?: string}> $languages  per-language overrides
     * @param array<string, string>                                                                                                          $lspServers per-language LSP command overrides (map form)
     * @param array<string, array<string, bool|int|string>>                                                                                  $plugins    raw per-plugin setting values (coerced at read by PluginSettings)
     * @param list<string>                                                                                                                   $disabledPlugins plugin names skipped at boot (project/global plugins are on unless listed here)
     * @param list<string>                                                                                                                   $enabledPlugins  bundled (embedded) plugin names to load — {@see DEFAULT_ENABLED_PLUGINS} unless the key is present (an explicit list, even empty, replaces the default)
     * @param array{default: string, languages: array<string, string>}                                                                      $highlight  the top-level `highlight` block: grammar name => backend id
     * @param list<ConfigWarning>                                                                                                            $warnings
     */
    private function __construct(
        public readonly array $keymap,
        public readonly array $settings,
        public readonly array $languages,
        public readonly array $lspServers,
        public readonly array $plugins,
        public readonly array $disabledPlugins,
        public readonly array $enabledPlugins,
        public readonly array $highlight,
        public readonly array $warnings,
    ) {}

    public static function load(string $rootDir): self
    {
        $file = $rootDir.'/.porta.json';
        if (!is_file($file)) {
            return new self([], self::DEFAULT_SETTINGS, [], [], [], [], self::DEFAULT_ENABLED_PLUGINS, self::NO_HIGHLIGHT, []);
        }

        $jsonError = null;
        $config = JsonFile::read($file, $jsonError);
        if (null === $config) {
            return new self([], self::DEFAULT_SETTINGS, [], [], [], [], self::DEFAULT_ENABLED_PLUGINS, self::NO_HIGHLIGHT, [
                null !== $jsonError
                    ? new ConfigWarning('.porta.json is not valid JSON (%error%)', ['%error%' => $jsonError])
                    : new ConfigWarning('.porta.json is not valid JSON'),
            ]);
        }

        $keymap = [];
        $warnings = [];
        /** @var array<string, string|int|list<string>> $keymapConfig */
        $keymapConfig = $config['keymap'] ?? [];
        foreach ($keymapConfig as $action => $keys) {
            // Only the SHAPE is judged here. Whether an id exists is a question
            // this class cannot answer: it parses .porta.json before the
            // container exists, let alone the plugins, and a plugin pane's
            // actions ("image_zoom_in") are as real as core's. KeybindingsFactory
            // asks that once the catalog is complete, and warns there.
            $keys = \is_string($keys) ? [$keys] : $keys;
            if (!\is_array($keys)) {
                $warnings[] = new ConfigWarning('Keymap action "%action%" must map to a key or list of keys', ['%action%' => $action]);
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
                $warnings[] = new ConfigWarning('Unknown setting "%key%" in .porta.json', ['%key%' => $key]);
                continue;
            }
            // Coerce to the default's type (bool, int, or string).
            $default = self::DEFAULT_SETTINGS[$key];
            $coerced = match (true) {
                \is_bool($default) => (bool) $value,
                \is_int($default) => (int) $value,
                default => (string) $value,
            };
            // A choice setting's value domain is closed: an unknown value
            // would otherwise flow into the env channel and resolve to
            // whatever fallback its consumer happens to apply, silently.
            $allowed = self::CHOICE_SETTINGS[$key] ?? null;
            if (null !== $allowed && null === FieldNode::enum($allowed, $coerced)) {
                $warnings[] = new ConfigWarning('Setting "%key%" must be one of %choices%, got "%value%"', [
                    '%key%' => $key,
                    '%choices%' => implode('|', $allowed),
                    '%value%' => (string) $coerced,
                ]);
                continue;
            }
            $settings[$key] = $coerced;
        }

        // Migrate the legacy `relativeLineNumbers` bool into the `lineNumbers`
        // mode (an explicit `lineNumbers` in the file wins).
        if (\array_key_exists('relativeLineNumbers', $settingsConfig) && !\array_key_exists('lineNumbers', $settingsConfig)) {
            $settings['lineNumbers'] = (bool) $settingsConfig['relativeLineNumbers'] ? 'relative' : 'absolute';
        }

        /** @var array<string, mixed> $languagesConfig */
        $languagesConfig = \is_array($config['languages'] ?? null) ? $config['languages'] : [];
        $languages = self::parseLanguages($languagesConfig, $warnings);
        $lspServers = self::parseLspServers($config['lsp'] ?? null);
        $plugins = self::parsePluginSettings(\is_array($config['plugins'] ?? null) ? $config['plugins'] : [], $warnings);
        $disabledPlugins = self::stringList($config['disabledPlugins'] ?? null);
        $enabledPlugins = \array_key_exists('enabledPlugins', $config)
            ? self::stringList($config['enabledPlugins'])
            : self::DEFAULT_ENABLED_PLUGINS;

        $highlight = self::parseHighlight($config['highlight'] ?? null, $warnings);

        return new self($keymap, $settings, $languages, $lspServers, $plugins, $disabledPlugins, $enabledPlugins, $highlight, $warnings);
    }

    /**
     * The top-level `highlight` block: which highlighting backend to use, both
     * globally and per language.
     *
     * ```json
     * "highlight": { "default": "tempest", "languages": { "rust": "chroma" } }
     * ```
     *
     * Keys are grammar names (what {@see \Porta\Support\LanguageMap} resolves a
     * path to), not LSP language ids — the two vocabularies differ, and several
     * highlightable grammars have no language server at all. Ids are validated
     * against loaded plugins later, by the registry, since plugins have not
     * loaded when the config is parsed.
     *
     * @param list<ConfigWarning> $warnings
     *
     * @return array{default: string, languages: array<string, string>}
     */
    private static function parseHighlight(mixed $highlight, array &$warnings): array
    {
        if (null === $highlight) {
            return self::NO_HIGHLIGHT;
        }
        if (!\is_array($highlight)) {
            $warnings[] = new ConfigWarning('"highlight" in .porta.json must be an object');

            return self::NO_HIGHLIGHT;
        }

        return [
            'default' => self::highlighterId($highlight['default'] ?? null) ?? '',
            'languages' => self::highlighterLanguages($highlight['languages'] ?? null, $warnings),
        ];
    }

    /** A highlighter id from a raw config value, or null when it is not a usable one. */
    private static function highlighterId(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * The per-grammar highlighter overrides from the block's `languages` map.
     *
     * @param list<ConfigWarning> $warnings
     *
     * @return array<string, string>
     */
    private static function highlighterLanguages(mixed $raw, array &$warnings): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $languages = [];
        foreach (array_keys($raw) as $language) {
            $id = self::highlighterId($raw[$language]);
            if (null === $id) {
                $warnings[] = new ConfigWarning('Highlighter for "%language%" in .porta.json must be a non-empty string', ['%language%' => (string) $language]);
                continue;
            }
            $languages[(string) $language] = $id;
        }

        return $languages;
    }

    /**
     * The raw per-plugin setting values from the top-level `plugins` block.
     * Values stay uncoerced — descriptors are only known once plugins load;
     * {@see \Porta\Plugin\Runtime\PluginSettings} coerces at read time.
     *
     * @param array<array-key, mixed> $config
     * @param list<ConfigWarning>     $warnings
     *
     * @return array<string, array<string, bool|int|string>>
     */
    private static function parsePluginSettings(array $config, array &$warnings): array
    {
        $plugins = [];
        /** @var mixed $values */
        foreach ($config as $plugin => $values) {
            if (!\is_array($values)) {
                $warnings[] = new ConfigWarning('Plugin settings for "%plugin%" must be an object', ['%plugin%' => (string) $plugin]);
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

    /**
     * A `.porta.json` value normalized to a list of strings (non-scalars dropped).
     *
     * @return list<string>
     */
    public static function stringList(mixed $value): array
    {
        return \is_array($value) ? array_values(array_map(strval(...), array_filter($value, is_scalar(...)))) : [];
    }

    /** A raw `lsp` value (string or argv list) as a single command string. */
    public static function commandString(mixed $command): string
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
     * @param list<ConfigWarning>     $warnings
     *
     * @return array<string, array{indentStyle?: string, indentSize?: int|string, debug?: array{adapter?: string, launch?: array<string, mixed>}, runCommand?: string}>
     */
    private static function parseLanguages(array $config, array &$warnings): array
    {
        $languages = [];
        foreach (array_keys($config) as $lang) {
            $lang = (string) $lang;
            if (!LanguageCatalog::isKnown($lang)) {
                $warnings[] = new ConfigWarning('Unknown language "%lang%" in .porta.json', ['%lang%' => $lang]);
                continue;
            }
            $entry = self::languageEntry($config[$lang]);
            if (null === $entry) {
                $warnings[] = new ConfigWarning('Language "%lang%" must map to an object of indent settings', ['%lang%' => $lang]);
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
     * @return array{indentStyle?: string, indentSize?: int|string, debug?: array{adapter?: string, launch?: array<string, mixed>}, runCommand?: string}|null
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
        $runCommand = self::commandString($props['runCommand'] ?? null);
        if ('' !== $runCommand) {
            $entry['runCommand'] = $runCommand;
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
        return \is_string($value) ? FieldNode::enum(self::INDENT_STYLES, $value) : null;
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
}
