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
final readonly class IdeConfig
{
    /**
     * Editor/UI settings with their defaults. The `settings` block of
     * .porta.json overrides these (unknown keys are warned about).
     *
     * @var array<string, int|bool|string>
     */
    public const array DEFAULT_SETTINGS = [
        'language' => 'auto',
        'theme' => 'dark',
        'diagnosticMessages' => 'none',
        'inlayHints' => 'inline',
        'inlayParameterHints' => 'literals',
        'inlayTypeHints' => true,
        'keymapPlatform' => 'auto',
        'lineNumbers' => 'absolute',
        'stickyScroll' => true,
        'indentGuides' => true,
        'methodSeparators' => false,
        'showWhitespace' => false,
        'softWrap' => false,
        'breadcrumbs' => true,
        'autoPairs' => true,
        'formatOnSave' => false,
        'searchIncludeVendor' => false,
        'cursorShape' => 'bar',
        'pixelRatio' => 'auto',
        'tabBarPosition' => 'top',
        'tooltips' => true,
        'tooltipDelay' => 500,
        'displayName' => '',
        'sharePresence' => true,
        'toolUpdateCheck' => 'daily',
        'pluginUpdateCheck' => 'daily',
        'pluginAutoUpdate' => false,
        'ideUpdateCheck' => 'daily',
        'ideAutoUpdate' => false,
    ];

    /**
     * Superseded setting keys: silently skipped at load, so a stale value in
     * an older .porta.json never warns. `editorConfig` was the "Respect
     * .editorconfig" toggle — .editorconfig is always honored now, superseded
     * per language by the indent settings. `markdownPreview` turned
     * Markdown's preview off for files opened after it; the Editor / Split /
     * Preview toggles over a previewed file say that per file now. Both carry
     * nothing any more. `relativeLineNumbers` and
     * `inlineDiagnostics` were booleans that grew into choices
     * (`lineNumbers`, `diagnosticMessages`): still read, and migrated.
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
        'markdownPreview',
        'relativeLineNumbers',
        'inlineDiagnostics',
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
     * obsoleted. `editorConfig` and `markdownPreview` carry no information any
     * more and drop on any write; a SUPERSEDED_BY key drops only when its successor is being
     * written, so the preference it still encodes is never destroyed before
     * the successor records it. The eight git* keys are deliberately in
     * neither list: their values belong to the git plugin's one-time adoption,
     * which may not have run yet (plugin disabled, project not reopened) —
     * stripping them on an unrelated write would destroy what it adopts.
     */
    public const array OBSOLETE_SETTINGS = ['editorConfig', 'markdownPreview'];

    /** legacy key => the successor whose write retires it. */
    public const array SUPERSEDED_BY = ['relativeLineNumbers' => 'lineNumbers', 'inlineDiagnostics' => 'diagnosticMessages'];

    /**
     * Bundled plugins enabled when `.porta.json` has NO `enabledPlugins` key —
     * the viewers ship on so a fresh install opens images and graphs inline.
     * An explicit list (even an empty one) replaces this default entirely;
     * {@see PortaJsonWriter} materializes it before editing so a disable from
     * the default state persists "the default minus that name".
     */
    public const array DEFAULT_ENABLED_PLUGINS = ['image-viewer', 'graph-viewer', 'todo', 'local-history', 'diff', 'git', 'ssh', 'ftp'];

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
        'language' => ['auto', 'en', 'fr', 'de', 'es', 'it', 'ja', 'he'],
        'theme' => ['dark', 'light', 'high-contrast'],
        'lineNumbers' => ['off', 'absolute', 'relative'],
        'diagnosticMessages' => ['none', 'inline', 'menu'],
        'inlayHints' => ['none', 'inline', 'tooltip'],
        'inlayParameterHints' => ['none', 'literals', 'all'],
        'keymapPlatform' => ['auto', 'macos', 'windows', 'linux'],
        'cursorShape' => ['bar', 'block', 'underline'],
        'pixelRatio' => ['auto', '1', '1.25', '1.5', '2', '3'],
        'tabBarPosition' => ['top', 'bottom', 'left', 'right'],
        'toolUpdateCheck' => ['off', 'daily', 'weekly'],
        'pluginUpdateCheck' => ['off', 'daily', 'weekly'],
        'ideUpdateCheck' => ['off', 'daily', 'weekly'],
    ];

    /** An empty `highlight` block: no global default, no per-language overrides. */
    private const array NO_HIGHLIGHT = ['default' => '', 'languages' => []];

    /** The per-language indent override keys and their allowed values. */

    /**
     * @param array<string, list<string>>                                                                                                    $keymap
     * @param array<string, int|bool|string>                                                                                                 $settings   resolved settings (defaults merged)
     * @param array<string, array{indentStyle?: string, indentSize?: int|string, debug?: array{adapter?: string, launch?: array<string, mixed>}, runCommand?: string, templates?: array<string, string>}> $languages  per-language overrides
     * @param array<string, string>                                                                                                          $lspServers per-language LSP command overrides (map form)
     * @param array<string, array<string, bool|int|string>>                                                                                  $plugins    raw per-plugin setting values (coerced at read by PluginSettings)
     * @param list<string>                                                                                                                   $disabledPlugins plugin names skipped at boot (project/global plugins are on unless listed here)
     * @param list<string>                                                                                                                   $enabledPlugins  bundled (embedded) plugin names to load — {@see DEFAULT_ENABLED_PLUGINS} unless the key is present (an explicit list, even empty, replaces the default)
     * @param array{default: string, languages: array<string, string>}                                                                      $highlight  the top-level `highlight` block: grammar name => backend id
     * @param list<ConfigWarning>                                                                                                            $warnings
     * @param bool                                                                                                                           $trusted    whether the project's own say was believed — see {@see WorkspaceTrust}
     */
    private function __construct(
        public array $keymap,
        public array $settings,
        public array $languages,
        public array $lspServers,
        public array $plugins,
        public array $disabledPlugins,
        public array $enabledPlugins,
        public array $highlight,
        public array $warnings,
        public bool $trusted = true,
    ) {}

    /**
     * The effective config: the global file under the project file.
     *
     * Merged HERE, at the bottom, and that placement is the whole design. The
     * container's cache key is VERSION + PHP_VERSION + path + cacheDir +
     * installedPackages, and config reaches services through separate $_ENV
     * JSON channels — so a `load()` that returns a merged config with unchanged
     * property shapes leaves IdeContainerFactory, every env channel, every
     * #[Autowire] consumer and LiveKeybindings untouched, and
     * LiveKeybindings::rebuild() keeps the global layer for free because it
     * re-reads through here. Merging any later pays for all of that and buys
     * nothing.
     *
     * $plugins — `porta --plugin` — are bundled plugins turned on for this
     * launch, on top of what the layers enable. The command line is the
     * user's, not the project's, so a restricted project keeps them: it is how
     * `porta assist` opens a host's project restricted with assist on.
     *
     * @param list<string> $plugins
     */
    public static function load(string $rootDir, array $plugins = []): self
    {
        $files = ConfigFiles::for($rootDir);
        $trusted = WorkspaceTrust::fromEnvironment()->isTrusted($rootDir);
        $warnings = [];
        $layers = [];
        foreach (ConfigScope::inPrecedenceOrder() as $scope) {
            // An untrusted project's file is read minus what names something to run.
            $restricted = ConfigScope::Project === $scope && !$trusted;
            $layer = self::readLayer($files->path($scope), $files->label($scope), $warnings, $restricted);
            if (null !== $layer) {
                $layers[] = $layer;
            }
        }

        return self::merge($layers, $warnings, $trusted, $plugins);
    }

    /**
     * One file, parsed into only what it actually said — no defaults.
     *
     * That is what makes the merge decidable: "global set lineNumbers to
     * absolute" and "global did not mention lineNumbers" are the same array
     * once DEFAULT_SETTINGS has been folded in, and they must not be. Defaults
     * are applied once, in {@see merge()}, after the layers have been resolved.
     *
     * Null when the file is absent or unreadable as JSON. Warnings carry
     * $label, so a mistake in the global file says so instead of blaming the
     * project's.
     *
     * @param list<ConfigWarning> $warnings
     *
     * @return array{keymap: array<string, list<string>>, settings: array<string, bool|int|string>, languages: array<string, array{indentStyle?: string, indentSize?: int|string, debug?: array{adapter?: string, launch?: array<string, mixed>}, runCommand?: string, templates?: array<string, string>}>, lsp: array<string, string>, plugins: array<string, array<string, bool|int|string>>, disabledPlugins: list<string>, enabledPlugins: ?list<string>, highlight: ?array{default: string, languages: array<string, string>}}|null
     */
    // @mago-expect lint:no-boolean-flag-parameter -- restricted IS the one fact the trust decision hands the reader
    private static function readLayer(?string $file, string $label, array &$warnings, bool $restricted): ?array
    {
        $config = null === $file || !\is_file($file) ? null : self::readLayerJson($file, $label, $warnings);
        if (null === $config) {
            return null;
        }
        if ($restricted) {
            $config = WorkspaceTrust::restricted($config);
        }

        /** @var array<string, string|int|list<string>> $keymapConfig */
        $keymapConfig = $config['keymap'] ?? [];
        /** @var array<string, scalar> $settingsConfig */
        $settingsConfig = $config['settings'] ?? [];
        /** @var array<string, mixed> $languagesConfig */
        $languagesConfig = \is_array($config['languages'] ?? null) ? $config['languages'] : [];

        return [
            'keymap' => self::parseKeymap($keymapConfig, $label, $warnings),
            'settings' => self::parseSettings($settingsConfig, $label, $warnings),
            'languages' => self::parseLanguages($languagesConfig, $label, $warnings),
            'lsp' => self::parseLspServers($config['lsp'] ?? null),
            'plugins' => self::parsePluginSettings(\is_array($config['plugins'] ?? null) ? $config['plugins'] : [], $warnings),
            'disabledPlugins' => self::stringList($config['disabledPlugins'] ?? null),
            // Nullable on purpose — see the enabledPlugins clause in merge().
            'enabledPlugins' => \array_key_exists('enabledPlugins', $config) ? self::stringList($config['enabledPlugins']) : null,
            'highlight' => \array_key_exists('highlight', $config) ? self::parseHighlight($config['highlight'], $label, $warnings) : null,
        ];
    }

    /**
     * The layer file decoded, or null with a warning when it is not valid JSON.
     *
     * @param list<ConfigWarning> $warnings
     *
     * @return array<mixed>|null
     */
    private static function readLayerJson(string $file, string $label, array &$warnings): ?array
    {
        $jsonError = null;
        $config = JsonFile::read($file, $jsonError);
        if (null === $config) {
            // Always with a reason. JsonFile::read omits one only for a missing
            // file, and this one is not missing — the is_file in readLayer is what
            // makes the reasonless variant unreachable, which is why it no
            // longer exists.
            $warnings[] = new ConfigWarning('%file% is not valid JSON (%error%)', ['%file%' => $label, '%error%' => (string) $jsonError]);
        }

        return $config;
    }

    /**
     * Fold the layers together, lowest precedence first, and apply the defaults.
     *
     * Each block is merged at the finest granularity that keeps its meaning,
     * which is not the same granularity for all of them:
     *
     * - `settings`, `plugins`, `lsp`, `highlight.languages` — per key. Setting
     *   your theme globally and your tab width per project is the ordinary case.
     * - `keymap` — per ACTION, never per chord list. Merging two lists of keys
     *   for one action produces a binding nobody asked for.
     * - `languages` — per language, then per field, except `debug.launch`,
     *   which replaces whole. A half-merged DAP launch config has never been a
     *   thing that runs.
     * - `disabledPlugins` — UNION. Both layers are saying "not this one", and
     *   an override that silently re-enables a plugin the user turned off
     *   globally is the opposite of what either layer asked for.
     * - `enabledPlugins` — the nearest PRESENT layer replaces, and an empty
     *   list counts as present. See the clause below; this is the one rule that
     *   cannot be a union.
     *
     * @param list<array{keymap: array<string, list<string>>, settings: array<string, bool|int|string>, languages: array<string, array{indentStyle?: string, indentSize?: int|string, debug?: array{adapter?: string, launch?: array<string, mixed>}, runCommand?: string, templates?: array<string, string>}>, lsp: array<string, string>, plugins: array<string, array<string, bool|int|string>>, disabledPlugins: list<string>, enabledPlugins: ?list<string>, highlight: ?array{default: string, languages: array<string, string>}}> $layers
     * @param list<ConfigWarning> $warnings
     * @param list<string>        $requested bundled plugins this launch turns on besides — `--plugin`
     */
    private static function merge(array $layers, array $warnings, bool $trusted, array $requested): self
    {
        $keymap = [];
        $settings = self::DEFAULT_SETTINGS;
        $languages = [];
        $lsp = [];
        $plugins = [];
        $disabled = [];
        $enabled = null;
        $highlight = null;

        foreach ($layers as $layer) {
            $keymap = [...$keymap, ...$layer['keymap']];
            $settings = [...$settings, ...$layer['settings']];
            $languages = self::mergeLanguages($languages, $layer['languages']);
            $lsp = [...$lsp, ...$layer['lsp']];
            foreach ($layer['plugins'] as $plugin => $values) {
                $plugins[$plugin] = [...($plugins[$plugin] ?? []), ...$values];
            }
            $disabled = [...$disabled, ...$layer['disabledPlugins']];
            // NOT a union, and this is the sentence the whole scope feature
            // rests on: `enabledPlugins` is an explicit list that REPLACES,
            // even when empty — that invariant is why persistBundled*
            // materialises the effective list before writing. Unioned, a
            // project could never turn off what global turned on, because
            // there would be no way to express the absence.
            $enabled = $layer['enabledPlugins'] ?? $enabled;
            $highlight = null === $layer['highlight']
                ? $highlight
                : [
                    'default' => '' !== $layer['highlight']['default'] ? $layer['highlight']['default'] : $highlight['default'] ?? '',
                    'languages' => [...($highlight['languages'] ?? []), ...$layer['highlight']['languages']],
                ];
        }

        return new self(
            $keymap,
            $settings,
            $languages,
            $lsp,
            $plugins,
            \array_values(\array_unique($disabled)),
            \array_values(\array_unique([...($enabled ?? self::DEFAULT_ENABLED_PLUGINS), ...$requested])),
            $highlight ?? self::NO_HIGHLIGHT,
            $warnings,
            $trusted,
        );
    }

    /**
     * Per language, then per field — except `debug`, whose `launch` object
     * replaces whole rather than merging key by key. A launch configuration is
     * one thing an adapter either understands or does not; half of a project's
     * and half of the global one is a config nobody wrote.
     *
     * @param array<string, array{indentStyle?: string, indentSize?: int|string, debug?: array{adapter?: string, launch?: array<string, mixed>}, runCommand?: string, templates?: array<string, string>}> $base
     * @param array<string, array{indentStyle?: string, indentSize?: int|string, debug?: array{adapter?: string, launch?: array<string, mixed>}, runCommand?: string, templates?: array<string, string>}> $over
     *
     * @return array<string, array{indentStyle?: string, indentSize?: int|string, debug?: array{adapter?: string, launch?: array<string, mixed>}, runCommand?: string, templates?: array<string, string>}>
     */
    private static function mergeLanguages(array $base, array $over): array
    {
        foreach ($over as $language => $fields) {
            $under = $base[$language] ?? [];
            $merged = [...$under, ...$fields];
            if (\array_key_exists('debug', $under) && \array_key_exists('debug', $fields)) {
                $merged['debug'] = [...$under['debug'], ...$fields['debug']];
            }
            // Templates merge per ABBREVIATION: adding one project template must
            // not drop the set defined globally.
            if (\array_key_exists('templates', $under) && \array_key_exists('templates', $fields)) {
                $merged['templates'] = [...$under['templates'], ...$fields['templates']];
            }
            $base[$language] = $merged;
        }

        return $base;
    }

    /**
     * The `keymap` block: action id => list of key chords.
     *
     * Only the SHAPE is judged here. Whether an id exists is a question this
     * class cannot answer: it parses config before the container exists, let
     * alone the plugins, and a plugin pane's actions ("image_zoom_in") are as
     * real as core's. KeybindingsFactory asks that once the catalog is
     * complete, and warns there.
     *
     * @param array<string, string|int|list<string>> $config
     * @param list<ConfigWarning>                    $warnings
     *
     * @return array<string, list<string>>
     */
    private static function parseKeymap(array $config, string $label, array &$warnings): array
    {
        $keymap = [];
        foreach ($config as $action => $keys) {
            $keys = \is_string($keys) ? [$keys] : $keys;
            if (!\is_array($keys)) {
                $warnings[] = new ConfigWarning('Keymap action "%action%" in %file% must map to a key or list of keys', ['%action%' => $action, '%file%' => $label]);
                continue;
            }
            // `cmd+s` is what a Mac user writes; the parser's name for it is `super`.
            // @noinspection TypesCastingCanBeUsedInspection -- a key may be an int; mago calls a (string) cast here redundant, strval() it reads right
            $keymap[$action] = \array_values(\array_map(
                static fn (mixed $key): string => (string) \preg_replace(pattern: '/(^|\+)cmd(?=\+)/i', replacement: '$1super', subject: \strval($key)),
                $keys,
            ));
        }

        return $keymap;
    }

    /**
     * The `settings` block: only the keys this file actually set, coerced to
     * each default's type and checked against any closed value domain.
     *
     * @param array<string, scalar> $config
     * @param list<ConfigWarning>   $warnings
     *
     * @return array<string, bool|int|string>
     */
    private static function parseSettings(array $config, string $label, array &$warnings): array
    {
        $settings = [];
        foreach ($config as $key => $value) {
            if (\in_array($key, self::LEGACY_SETTINGS, strict: true)) {
                continue;
            }
            if (!\array_key_exists($key, self::DEFAULT_SETTINGS)) {
                $warnings[] = new ConfigWarning('Unknown setting "%key%" in %file%', ['%key%' => $key, '%file%' => $label]);
                continue;
            }
            // Coerce to the default's type (bool, int, or string).
            $default = self::DEFAULT_SETTINGS[$key];
            $coerced = match (true) {
                \is_bool($default) => (bool) $value,
                \is_int($default) => (int) $value,
                default => (string) $value,
            };
            // A choice setting's value domain is closed: an unknown value would
            // otherwise flow into the env channel and resolve to whatever
            // fallback its consumer happens to apply, silently.
            $allowed = self::CHOICE_SETTINGS[$key] ?? null;
            if (null !== $allowed && null === FieldNode::enum($allowed, $coerced)) {
                $warnings[] = new ConfigWarning('Setting "%key%" in %file% must be one of %choices%, got "%value%"', [
                    '%key%' => $key,
                    '%file%' => $label,
                    '%choices%' => \implode('|', $allowed),
                    '%value%' => (string) $coerced,
                ]);
                continue;
            }
            $settings[$key] = $coerced;
        }

        // Migrate the legacy `relativeLineNumbers` bool into the `lineNumbers`
        // mode (an explicit `lineNumbers` in the same file wins).
        if (\array_key_exists('relativeLineNumbers', $config) && !\array_key_exists('lineNumbers', $config)) {
            $settings['lineNumbers'] = (bool) $config['relativeLineNumbers'] ? 'relative' : 'absolute';
        }
        // And the legacy `inlineDiagnostics` bool into `diagnosticMessages`:
        // on was the end-of-line message, off was no message at all. On stays
        // an explicit ask for the message now that none is the default.
        if (\array_key_exists('inlineDiagnostics', $config) && !\array_key_exists('diagnosticMessages', $config)) {
            $settings['diagnosticMessages'] = (bool) $config['inlineDiagnostics'] ? 'inline' : 'none';
        }

        return $settings;
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
    private static function parseHighlight(mixed $highlight, string $label, array &$warnings): array
    {
        if (!\is_array($highlight)) {
            $warnings[] = new ConfigWarning('"highlight" in %file% must be an object', ['%file%' => $label]);

            return self::NO_HIGHLIGHT;
        }

        return [
            'default' => self::highlighterId($highlight['default'] ?? null) ?? '',
            'languages' => self::highlighterLanguages($highlight['languages'] ?? null, $label, $warnings),
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
    private static function highlighterLanguages(mixed $raw, string $label, array &$warnings): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $languages = [];
        foreach (\array_keys($raw) as $language) {
            $id = self::highlighterId($raw[$language]);
            if (null === $id) {
                $warnings[] = new ConfigWarning('Highlighter for "%language%" in %file% must be a non-empty string', ['%language%' => (string) $language, '%file%' => $label]);
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
        if (!\is_array($lsp) || [] === $lsp || \array_is_list($lsp)) {
            return [];
        }

        $servers = [];
        foreach (\array_keys($lsp) as $language) {
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
        return \is_array($value) ? \array_values(\array_map(\strval(...), \array_filter($value, \is_scalar(...)))) : [];
    }

    /** A raw `lsp` value (string or argv list) as a single command string. */
    public static function commandString(mixed $command): string
    {
        if (\is_array($command)) {
            return \implode(' ', \array_map(\strval(...), $command));
        }

        return \is_string($command) ? $command : '';
    }

    /**
     * A language's `templates` block: abbreviation => LSP snippet body. Both
     * halves must be strings; anything else is dropped like an invalid indent
     * value is.
     *
     * An EMPTY body is kept, not dropped — it is how a user switches a built-in
     * template off, and dropping it here would silently ignore that.
     *
     * @return array<string, string>
     */
    private static function templateMap(mixed $templates): array
    {
        if (!\is_array($templates)) {
            return [];
        }
        $map = [];
        foreach (\array_keys($templates) as $abbreviation) {
            if ('' === (string) $abbreviation || !\is_string($templates[$abbreviation])) {
                continue;
            }
            $map[(string) $abbreviation] = $templates[$abbreviation];
        }

        return $map;
    }

    /**
     * Validate the `languages` block: known language ids, `indentStyle` in
     * {auto,spaces,tabs}, `indentSize` = 'auto' or a positive int. Invalid
     * entries are dropped with a warning.
     *
     * @param array<array-key, mixed> $config
     * @param list<ConfigWarning>     $warnings
     *
     * @return array<string, array{indentStyle?: string, indentSize?: int|string, debug?: array{adapter?: string, launch?: array<string, mixed>}, runCommand?: string, templates?: array<string, string>}>
     */
    private static function parseLanguages(array $config, string $label, array &$warnings): array
    {
        $languages = [];
        foreach (\array_keys($config) as $lang) {
            $lang = (string) $lang;
            if (!LanguageCatalog::isKnown($lang)) {
                $warnings[] = new ConfigWarning('Unknown language "%lang%" in %file%', ['%lang%' => $lang, '%file%' => $label]);
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
     * @return array{indentStyle?: string, indentSize?: int|string, debug?: array{adapter?: string, launch?: array<string, mixed>}, runCommand?: string, templates?: array<string, string>}|null
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
        $templates = self::templateMap($props['templates'] ?? null);
        if ([] !== $templates) {
            $entry['templates'] = $templates;
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
        if (\is_array($launch) && !\array_is_list($launch)) {
            /** @var array<string, mixed> $launch */
            $entry['launch'] = $launch;
        }

        return $entry;
    }

    private static function indentStyleValue(mixed $value): ?string
    {
        return \is_string($value) ? FieldNode::enum(IndentStyle::values(), $value) : null;
    }

    private static function indentSizeValue(mixed $value): int|string|null
    {
        return match (true) {
            'auto' === $value => 'auto',
            \is_int($value) && $value >= 1 => $value,
            \is_string($value) && 1 === \preg_match('/^\d+$/', $value) => \max(1, (int) $value),
            default => null,
        };
    }
}
