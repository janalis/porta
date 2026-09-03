<?php

declare(strict_types=1);

namespace Porta\Plugin\Api;

use Porta\Config\ActionCatalog;
use Porta\Editor\ChangeMarkKind;
use Porta\Editor\OpenFocus;
use Porta\Event\ActiveBufferChanged;
use Porta\Event\BufferOpened;
use Porta\Event\BufferReloaded;
use Porta\Event\BufferSaved;
use Porta\Event\IdeReady;
use Porta\Event\IdeShuttingDown;
use Porta\Event\PluginObservable;
use Porta\Event\ThemeChanged;
use Porta\Highlight\DarkTerminalTheme;
use Porta\Lsp\Uri;
use Porta\Overlay\State\ToastSeverity;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginMenuSpec;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Settings\SettingsRowKind;
use Porta\Support\ProjectPaths;
use Porta\Workbench\Dock\ToolRun;
use Porta\Workbench\Menu\MenuContribution;
use Porta\Workbench\Menu\MenuSurface;
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
    /**
     * The plugin-API major this Porta speaks. A plugin may pin the major it
     * was written against in its manifest (`extra.porta.api`); a mismatch is
     * declined by name at load, the way an uninstalled dependency is — the
     * alternative is a TypeError deep inside a render pass with nothing
     * pointing at the cause. Bump ONLY on a breaking change to this class's
     * surface; additive growth (new verbs) is not a bump.
     */
    public const int API_VERSION = 1;

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
     * @var list<array{id: string, when: (\Closure(): bool)|null, surface: MenuSurface}> command ids and the menu surface each belongs to
     */
    private array $menuItems = [];
    /**
     * Plain array: seven writer methods, positional tuples, drained into the
     * event dispatcher at commit.
     *
     * @var list<array{0: class-string, 1: \Closure(object): void}> [event class, listener]
     */
    private array $hooks = [];

    /** Set when the hooks are drained: registering after that is a mistake, not a no-op. */
    private bool $drained = false;
    /**
     * Plain array: keyed by setting key for dedup, drains into PluginSettings.
     *
     * @var array<string, array{key: string, label: string, kind: string, default: bool|int|string, options: list<string>|null, hint: string|null, min: int|null, max: int|null, step: int|null}> by key
     */
    private array $settingDescriptors = [];
    /** @var PluginBuffer<array{plugin: string, languageId: string, name: string, resolve: \Closure(): (list<string>|null), initOptions: (\Closure(): array<string, mixed>)|null}> */
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

    private readonly PluginBuffer $views;
    /** @var PluginBuffer<array{plugin: string, id: string, label: string}> */
    private readonly PluginBuffer $actions;
    /** @var PluginBuffer<array{plugin: string, id: string, title: string, rerun: (\Closure(): void)|null, activate: (\Closure(string, int): void)|null}> */
    private readonly PluginBuffer $listTools;
    /** @var PluginBuffer<array{plugin: string, contribution: MenuContribution}> */
    private readonly PluginBuffer $menus;
    /** @var PluginBuffer<array{plugin: string, listener: \Closure(string, bool|int|string): void}> */
    private readonly PluginBuffer $settingHooks;
    /** @var PluginBuffer<array{plugin: string, segment: \Closure(): (string|\Porta\Workbench\StatusCell|null)}> */
    private readonly PluginBuffer $statusSegments;
    /** @var PluginBuffer<array{plugin: string, languageId: string, resolve: \Closure(): (list<string>|null)}> */
    private readonly PluginBuffer $debugAdapters;
    /** @var array<string, true> bare ids, so showList() can reject one this plugin never registered */
    private array $listToolIds = [];
    /** @var array<string, true> ids taken with adoptCommand() — registered BARE, so their menu specs are too */
    private array $adopted = [];

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
        $this->views = new PluginBuffer();
        $this->actions = new PluginBuffer();
        $this->listTools = new PluginBuffer();
        $this->menus = new PluginBuffer();
        $this->settingHooks = new PluginBuffer();
        $this->statusSegments = new PluginBuffer();
        $this->debugAdapters = new PluginBuffer();
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
     *
     * @throws PluginException
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
     * Implement a CORE command whose id core has vacated — the way a feature
     * that used to live in `src/` keeps its identity after moving into a
     * bundled plugin.
     *
     * The id stays BARE (`ide_todo`, not `todo:todo`), so an existing
     * `.porta.json` keymap entry still binds it, the Settings → Keymap page
     * still lists it under core's label, and its position in a core menu is
     * unchanged. The label and the default keys come from
     * {@see ActionCatalog} — the identity is core's, only the implementation
     * moved.
     *
     * Only ids core marks `adoptable` may be taken, which is what stops a
     * third-party plugin from quietly becoming `save`.
     *
     * @param \Closure(): void $handler
     *
     * @throws PluginException
     */
    public function adoptCommand(string $id, \Closure $handler): void
    {
        if (!ActionCatalog::isAdoptable($id)) {
            throw new PluginException(\sprintf('Command "%s" is not adoptable — core still implements it (or there is no such action)', $id));
        }
        if (array_key_exists($id, $this->ids)) {
            throw new PluginException(\sprintf('Duplicate command id "%s"', $id));
        }
        $this->ids[$id] = true;
        $this->adopted[$id] = true;
        $this->commands[] = [
            'plugin' => $this->pluginName,
            'id' => $id,
            'label' => ActionCatalog::label($id),
            'handler' => $handler,
            // None: an adoptable id is an IDE-scope catalog action, so its
            // default key is already in the map ActionCatalog::ideDefaults()
            // builds. Declaring it again here would just shadow itself.
            'keys' => [],
        ];
    }

    /**
     * Show a registered command in the Plugins menu (its label is reused).
     * With $enabledWhen, the item renders disabled while the closure returns
     * false (e.g. the tool's binary is missing) — it is re-evaluated on every
     * menu render, and a throw reads as disabled.
     *
     * $surface picks WHERE it lands: the trailing Plugins menu by default, or
     * the editor's right-click menu ({@see MenuSurface}).
     *
     * @param (\Closure(): bool)|null $enabledWhen
     *
     * @throws PluginException
     */
    public function addMenuItem(string $commandId, ?\Closure $enabledWhen = null, MenuSurface $surface = MenuSurface::Plugins): void
    {
        if (!array_key_exists($commandId, $this->ids)) {
            throw new PluginException(\sprintf('Unknown command id "%s" for a menu item — register it first', $commandId));
        }
        // An ADOPTED id is registered bare (it is core's), so its menu spec must
        // be bare too — prefixing it would name a command nothing answers to.
        $this->menuItems[] = [
            'id' => array_key_exists($commandId, $this->adopted) ? $commandId : $this->pluginName.':'.$commandId,
            'when' => $enabledWhen,
            'surface' => $surface,
        ];
    }

    /**
     * Declare a user-editable setting, shown on this plugin's Settings page
     * and persisted in `.porta.json` under `plugins.<name>.<key>`. Call it
     * before {@see setting()} — the descriptor supplies the default and the
     * type coercion. Kinds: 'text', 'bool', 'int', 'choice' (with $options).
     *
     * @param list<string>|null $options required for 'choice'; must contain the default
     * @param ?int              $min     'int' only: the floor the stepper clamps to (default 1)
     * @param ?int              $max     'int' only: the ceiling it clamps to (default unbounded)
     * @param ?int              $step    'int' only: how far one keypress moves it (default 1)
     *
     * @throws PluginException
     */
    public function registerSetting(
        string $key,
        string $label,
        string $kind = 'text',
        bool|int|string $default = '',
        ?array $options = null,
        ?string $hint = null,
        ?int $min = null,
        ?int $max = null,
        ?int $step = null,
    ): void {
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
        // A bound on anything but a number is a mistake worth naming: nothing
        // would clamp it, and the row would advertise a range it does not keep.
        if ('int' !== $kind && (null !== $min || null !== $max || null !== $step)) {
            throw new PluginException(\sprintf('Setting "%s": min/max/step apply to an int, not a %s', $key, $kind));
        }
        if (null !== $min && null !== $max && $min > $max) {
            throw new PluginException(\sprintf('Setting "%s": min %d is above max %d', $key, $min, $max));
        }
        $this->settingDescriptors[$key] = [
            'key' => $key,
            'label' => $label,
            'kind' => $kind,
            'default' => $default,
            'options' => $options,
            'hint' => $hint,
            'min' => $min,
            'max' => $max,
            'step' => $step,
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
     * $initializationOptions, when given, returns the LSP `initializationOptions`
     * sent in the server's initialize handshake. Like the command resolver it is
     * re-run on every (re)configure — read this plugin's settings in it and a
     * settings edit reaches the relaunched server. An empty array sends nothing.
     *
     * @param \Closure(): (list<string>|null)            $resolveCommand
     * @param (\Closure(): array<string, mixed>)|null $initializationOptions
     *
     * @throws PluginException
     */
    public function registerLanguageServer(string $languageId, string $name, \Closure $resolveCommand, ?\Closure $initializationOptions = null): void
    {
        if (1 !== preg_match(self::ID_PATTERN, $languageId)) {
            throw new PluginException(\sprintf('Invalid language id "%s" (expected /^[a-z0-9][a-z0-9_-]*$/i)', $languageId));
        }
        $this->languageServers->add([
            'plugin' => $this->pluginName,
            'languageId' => $languageId,
            'name' => $name,
            'resolve' => $resolveCommand,
            'initOptions' => $initializationOptions,
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
     *
     * @throws PluginException
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
     *
     * @throws PluginException
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
            $claims = static fn (string $path): bool => ProjectPaths::hasExtension($path, $extensions);
        }
        $this->viewers->add([
            'plugin' => $this->pluginName,
            'id' => $id,
            'claims' => $claims,
        ]);
    }

    /**
     * Register a full-screen view: a workspace takeover with its own sidebar,
     * drag separator and content pane, reached by {@see openView()}.
     *
     * $factory is called ONCE, after plugins have committed, with the
     * {@see \Porta\Workbench\View\ViewServices} core assembles — a plugin
     * class can never be a container service, so this is how a view receives
     * its collaborators. Its region is minted for $id before the call, so the
     * view can fill both panes immediately.
     *
     * Untyped here on purpose, the same reason `registerPane`'s is: naming the
     * return type would put the plugin tier back in front of a widget.
     *
     * @param \Closure $factory \Closure(\Porta\Workbench\View\ViewServices): \Porta\Workbench\View\ContributedView
     *
     * @throws PluginException
     */
    public function registerView(string $id, \Closure $factory): void
    {
        if (1 !== preg_match(self::ID_PATTERN, $id)) {
            throw new PluginException(\sprintf('Invalid view id "%s" (expected /^[a-z0-9][a-z0-9_-]*$/i)', $id));
        }
        $this->views->add([
            'plugin' => $this->pluginName,
            'id' => $id,
            'factory' => $factory,
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
     *
     * @throws PluginException
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
     *
     * @throws PluginException
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
     * Provide the DEBUG ADAPTER for a language — the DAP counterpart of
     * {@see registerLanguageServer()}, and it works the same way: the resolver
     * is called when a session starts, so it can read this plugin's `binary`
     * setting and return null while the adapter is not installed yet.
     *
     * Consulted BELOW `.porta.json`'s `languages.<id>.debug`: a user who named
     * an adapter by hand keeps it. Returning the argv is the whole contract —
     * launch configuration stays the user's, because it is theirs to tune.
     *
     * @param \Closure(): (list<string>|null) $resolveCommand
     *
     * @throws PluginException
     */
    public function registerDebugAdapter(string $languageId, \Closure $resolveCommand): void
    {
        if (1 !== preg_match(self::ID_PATTERN, $languageId)) {
            throw new PluginException(\sprintf('Invalid language id "%s" (expected /^[a-z0-9][a-z0-9_-]*$/i)', $languageId));
        }
        $this->debugAdapters->add(['plugin' => $this->pluginName, 'languageId' => $languageId, 'resolve' => $resolveCommand]);
    }

    /**
     * Contribute a STATUS-BAR SEGMENT: ambient state, shown at the left of the
     * bar beside the debugger's and before the git branch.
     *
     * Pulled once per RENDER, so the closure must be cheap and must never
     * block — cache whatever it reports and return the cached value. Return
     * null (or '') to show nothing, which is the normal state most of the time.
     * A throwing closure reads as nothing and is reported once.
     *
     * Returning a bare STRING shares one subtle cell with every other plugin
     * doing the same. Returning a {@see \Porta\Workbench\StatusCell} claims a
     * cell of its own, which is what a segment needs when it carries a tone or
     * a right-click menu — the git branch indicator is the case that forced it.
     * The tone is chosen from core's three; a plugin cannot name a colour.
     *
     * @param \Closure(): (string|\Porta\Workbench\StatusCell|null) $segment
     */
    public function registerStatusSegment(\Closure $segment): void
    {
        $this->statusSegments->add(['plugin' => $this->pluginName, 'segment' => $segment]);
    }

    /**
     * Contribute a TOP-LEVEL MENU — a group of its own in the menu bar, rather
     * than an entry in the trailing Plugins one that {@see addMenuItem()} adds
     * to.
     *
     * $items are command ids spelled `'i:<id>'`, with
     * {@see \Porta\Workbench\Menu\MenuModel::SEP} between groups; an id
     * nothing registered is skipped, which is what lets a menu list a verb that
     * only exists under some condition. $after names the CORE menu title this
     * group follows, so a contributed group lands where it belongs rather than
     * at the end.
     *
     * $viewSafe is the other half and travels with it: while the view named by
     * $viewId owns the main area, only the ids listed here stay enabled. A menu
     * contributed without one greys out its own items the moment its view
     * opens — a bug with no error, which is why the two are one call.
     *
     * @param list<string>            $items    `'i:<command-id>'` entries and separators
     * @param (\Closure(): bool)|null $visible  hides the whole group; null means always shown
     * @param string                  $viewId   the view whose open state $viewSafe applies to, '' for none
     * @param list<string>            $viewSafe command ids that stay enabled while that view is open
     *
     * @throws PluginException
     */
    public function registerMenu(string $title, string $after, array $items, ?\Closure $visible = null, string $viewId = '', array $viewSafe = []): void
    {
        if ('' === $title) {
            throw new PluginException('A contributed menu needs a title');
        }
        $this->menus->add([
            'plugin' => $this->pluginName,
            'contribution' => new MenuContribution($title, $after, $items, $visible, $viewId, $viewSafe),
        ]);
    }

    /**
     * Tell the status bar this plugin's segment has changed.
     *
     * Segments are PULLED once per render, but the bar caches its assembled
     * line, so a segment that changes between frames — a branch name resolving
     * out of a subprocess — needs to say so or the old text stays until
     * something else invalidates. Cheap and idempotent; call it from whatever
     * callback produced the new value.
     */
    public function refreshStatusSegments(): void
    {
        $runtime = $this->manager->runtime();
        $runtime->statusBar->invalidate();
        $runtime->tui->requestRender();
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
     *
     * @throws PluginException
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
     *
     * @throws PluginException
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
     *
     * @throws PluginException
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
     *
     * @throws PluginException
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

    /**
     * Run $listener once the IDE is up — layout applied, services wired, no
     * buffer opened yet, so setup lands before the first `onFileOpened` rather
     * than racing it. It receives the project root, matching the other hooks'
     * signature; a closure taking no argument is fine.
     *
     * @param \Closure(string): void $listener
     *
     * @throws PluginException
     */
    public function onReady(\Closure $listener): void
    {
        $this->listen(IdeReady::class, static fn (IdeReady $event) => $listener($event->projectRoot));
    }

    /**
     * Run $listener as the IDE shuts down, before core tears its services down.
     *
     * The event loop has ALREADY stopped by then, so the listener must finish
     * synchronously — a timer or subprocess it starts will never run.
     * Persisting something is what this is for.
     *
     * @param \Closure(string): void $listener
     *
     * @throws PluginException
     */
    public function onShutdown(\Closure $listener): void
    {
        $this->listen(IdeShuttingDown::class, static fn (IdeShuttingDown $event) => $listener($event->projectRoot));
    }

    /**
     * @param \Closure(string): void $listener receives the absolute path
     *
     * @throws PluginException
     */
    public function onFileOpened(\Closure $listener): void
    {
        $this->listen(BufferOpened::class, static fn (BufferOpened $event) => $listener($event->buffer->path));
    }

    /**
     * @param \Closure(string): void $listener receives the absolute path
     *
     * @throws PluginException
     */
    public function onFileSaved(\Closure $listener): void
    {
        $this->listen(BufferSaved::class, static fn (BufferSaved $event) => $listener($event->buffer->path));
    }

    /**
     * The poller replaced a clean buffer with what the file now holds on disk.
     *
     * Sugar over the internal {@see BufferReloaded}, which carries a mutable
     * `Buffer` and so cannot be observable — the adapter hands the path only,
     * exactly as {@see onFileOpened} and {@see onFileSaved} do. A producer of
     * file-scoped state (change marks, coverage) wants this: the content moved
     * without a save.
     *
     * @param \Closure(string): void $listener receives the absolute path
     *
     * @throws PluginException
     */
    public function onFileReloaded(\Closure $listener): void
    {
        $this->listen(BufferReloaded::class, static fn (BufferReloaded $event) => $listener($event->buffer->path));
    }

    /**
     * The active file changed — not every SHOW of it.
     *
     * Listens to the deduped {@see ActiveBufferChanged}, so re-showing the
     * buffer that is already active (config re-apply, pane swaps, focus churn)
     * stays silent while core listeners still see every show.
     *
     * @param \Closure(string): void $listener receives the absolute path
     *
     * @throws PluginException
     */
    public function onBufferSwitched(\Closure $listener): void
    {
        $this->listen(ActiveBufferChanged::class, static fn (ActiveBufferChanged $event) => $listener($event->path));
    }

    /**
     * One of THIS plugin's settings changed — the Settings page wrote it, and
     * whatever the plugin cached from it is now stale.
     *
     * Only its own: a plugin never sees another's configuration. Runs after the
     * new value is persisted, so `setting()` inside the listener reads the new
     * one; the value is handed over anyway, because the common shape is a
     * `match` on the key.
     *
     * Without this, a setting a plugin reads once at `activate()` is
     * restart-required by construction — which is right for a few (a binary
     * path baked into a process runner) and wrong for the rest.
     *
     * @param \Closure(string, bool|int|string): void $listener receives the key and its new value
     */
    public function onSettingChanged(\Closure $listener): void
    {
        $this->settingHooks->add(['plugin' => $this->pluginName, 'listener' => $listener]);
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
     *
     * @throws PluginException
     */
    public function onThemeChanged(\Closure $listener): void
    {
        $this->listen(ThemeChanged::class, static fn (ThemeChanged $event) => $listener($event->theme->value));
    }

    /**
     * Listen to a core event directly.
     *
     * The six named hooks above are sugar over this one; `on()` reaches the
     * rest of the observable set, and hands the listener the event OBJECT
     * rather than a string. The class must implement
     * {@see \Porta\Event\PluginObservable} — the frozen, documented subset of
     * `Porta\Event`; everything else there is internal and changes without
     * notice, which is why naming one is refused rather than merely useless.
     *
     * Runs at {@see \Porta\Plugin\Runtime\PluginManager::PLUGIN_PRIORITY},
     * after every priority-0 core listener. A throw is caught and flashed like
     * any other plugin closure. There is no `off()`: disabling the plugin
     * removes its listeners.
     *
     * @param class-string                  $eventClass a {@see PluginObservable} implementation
     * @param \Closure(PluginObservable): void $listener
     *
     * @throws PluginException
     */
    public function on(string $eventClass, \Closure $listener): void
    {
        if (!is_a($eventClass, PluginObservable::class, allow_string: true)) {
            throw new PluginException(\sprintf('"%s" is not a plugin-observable event — see the event list in docs/plugins.md', $eventClass));
        }
        $this->listen($eventClass, $listener);
    }

    /**
     * The unchecked door the named hooks use: core code naming core event
     * classes, which is how `onFileOpened`/`onFileSaved` keep working while
     * {@see BufferOpened}/{@see BufferSaved} stay internal — they carry a
     * mutable `Buffer`, so they are not something a plugin may hold.
     *
     * Refuses once the buffer has been drained. Registrations are collected
     * during `activate()` and committed atomically; one arriving after that
     * used to append to an array nobody would read again, so a listener
     * subscribed from a command handler simply never fired. A silent no-op is
     * the wrong answer to a plugin author's mistake, and `on()` widens the door
     * it comes through.
     *
     * @param class-string           $eventClass
     * @param \Closure(never): void  $listener
     */
    private function listen(string $eventClass, \Closure $listener): void
    {
        if ($this->drained) {
            throw new PluginException(\sprintf('Listeners must be registered during activate(); the one for "%s" arrived after this plugin was committed', $eventClass));
        }
        /** @var \Closure(object): void $listener */
        $this->hooks[] = [$eventClass, $listener];
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

    /**
     * Ask the user to pick one of $items — the centered list overlay the IDE's
     * own pickers use. `$onPick` receives the chosen item's value; dismissing
     * the overlay calls nothing.
     *
     * The one thing a plugin could not do before this: `flash()` tells, and
     * nothing asked.
     *
     * A value => label map is the short spelling. Pass full rows —
     * `['label' => …, 'hint' => …, 'value' => …]` — when the list needs a
     * dimmed second column: a stash's date, a branch's tracking state. Both
     * spellings reach the same overlay.
     *
     * @param array<string, string>|list<array{label: string, hint: string, value: string}> $items in the order to show them
     * @param \Closure(string): void                                                        $onPick
     */
    public function pick(string $title, array $items, \Closure $onPick): void
    {
        $runtime = $this->manager->runtime(); // activate()-phase gate
        $rows = [];
        foreach ($items as $value => $label) {
            $rows[] = \is_array($label) ? $label : ['label' => $label, 'hint' => '', 'value' => (string) $value];
        }
        $runtime->modals->openListOverlay($title, $rows, $onPick);
    }

    /**
     * Ask the user for one line of text, pre-filled with $initial. `$onSubmit`
     * receives what they typed; cancelling calls nothing.
     *
     * @param \Closure(string): void $onSubmit
     */
    public function prompt(string $label, string $initial, \Closure $onSubmit): void
    {
        $runtime = $this->manager->runtime();
        $runtime->modals->openPrompt($label, $initial, $onSubmit);
    }

    /** Show a transient status-bar message. */
    public function flash(string $message, float $seconds = 2.5): void
    {
        $runtime = $this->manager->runtime();
        $runtime->notifier->flash($message, $seconds);
    }

    /**
     * Say it with a SEVERITY — the same toast {@see flash()} shows, coloured
     * for what happened: `Success` for a completed action, `Error` for one that
     * failed, `Warn` for one that half-worked.
     *
     * The severity is chosen from core's four and core owns what each looks
     * like, the same split {@see registerStatusSegment()} draws between asking
     * for prominence and naming a colour. `flash()` is this at `Info`.
     */
    public function notify(string $message, ToastSeverity $severity = ToastSeverity::Info, float $seconds = 2.5): void
    {
        $this->manager->runtime()->notifier->notify($message, $severity, $seconds);
    }

    /**
     * Persist a new value for a setting this plugin registered — coerced to the
     * declared kind, kept live and written to `.porta.json` under
     * `plugins.<name>.<key>`, exactly like a Settings-page edit: this plugin's
     * own {@see onSettingChanged()} hooks fire, and when it provides a
     * language server the LSP re-resolves and relaunches live. An action
     * method: calling it during activate() throws (seed defaults via
     * {@see registerSetting()} instead).
     *
     * @throws PluginException
     */
    public function changeSetting(string $key, bool|int|string $value): void
    {
        if (!array_key_exists($key, $this->settingDescriptors)) {
            throw new PluginException(\sprintf('Unknown setting "%s" — registerSetting() it first', $key));
        }
        $runtime = $this->manager->runtime(); // activate()-phase gate
        $this->settings->change($this->pluginName, $key, $value);
        // The hook gets the COERCED value — what setting() reads back now.
        $runtime->lifecycle->onPluginSettingChanged($this->pluginName, $key, $this->settings->value($this->pluginName, $key));
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

    /**
     * The file this invocation is ABOUT: the right-clicked path when the
     * command came from a file context menu (a tree row, a tab), else
     * {@see activeFile()}.
     *
     * The fallback is what makes this the right default for a file verb: the
     * same command answers a right-click on `Other.php`, a keystroke, and a
     * palette entry, without the plugin knowing which one it was.
     */
    public function contextFile(): ?string
    {
        return $this->manager->runtime()->menuFile->file() ?? $this->activeFile();
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

    /**
     * Replace the active text buffer's WHOLE content — the verb behind "apply
     * what my tool produced": a formatter's output, a restored snapshot.
     *
     * - **One** undo step: Ctrl+Z puts back what was there, caret and all.
     * - The caret stays where it is, clamped into the new text — a restore does
     *   not dump the user at the end of the document.
     * - A full LSP didChange goes out, as if the text had been typed.
     * - A silent no-op when nothing is open, when the active tab is a VIEWER
     *   pane, or when the text is IDENTICAL: a tool that changed nothing must
     *   not look like it did.
     *
     * It never touches the file on disk. Save stays the user's verb — which is
     * also why an async tool must check `activeFile()` before applying: the
     * buffer the user is looking at may not be the one your subprocess read.
     */
    public function replaceDocument(string $text): void
    {
        $runtime = $this->manager->runtime(); // activate()-phase gate
        if ($runtime->documents->replaceActive($text)) {
            $runtime->tui->requestRender();
        }
    }

    /**
     * Translate a message through the IDE's catalogs — the same lookup core's
     * own strings take, so a bundled feature that moves into a plugin keeps its
     * translations instead of becoming English in every other locale.
     *
     * The message id IS the English source string. A string the catalogs do not
     * know passes through unchanged, which is what a third-party plugin's own
     * copy will do.
     *
     * @param array<string, string|int> $params placeholders, e.g. `['%name%' => $file]`
     */
    public function trans(string $id, array $params = []): string
    {
        return $this->manager->runtime()->translator->trans($id, $params);
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

    /**
     * Put an event on Porta's bus.
     *
     * The symmetric half of {@see on()}: what a plugin may observe, it may
     * also announce. The parameter is an INSTANCE rather than a class-string,
     * so the {@see PluginObservable} marker is enforced by the type system and
     * needs no runtime check — and the plugin never holds the dispatcher,
     * which is what stops it reaching the internal events.
     *
     * Core listeners run first, at priority 0; other plugins hear it after.
     * An action method, so it throws during `activate()` — nothing has been
     * wired yet, and an event dispatched into a half-built IDE is a bug
     * wearing a notification.
     */
    public function emit(PluginObservable $event): void
    {
        $this->manager->runtime(); // the same not-during-activate() gate the other actions carry
        $this->manager->dispatch($event);
    }

    /**
     * Open a registered full-screen view, or close it. Every other view yields
     * first — one owns the workspace at a time.
     *
     * A view's own commands reach it through here rather than by holding the
     * controller: the registry hands back a `ContributedView` without a view's
     * own verbs on it, deliberately, so that a capability only one view has
     * stays that view's business.
     */
    public function openView(string $id): void
    {
        $this->manager->runtime()->views->open($id);
    }

    public function closeView(string $id): void
    {
        $this->manager->runtime()->views->close($id);
    }

    /**
     * Flip a registered view open or closed — what a keybinding on a view
     * wants, and the reason it is here rather than composed from the two above.
     *
     * "Is it open?" is a question only the registry can answer, and a plugin
     * asking it any other way has to hold its own controller for the duration
     * — which means keeping a reference alive purely to read one bool, against
     * an object built later than everything that needs it.
     */
    public function toggleView(string $id): void
    {
        $this->manager->runtime()->views->toggle($id);
    }

    /**
     * Put text on the system clipboard (OSC 52 where the terminal allows it).
     *
     * The one verb a full-screen view needed that had no plugin route at all:
     * `Support\Clipboard` is a single-method service, and a view that copies a
     * commit hash cannot inject it.
     */
    public function copyToClipboard(string $text): void
    {
        $this->manager->runtime()->clipboard->copy($text);
    }

    /**
     * Publish version-control change marks for a file — the gutter's
     * added/modified/deleted stripe. Keys are 0-based lines, values one of
     * `added`, `modified`, `deleted`; an empty array clears the file.
     *
     * Path-keyed, unlike {@see setLineAnnotations}: the answer arrives from a
     * subprocess and the user may have switched tabs, so name the file you
     * computed and core decides whether it is on screen. A path with no open
     * buffer is a silent no-op.
     *
     * The three kinds are core's vocabulary. A plugin says which lines changed
     * and how; core owns what that looks like, which is why a plugin cannot
     * invent a colour here.
     *
     * @param array<int, string> $marks 0-based line => 'added'|'modified'|'deleted'
     */
    public function setChangeMarks(string $path, array $marks): void
    {
        $runtime = $this->manager->runtime();
        $known = [];
        foreach ($marks as $line => $kind) {
            // Normalised at the boundary, like setDiagnostics' severity: an
            // unknown kind has no Role behind it, so dropping it here is what
            // stops it reaching the renderer as a colour nobody chose.
            $parsed = ChangeMarkKind::tryFrom($kind);
            if (null === $parsed) {
                continue;
            }

            $known[max(0, $line)] = $parsed;
        }
        $runtime->changeMarks->applyChangeMarks($path, $known);
    }

    /**
     * Publish the working tree's per-FILE version-control status — what the file
     * tree marks its rows with. Keys are absolute paths, values one of `added`,
     * `modified`, `deleted`.
     *
     * The map REPLACES whole, unlike {@see setChangeMarks}' per-file writes: a
     * file dropping out of it is precisely the news that it went back to
     * unmodified, and there is no other way to say that.
     *
     * Same boundary rule as change marks — the three kinds are core's
     * vocabulary, so a plugin says which files changed and how, and core owns
     * what that looks like. An unknown kind is dropped rather than rendered.
     *
     * @param array<string, string> $statuses absolute path => 'added'|'modified'|'deleted'
     */
    public function setFileStatuses(array $statuses): void
    {
        $runtime = $this->manager->runtime();
        $known = [];
        foreach ($statuses as $path => $kind) {
            $parsed = ChangeMarkKind::tryFrom($kind);
            if ('' === $path || null === $parsed) {
                continue;
            }
            $known[$path] = $parsed;
        }
        $runtime->fileStatuses->applyFileStatuses($known);
    }

    /**
     * Show or hide the version-control gutter STRIP — the one-column stripe
     * {@see setChangeMarks()} paints into.
     *
     * Separate from publishing marks, because they answer different questions:
     * "which lines changed?" is the plugin's, "is there a column for them?" is
     * the user's. Publishing nothing while the strip is on leaves an empty
     * column indented into every file, which is what the setting behind this
     * exists to remove.
     *
     * Hiding drops whatever marks were showing, so nothing lingers under a
     * strip that is no longer painted.
     */
    public function showChangeMarkGutter(bool $on): void
    {
        $runtime = $this->manager->runtime();
        $runtime->editorView->setVcsGutter($on);
        $runtime->tui->requestRender();
    }

    /**
     * Show a dim trailing note after each of the ACTIVE document's lines — the
     * slot `git blame` uses. The map replaces whatever was showing, so an empty
     * array clears it.
     *
     * Unlike {@see setDiagnostics} this takes no path: annotations belong to
     * whatever the editor is showing, and the widget drops them on every
     * document show. A plugin that wants them to survive a buffer switch
     * re-publishes from {@see onBufferSwitched}.
     *
     * @param array<int, string> $byLine 0-based line => the text shown after it
     */
    public function setLineAnnotations(array $byLine): void
    {
        $runtime = $this->manager->runtime();
        $runtime->annotations->setLineAnnotations($byLine);
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

    /** @return list<array{id: string, when: (\Closure(): bool)|null, surface: MenuSurface}> */
    public function bufferedMenuItems(): array
    {
        return $this->menuItems;
    }

    /** @return list<array{0: class-string, 1: \Closure(object): void}> */
    public function bufferedHooks(): array
    {
        $this->drained = true;

        return $this->hooks;
    }

    /** @return list<array{key: string, label: string, kind: string, default: bool|int|string, options: list<string>|null, hint: string|null, min: int|null, max: int|null, step: int|null}> */
    public function bufferedSettings(): array
    {
        return array_values($this->settingDescriptors);
    }

    /** @return list<array{plugin: string, languageId: string, name: string, resolve: \Closure(): (list<string>|null), initOptions: (\Closure(): array<string, mixed>)|null}> */
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

    /**
     * @return list<array{plugin: string, id: string, factory: \Closure}>
     *
     * @phpstan-ignore-next-line the buffer is untyped by design; the shape is registerView's
     */
    public function bufferedViews(): array
    {
        /** @var list<array{plugin: string, id: string, factory: \Closure}> */
        return $this->views->all();
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

    /** @return list<array{plugin: string, contribution: MenuContribution}> */
    public function bufferedMenus(): array
    {
        return $this->menus->all();
    }

    /** @return list<array{plugin: string, listener: \Closure(string, bool|int|string): void}> */
    public function bufferedSettingHooks(): array
    {
        return $this->settingHooks->all();
    }

    /** @return list<array{plugin: string, segment: \Closure(): (string|\Porta\Workbench\StatusCell|null)}> */
    public function bufferedStatusSegments(): array
    {
        return $this->statusSegments->all();
    }

    /** @return list<array{plugin: string, languageId: string, resolve: \Closure(): (list<string>|null)}> */
    public function bufferedDebugAdapters(): array
    {
        return $this->debugAdapters->all();
    }
}
