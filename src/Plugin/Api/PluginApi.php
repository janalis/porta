<?php

declare(strict_types=1);

namespace Porta\Plugin\Api;

use Porta\Config\ActionCatalog;
use Porta\Editor\OpenFocus;
use Porta\Highlight\DarkTerminalTheme;
use Porta\Lsp\Uri;
use Porta\Plugin\Runtime\PluginEvents;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginMenuSpec;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Settings\SettingsRowKind;
use Porta\Workbench\Dock\ToolRun;
use Tempest\Highlight\TerminalTheme;

/**
 * A plugin's handle into Porta. Registration methods buffer during
 * `activate()` and land atomically when it returns (a throwing plugin
 * contributes nothing). Action methods reach the live IDE through the
 * manager's runtime and are meant for command handlers / event hooks —
 * calling them during `activate()` throws a {@see PluginException}.
 */
final class PluginApi
{
    private const string ID_PATTERN = '/^[a-z0-9][a-z0-9_-]*$/i';
    private const array SETTING_KINDS = ['bool', 'choice', 'text', 'int'];

    /**
     * Plain array, not a {@see PluginBuffer}: paired with the $ids dedup index below.
     *
     * @var list<array{plugin: string, id: string, label: string, handler: \Closure(): void, keys: list<string>}>
     */
    private array $commands = [];
    /** @var array<string, true> registered short ids, for duplicate detection */
    private array $ids = [];
    /**
     * Plain array: the drain transforms rows into {@see PluginMenuSpec}s.
     *
     * @var list<array{id: string, when: (\Closure(): bool)|null}> namespaced command ids shown in the Plugins menu
     */
    private array $menuItems = [];
    /**
     * Plain array: four writer methods, positional tuples, drains into PluginEvents.
     *
     * @var list<array{0: string, 1: \Closure(string): void}> [event, listener]
     */
    private array $hooks = [];
    /**
     * Plain array: keyed by setting key for dedup, drains into PluginSettings.
     *
     * @var array<string, array{key: string, label: string, kind: string, default: bool|int|string, options: list<string>|null, hint: string|null}> by key
     */
    private array $settingDescriptors = [];
    /** @var PluginBuffer<array{plugin: string, languageId: string, name: string, resolve: \Closure(): (list<string>|null)}> */
    private readonly PluginBuffer $languageServers;
    /** @var PluginBuffer<array{plugin: string, tool: string, available: \Closure(): bool, via: list<array{0: string, 1: string}>}> */
    private readonly PluginBuffer $installers;
    /** @var PluginBuffer<array{plugin: string, id: string, label: string, languages: \Closure(): list<string>, highlight: \Closure(string, string, \Closure(string): void): void, available: (\Closure(): bool)|null}> */
    private readonly PluginBuffer $highlighters;
    /** @var PluginBuffer<array{plugin: string, languages: \Closure(): list<string>, classify: \Closure(string, string): (list<array{start: int, end: int}>|null)}> */
    private readonly PluginBuffer $tokenSpans;
    /** @var PluginBuffer<array{plugin: string, id: string, claims: \Closure(string): bool}> */
    private readonly PluginBuffer $viewers;
    /** @var PluginBuffer<array{plugin: string, id: string, pane: \Closure, load: \Closure}> */
    private readonly PluginBuffer $panes;
    /** @var PluginBuffer<array{plugin: string, id: string, label: string}> */
    private readonly PluginBuffer $actions;
    /** @var PluginBuffer<array{plugin: string, id: string, title: string, rerun: (\Closure(): void)|null, activate: (\Closure(string, int): void)|null}> */
    private readonly PluginBuffer $listTools;
    /** @var array<string, true> bare ids, so showList() can reject one this plugin never registered */
    private array $listToolIds = [];

    public function __construct(
        private readonly string $pluginName,
        private readonly PluginManager $manager,
        private readonly string $rootDir,
        private readonly PluginSettings $settings,
        private readonly ?TerminalTheme $syntaxTheme = null,
    ) {
        $this->languageServers = new PluginBuffer();
        $this->installers = new PluginBuffer();
        $this->highlighters = new PluginBuffer();
        $this->tokenSpans = new PluginBuffer();
        $this->viewers = new PluginBuffer();
        $this->panes = new PluginBuffer();
        $this->actions = new PluginBuffer();
        $this->listTools = new PluginBuffer();
    }

    /**
     * The live syntax color scheme, for a backend that renders its own colours.
     *
     * A **holder**, not a snapshot: the settings layer swaps its inner theme in
     * place, so a backend that asks it per token automatically follows a theme
     * switch with no hook to subscribe to. Safe during activate() — the holder
     * is built before plugins load.
     */
    public function syntaxTheme(): TerminalTheme
    {
        return $this->syntaxTheme ?? new DarkTerminalTheme();
    }

    /**
     * The open project's root directory — static config, safe during
     * activate() (e.g. to locate `vendor/bin/...` tools).
     */
    public function projectRoot(): string
    {
        return $this->rootDir;
    }

    // --- Registration (buffered until the loader commits) --------------------

    /**
     * Register a palette command, optionally with default keybindings
     * (specs like 'ctrl+alt+y'; rebindable via .porta.json under
     * "<plugin>:<id>"). The handler runs wrapped — a throw flashes instead of
     * crashing the IDE.
     *
     * @param \Closure(): void $handler
     * @param list<string>     $keys
     */
    public function registerCommand(string $id, string $label, \Closure $handler, array $keys = []): void
    {
        if (1 !== preg_match(self::ID_PATTERN, $id)) {
            throw new PluginException(\sprintf('Invalid command id "%s" (expected /^[a-z0-9][a-z0-9_-]*$/i)', $id));
        }
        if (array_key_exists($id, $this->ids)) {
            throw new PluginException(\sprintf('Duplicate command id "%s"', $id));
        }
        $this->ids[$id] = true;
        $this->commands[] = [
            'plugin' => $this->pluginName,
            'id' => $this->pluginName.':'.$id,
            'label' => $label,
            'handler' => $handler,
            'keys' => array_values($keys),
        ];
    }

    /**
     * Show a registered command in the Plugins menu (its label is reused).
     * With $enabledWhen, the item renders disabled while the closure returns
     * false (e.g. the tool's binary is missing) — it is re-evaluated on every
     * menu render, and a throw reads as disabled.
     *
     * @param (\Closure(): bool)|null $enabledWhen
     */
    public function addMenuItem(string $commandId, ?\Closure $enabledWhen = null): void
    {
        if (!array_key_exists($commandId, $this->ids)) {
            throw new PluginException(\sprintf('Unknown command id "%s" for a menu item — register it first', $commandId));
        }
        $this->menuItems[] = ['id' => $this->pluginName.':'.$commandId, 'when' => $enabledWhen];
    }

    /**
     * Declare a user-editable setting, shown on this plugin's Settings page
     * and persisted in `.porta.json` under `plugins.<name>.<key>`. Call it
     * before {@see setting()} — the descriptor supplies the default and the
     * type coercion. Kinds: 'text', 'bool', 'int', 'choice' (with $options).
     *
     * @param list<string>|null $options required for 'choice'; must contain the default
     */
    public function registerSetting(string $key, string $label, string $kind = 'text', bool|int|string $default = '', ?array $options = null, ?string $hint = null): void
    {
        if (1 !== preg_match(self::ID_PATTERN, $key)) {
            throw new PluginException(\sprintf('Invalid setting key "%s" (expected /^[a-z0-9][a-z0-9_-]*$/i)', $key));
        }
        if (array_key_exists($key, $this->settingDescriptors)) {
            throw new PluginException(\sprintf('Duplicate setting key "%s"', $key));
        }
        if (!\in_array($kind, self::SETTING_KINDS, strict: true)) {
            throw new PluginException(\sprintf('Unknown setting kind "%s" (expected bool, choice, text or int)', $kind));
        }
        if ('choice' === $kind && (null === $options || [] === $options || !\in_array($default, $options, strict: true))) {
            throw new PluginException(\sprintf('Setting "%s": a choice needs options containing its default', $key));
        }
        $this->settingDescriptors[$key] = [
            'key' => $key,
            'label' => $label,
            'kind' => $kind,
            'default' => $default,
            'options' => $options,
            'hint' => $hint,
        ];
    }

    /**
     * Provide a language server for $languageId (e.g. 'php'). $resolveCommand
     * returns the launch argv, or null when the server binary is not available
     * — so a missing binary leaves the language unserved (and the "install"
     * hint shows) rather than launching a broken process. The resolver runs
     * lazily whenever the LSP layer (re)configures, so it can read the current
     * `binary` setting; keep it side-effect-free (settings + discovery only).
     * Plugin servers sit below the user's `.porta.json`/`$PORTA_LSP`/`--lsp`
     * config in precedence; when several enabled plugins serve one language the
     * first available (in load order) wins.
     *
     * @param \Closure(): (list<string>|null) $resolveCommand
     */
    public function registerLanguageServer(string $languageId, string $name, \Closure $resolveCommand): void
    {
        if (1 !== preg_match(self::ID_PATTERN, $languageId)) {
            throw new PluginException(\sprintf('Invalid language id "%s" (expected /^[a-z0-9][a-z0-9_-]*$/i)', $languageId));
        }
        $this->languageServers->add([
            'plugin' => $this->pluginName,
            'languageId' => $languageId,
            'name' => $name,
            'resolve' => $resolveCommand,
        ]);
    }

    /**
     * Provide a syntax-highlighting backend for one or more languages (Porta's
     * grammar names — see {@see \Porta\Support\LanguageMap}). `['*']` claims
     * every language. Pass a closure for $languages when the claim list follows
     * this plugin's own settings; it is re-resolved on every query, like
     * {@see registerLanguageServer()}'s command resolver.
     *
     * $highlight receives the whole document text, the language, and a $deliver
     * callback it hands the ANSI-marked result to. A **synchronous** backend
     * calls $deliver before returning. An **asynchronous** one returns
     * immediately and calls $deliver later from the event loop — Porta paints
     * with what it already has and repaints when the result lands. Delivering
     * for a superseded version is a silent no-op, so a backend never has to
     * track whether its answer is still wanted.
     *
     * Two hard rules: it **must not block** (it runs on the way to a render
     * frame — once per document version, not once per frame, but that is still
     * the typing path), and it must **preserve line structure** — same number
     * of `"\n"`-separated lines in as out, identical to the input once ANSI
     * codes are stripped. A throw, or a mangled line count, falls back to plain
     * text rather than rendering a broken buffer; three consecutive faults drop
     * the backend for that buffer.
     *
     * $isAvailable declines the language when e.g. a binary is missing, so it
     * falls through to the next backend instead of failing repeatedly.
     *
     * Precedence: the user's `.porta.json` `highlight` block wins, then the
     * first available claimer in plugin load order, then the built-in engine.
     *
     * @param list<string>|\Closure(): list<string>  $languages
     * @param \Closure(string, string, \Closure(string): void): void $highlight (content, language, deliver)
     * @param (\Closure(): bool)|null                $isAvailable
     * @param string|null                            $id          selection key in config; defaults to the plugin name
     */
    public function registerHighlighter(
        string $label,
        array|\Closure $languages,
        \Closure $highlight,
        ?\Closure $isAvailable = null,
        ?string $id = null,
    ): void {
        $id ??= $this->pluginName;
        if (1 !== preg_match(self::ID_PATTERN, $id)) {
            throw new PluginException(\sprintf('Invalid highlighter id "%s" (expected /^[a-z0-9][a-z0-9_-]*$/i)', $id));
        }
        if (\is_array($languages) && [] === $languages) {
            throw new PluginException('A highlighter must claim at least one language (or "*")');
        }
        $resolve = $languages instanceof \Closure ? $languages : static fn (): array => array_values($languages);
        $this->highlighters->add([
            'plugin' => $this->pluginName,
            'id' => $id,
            'label' => $label,
            'languages' => $resolve,
            'highlight' => $highlight,
            'available' => $isAvailable,
        ]);
    }

    /**
     * Claim file paths for a viewer pane: files matching $claims open in the
     * viewer named by $id instead of the text editor. Today the id names a
     * BUILT-IN pane ('image', 'graph' — the bundled image-viewer/graph-viewer
     * plugins are what makes those viewers enable/disableable); an id no pane
     * answers to falls through to the text path, so a future
     * widget-registration API can widen this without breaking the contract.
     *
     * $claims is a list of file extensions (matched case-insensitively) or a
     * `\Closure(string $path): bool` for richer predicates; a throwing
     * predicate declines the path (reported once).
     *
     * @param list<string>|\Closure(string): bool $claims
     */
    public function registerViewer(string $id, array|\Closure $claims): void
    {
        if (1 !== preg_match(self::ID_PATTERN, $id)) {
            throw new PluginException(\sprintf('Invalid viewer id "%s" (expected /^[a-z0-9][a-z0-9_-]*$/i)', $id));
        }
        if (\is_array($claims) && [] === $claims) {
            throw new PluginException('A viewer must claim at least one file extension');
        }
        if (\is_array($claims)) {
            /** @var list<string> $list */
            $list = array_values($claims);
            $extensions = array_map(static fn (string $extension): string => strtolower($extension), $list);
            $claims = static fn (string $path): bool => \in_array(strtolower(pathinfo($path, \PATHINFO_EXTENSION)), $extensions, strict: true);
        }
        $this->viewers->add([
            'plugin' => $this->pluginName,
            'id' => $id,
            'claims' => $claims,
        ]);
    }

    /**
     * Supply the PANE a viewer id names: the widget that takes the editor area
     * for files {@see registerViewer()} claimed, and the loader that turns one of
     * those paths into whatever payload the pane understands.
     *
     * The two calls are separate because they answer different questions —
     * "does this file belong to me?" and "what shows it?" — and a plugin may
     * still claim an id some other plugin's pane answers to. An id with a claim
     * and no pane falls through to the text path, unchanged from before panes
     * existed.
     *
     * $pane is called ONCE, at the constructor tail, with the
     * {@see PaneServices} core assembles; a plugin class cannot be a container
     * service, so this is how a pane receives its collaborators. $load runs on
     * every open of a claimed path — a throw declines the file, which is how a
     * pane refuses an oversized or malformed document.
     *
     * @param \Closure $pane  `\Closure(\Porta\Editor\PaneServices): \Porta\Widget\EditorPaneWidget` — untyped here so the
     *                        plugin tier never names a widget class (the `Plugin > Widget` edge round 18 severed)
     * @param \Closure $load  `\Closure(string $path): mixed`
     */
    public function registerPane(string $id, \Closure $pane, \Closure $load): void
    {
        if (1 !== preg_match(self::ID_PATTERN, $id)) {
            throw new PluginException(\sprintf('Invalid pane id "%s" (expected /^[a-z0-9][a-z0-9_-]*$/i)', $id));
        }
        $this->panes->add([
            'plugin' => $this->pluginName,
            'id' => $id,
            'pane' => $pane,
            'load' => $load,
        ]);
    }

    /**
     * Declare a LIST TOOL: a bottom-dock tab of results with jump-to-location,
     * the shape core's Problems and Usages tabs have. Registration only
     * declares it — nothing appears until {@see showList()} publishes rows.
     *
     * A row is `['path' => …, 'line' => …, 'text' => …]` with the path
     * project-relative (an absolute one inside the project is relativised) and
     * the line **0-based**, the convention {@see setDiagnostics()} already
     * uses. An optional `ranges` of `['start' => …, 'length' => …]` display
     * columns bolds the match.
     *
     * @param (\Closure(): void)|null            $rerun    F5 on this tab; null leaves the key inert
     * @param (\Closure(string, int): void)|null $activate Enter on a row (path, 0-based line); null takes core's
     *                                                    open-the-file-and-jump, which is what most tools want
     */
    public function registerListTool(string $id, string $title, ?\Closure $rerun = null, ?\Closure $activate = null): void
    {
        if (1 !== preg_match(self::ID_PATTERN, $id)) {
            throw new PluginException(\sprintf('Invalid list tool id "%s" (expected /^[a-z0-9][a-z0-9_-]*$/i)', $id));
        }
        if (array_key_exists($id, $this->listToolIds)) {
            throw new PluginException(\sprintf('Duplicate list tool id "%s"', $id));
        }
        if ('' === trim($title)) {
            throw new PluginException(\sprintf('List tool "%s" needs a title — it is the strip label', $id));
        }
        $this->listToolIds[$id] = true;
        $this->listTools->add([
            'plugin' => $this->pluginName,
            'id' => $this->pluginName.':'.$id,
            'title' => $title,
            'rerun' => $rerun,
            'activate' => $activate,
        ]);
    }

    /**
     * Declare REBINDABLE ACTIONS — keys a pane binds itself, listed on the
     * Settings → Keymap page and overridable in `.porta.json` under their bare
     * ids, exactly like core's.
     *
     * Not commands: a command is globally dispatched and appears in the
     * palette, which is wrong for `-`, `0` and the arrow keys a viewer binds —
     * they must fire only while that pane holds the editor slot. The DEFAULTS
     * live in the pane's own `getDefaultKeybindings()`; what is declared here is
     * the id and the label, which is all the keymap page needs.
     *
     * @param array<string, string> $actions id => untranslated label
     */
    public function registerActions(array $actions): void
    {
        foreach ($actions as $id => $label) {
            if (1 !== preg_match(self::ID_PATTERN, $id)) {
                throw new PluginException(\sprintf('Invalid action id "%s" (expected /^[a-z0-9][a-z0-9_-]*$/i)', $id));
            }
            if (array_key_exists($id, ActionCatalog::ACTIONS)) {
                throw new PluginException(\sprintf('Action "%s" is a core action — pick an id core does not use', $id));
            }
            $this->actions->add(['plugin' => $this->pluginName, 'id' => $id, 'label' => $label]);
        }
    }

    /**
     * Provide comment/string classification for project search's token-scope
     * filter ("only in comments/strings", "except in ...").
     *
     * Separate from {@see registerHighlighter()} on purpose: this runs once per
     * *matched file* during a search, not on the typing path, and it is always
     * synchronous. $classify returns `[start, end)` byte spans, or null to
     * decline so Porta's built-in classifier serves the language.
     *
     * A backend that shells out should **not** register this — a 500-hit search
     * would mean 500 subprocesses.
     *
     * @param list<string>|\Closure(): list<string>                                       $languages `['*']` claims everything
     * @param \Closure(string, string): (list<array{start: int, end: int}>|null)          $classify  (content, language)
     */
    public function registerTokenSpans(array|\Closure $languages, \Closure $classify): void
    {
        if (\is_array($languages) && [] === $languages) {
            throw new PluginException('A token-span provider must claim at least one language (or "*")');
        }
        $this->tokenSpans->add([
            'plugin' => $this->pluginName,
            'languages' => $languages instanceof \Closure ? $languages : static fn (): array => array_values($languages),
            'classify' => $classify,
        ]);
    }

    /**
     * Declare how to install this plugin's tool binary, plus a predicate for
     * whether it is currently available. When it isn't, the plugin's Settings
     * page offers a one-click install that tries each `[manager, package]`
     * candidate in order, running the first whose manager is on the PATH — e.g.
     * a composer tool passes `[['composer', 'vendor/name']]`; a native tool like
     * phpantom passes `[['brew', 'phpantom-lsp'], ['cargo', 'phpantom_lsp']]`.
     * Supported managers: composer, brew, cargo. For a tool with no installable
     * source, don't call this — no install is offered.
     *
     * @param \Closure(): bool                   $isAvailable
     * @param list<array{0: string, 1: string}> $via ordered [manager, package] candidates
     */
    public function registerInstaller(string $tool, \Closure $isAvailable, array $via): void
    {
        if ('' === trim($tool)) {
            throw new PluginException('An installer needs a non-empty tool name');
        }
        if ([] === $via) {
            throw new PluginException('An installer needs at least one [manager, package] candidate');
        }
        foreach ($via as [$manager, $package]) {
            if (!\in_array($manager, ['composer', 'brew', 'cargo'], strict: true)) {
                throw new PluginException(\sprintf('Unknown install manager "%s" (expected composer, brew or cargo)', $manager));
            }
            if ('' === trim($package)) {
                throw new PluginException('An installer candidate needs a non-empty package name');
            }
        }
        $this->installers->add([
            'plugin' => $this->pluginName,
            'tool' => $tool,
            'available' => $isAvailable,
            'via' => $via,
        ]);
    }

    /**
     * The current value of a setting this plugin registered — the user's
     * `.porta.json`/Settings-page value coerced to the declared kind, or the
     * default. Safe during activate() (the config loads before plugins), but
     * {@see registerSetting()} must have run first.
     */
    public function setting(string $key): bool|int|string
    {
        $descriptor = $this->settingDescriptors[$key] ?? throw new PluginException(\sprintf('Unknown setting "%s" — registerSetting() it first', $key));
        $raw = $this->settings->raw($this->pluginName, $key);
        if (null === $raw) {
            return $descriptor['default'];
        }

        return SettingsRowKind::from($descriptor['kind'])->coerce($raw, $descriptor['default'], $descriptor['options'] ?? []);
    }

    /** @param \Closure(string): void $listener receives the absolute path */
    public function onFileOpened(\Closure $listener): void
    {
        $this->hooks[] = ['fileOpened', $listener];
    }

    /** @param \Closure(string): void $listener receives the absolute path */
    public function onFileSaved(\Closure $listener): void
    {
        $this->hooks[] = ['fileSaved', $listener];
    }

    /** @param \Closure(string): void $listener receives the absolute path */
    public function onBufferSwitched(\Closure $listener): void
    {
        $this->hooks[] = ['bufferSwitched', $listener];
    }

    /**
     * The theme changed; the listener receives the new theme name.
     *
     * For a highlighting backend that bakes colours at construction rather than
     * reading {@see syntaxTheme()} per token — drop the cached engine here and
     * rebuild it lazily. Runs before core drops the highlight caches, so the
     * next paint uses the rebuilt engine.
     *
     * @param \Closure(string): void $listener
     */
    public function onThemeChanged(\Closure $listener): void
    {
        $this->hooks[] = ['themeChanged', $listener];
    }

    // --- Actions (live IDE; PluginException during activate()) ---------------

    /**
     * Open (or reuse) this tool's dock tab, show $rows and focus it.
     *
     * @param list<array{path: string, line: int, text: string, ranges?: list<array{start: int, length: int}>}> $rows
     * @param ToolRun                                                                                          $run     whether more rows may follow (the tab shows 'searching…')
     */
    public function showList(string $id, array $rows = [], ToolRun $run = ToolRun::Finished): void
    {
        $runtime = $this->manager->runtime(); // activate()-phase gate
        $runtime->dockTools->open($this->listToolId($id), $rows, $run);
    }

    /**
     * Replace this tool's rows in place — no open, no focus steal, the user's
     * selection and collapse preserved. A silent no-op once the user has ×'d
     * the tab, which is why an async tool publishes by id and never holds one.
     *
     * @param list<array{path: string, line: int, text: string, ranges?: list<array{start: int, length: int}>}> $rows
     */
    public function updateList(string $id, array $rows, ToolRun $run = ToolRun::Finished): void
    {
        $runtime = $this->manager->runtime();
        $runtime->dockTools->update($this->listToolId($id), $rows, $run);
    }

    /** This plugin's namespaced tool id, or a refusal naming the missing registration. */
    private function listToolId(string $id): string
    {
        if (!array_key_exists($id, $this->listToolIds)) {
            throw new PluginException(\sprintf('Unknown list tool "%s" — registerListTool() it first', $id));
        }

        return $this->pluginName.':'.$id;
    }

    /** Show a transient status-bar message. */
    public function flash(string $message, float $seconds = 2.5): void
    {
        $runtime = $this->manager->runtime();
        $runtime->notifier->flash($message, $seconds);
    }

    /**
     * Persist a new value for a setting this plugin registered — coerced to the
     * declared kind, kept live and written to `.porta.json` under
     * `plugins.<name>.<key>`, exactly like a Settings-page edit. An action
     * method: calling it during activate() throws (seed defaults via
     * {@see registerSetting()} instead).
     */
    public function changeSetting(string $key, bool|int|string $value): void
    {
        if (!array_key_exists($key, $this->settingDescriptors)) {
            throw new PluginException(\sprintf('Unknown setting "%s" — registerSetting() it first', $key));
        }
        $runtime = $this->manager->runtime(); // activate()-phase gate
        $this->settings->change($this->pluginName, $key, $value);
        $runtime->tui->requestRender();
    }

    /** The active TAB's file path (viewer tabs included), or null when no tab is open. */
    public function activeFile(): ?string
    {
        // The active TAB's file — buffer truth, not the editor widget's (a
        // viewer tab neutralizes the editor document, but its file is still
        // the active one; the graph-viewer's export glue needs exactly that).
        $path = $this->manager->runtime()->buffers->active()?->path;

        return null !== $path && '' !== $path ? $path : null;
    }

    /** The active text document's full contents, or null when none is active. */
    public function documentText(): ?string
    {
        $runtime = $this->manager->runtime();

        return null !== $this->activeFile() ? $runtime->editor->getDocument()->getText() : null;
    }

    /**
     * The caret position in the active text file (0-based line and byte column).
     *
     * @return array{line: int, column: int}|null
     */
    public function caretPosition(): ?array
    {
        $runtime = $this->manager->runtime();
        if (null === $this->activeFile()) {
            return null;
        }
        $document = $runtime->editor->getDocument();

        return ['line' => $document->getCursorLine(), 'column' => $document->getCursorCol()];
    }

    /** Insert text at the caret (undoable, LSP-synced). No-op without a text file. */
    public function insertAtCaret(string $text): void
    {
        $runtime = $this->manager->runtime();
        if (null === $this->activeFile()) {
            return;
        }
        $runtime->editor->insertText($text);
        $runtime->tui->requestRender();
    }

    /** Open a file (absolute path) and focus the editor. */
    public function openFile(string $path): void
    {
        $this->manager->runtime()->files->openFile($path, focus: OpenFocus::Editor);
    }

    /** Move the caret (0-based line, UTF-16 column) in the active file. */
    public function jumpTo(int $line, int $column = 0): void
    {
        $runtime = $this->manager->runtime();
        $runtime->editor->jumpTo($line, $column);
        $runtime->tui->requestRender();
    }

    /**
     * Publish diagnostics for an open file — they render exactly like LSP
     * diagnostics (inline messages, gutter/stripe colors, the Problems panel)
     * and replace any previous set for that file, so an empty list clears
     * them. A path with no open buffer is a silent no-op.
     *
     * @param list<array{line: int, message: string, severity?: int, column?: int}> $diagnostics 0-based line; severity 1 = error (default), 2 = warning
     */
    public function setDiagnostics(string $path, array $diagnostics): void
    {
        $runtime = $this->manager->runtime();
        $byLine = [];
        foreach ($diagnostics as $diagnostic) {
            $column = max(0, $diagnostic['column'] ?? 0);
            $byLine[max(0, $diagnostic['line'])][] = [
                'severity' => $diagnostic['severity'] ?? 1,
                'message' => $diagnostic['message'],
                'col' => $column,
                'endCol' => $column,
            ];
        }
        $runtime->diagnostics->applyDiagnostics(Uri::fromPath($path), $byLine);
        $runtime->tui->requestRender();
    }

    // --- Loader/manager plumbing ---------------------------------------------

    public function pluginName(): string
    {
        return $this->pluginName;
    }

    /** @return list<array{plugin: string, id: string, label: string, handler: \Closure(): void, keys: list<string>}> */
    public function bufferedCommands(): array
    {
        return $this->commands;
    }

    /** @return list<array{id: string, when: (\Closure(): bool)|null}> */
    public function bufferedMenuItems(): array
    {
        return $this->menuItems;
    }

    /** @return list<array{0: string, 1: \Closure(string): void}> */
    public function bufferedHooks(): array
    {
        return $this->hooks;
    }

    /** @return list<array{key: string, label: string, kind: string, default: bool|int|string, options: list<string>|null, hint: string|null}> */
    public function bufferedSettings(): array
    {
        return array_values($this->settingDescriptors);
    }

    /** @return list<array{plugin: string, languageId: string, name: string, resolve: \Closure(): (list<string>|null)}> */
    public function bufferedLanguageServers(): array
    {
        return $this->languageServers->all();
    }

    /** @return list<array{plugin: string, tool: string, available: \Closure(): bool, via: list<array{0: string, 1: string}>}> */
    public function bufferedInstallers(): array
    {
        return $this->installers->all();
    }

    /** @return list<array{plugin: string, id: string, label: string, languages: \Closure(): list<string>, highlight: \Closure(string, string, \Closure(string): void): void, available: (\Closure(): bool)|null}> */
    public function bufferedHighlighters(): array
    {
        return $this->highlighters->all();
    }

    /** @return list<array{plugin: string, languages: \Closure(): list<string>, classify: \Closure(string, string): (list<array{start: int, end: int}>|null)}> */
    public function bufferedTokenSpans(): array
    {
        return $this->tokenSpans->all();
    }

    /** @return list<array{plugin: string, id: string, claims: \Closure(string): bool}> */
    public function bufferedViewers(): array
    {
        return $this->viewers->all();
    }

    /** @return list<array{plugin: string, id: string, pane: \Closure, load: \Closure}> */
    public function bufferedPanes(): array
    {
        return $this->panes->all();
    }

    /** @return list<array{plugin: string, id: string, label: string}> */
    public function bufferedActions(): array
    {
        return $this->actions->all();
    }

    /** @return list<array{plugin: string, id: string, title: string, rerun: (\Closure(): void)|null, activate: (\Closure(string, int): void)|null}> */
    public function bufferedListTools(): array
    {
        return $this->listTools->all();
    }
}
